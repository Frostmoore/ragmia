<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Project;
use App\Models\Message;
use Illuminate\Http\Request;
use App\Services\LLM\LlmClient;
use Illuminate\Support\Facades\DB;

use App\Services\Chat\HistoryService;
use App\Services\Chat\MemoryService;
use App\Services\Chat\MemoryMerger;
use App\Services\Chat\PlannerService;
use App\Services\Chat\PromptBuilder;
use App\Services\Chat\PostTurnUpdater;
use App\Services\Chat\PreTurnProfileUpdater;
use App\Services\Chat\ContextHintService;
use App\DTOs\Plan;

use App\Services\Chat\Send\SendRequest;
use App\Services\Chat\Send\SendCoordinator;

class ChatController extends Controller
{
    public function __construct(
        private LlmClient $llm,
        private HistoryService $history,
        private MemoryService $mem,
        private MemoryMerger $merger,
        private PlannerService $planner,
        private PromptBuilder $builder,
        private PostTurnUpdater $updater,
        private PreTurnProfileUpdater $preProfile,   // 👈 NEW
        private SendCoordinator $sender,
        private ContextHintService $hints,
    ) {}

    // ===== Views/API di navigazione =====

    public function index()
    {
        $userId = (int) auth()->id();

        $folders = Folder::query()
            ->forUser($userId)
            ->whereNull('parent_id')
            ->with([
                'projects' => fn($q) => $q->forUser($userId)->orderBy('name'),
                'children' => fn($q) => $q->forUser($userId)->orderBy('name'),
                'children.projects' => fn($q) => $q->forUser($userId)->orderBy('name'),
                'children.children' => fn($q) => $q->forUser($userId)->orderBy('name'),
                'children.children.projects' => fn($q) => $q->forUser($userId)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        $projectsNoFolder = Project::query()
            ->forUser($userId)
            ->whereNull('folder_id')
            ->orderBy('name')
            ->get();

        return view('chat.index', compact('folders', 'projectsNoFolder'));
    }

    public function listProjects()
    {
        $userId = (int) auth()->id();
        return response()->json($this->buildTree($userId));
    }

    public function listMessages(Request $req)
    {
        $userId    = (int) auth()->id();
        $projectId = (int) $req->query('project_id');

        // opzionale: verifica che il progetto sia dell'utente
        $project = Project::forUser($userId)->find($projectId);
        if (!$project) {
            return response()->json(['messages' => []]);
        }

        $messages = Message::forUser($userId)
            ->where('project_id', $projectId)
            ->orderBy('created_at','asc')
            ->get(['role','content','created_at']);

        return response()->json(['messages' => $messages]);
    }

    public function createProject(Request $req)
    {
        $path = trim($req->input('path',''));
        if ($path === '') {
            return response()->json(['error' => 'Path vuoto'], 422);
        }

        $userId  = (int) auth()->id();
        $project = $this->resolveOrCreateProjectByPath($path, $userId);
        return response()->json(['project' => $project], 201);
    }

    public function createFolder(Request $req)
    {
        $path = trim($req->input('path',''));
        if ($path === '') return response()->json(['error' => 'Path cartella vuoto'], 422);

        $userId = (int) auth()->id();
        $folder = $this->resolveOrCreateFolderPath($path, $userId);
        $tree   = $this->buildTree($userId);

        return response()->json(['folder' => $folder, 'tree' => $tree], 201);
    }

    public function stats()
    {
        $start  = now()->startOfMonth();
        $end    = now()->endOfMonth();
        $userId = (int) auth()->id();

        $q = \DB::table('messages')
            ->whereBetween('created_at', [$start, $end])
            ->where('role', 'assistant')
            ->where('user_id', $userId);

        $tokens = (int) $q->sum('tokens_total');
        $cost   = (float) $q->sum('cost_usd');

        return response()->json([
            'tokens' => $tokens,
            'cost'   => round($cost, 4),
        ]);
    }

    public function deleteProject(int $projectId)
    {
        $userId = (int) auth()->id();
        $project = Project::forUser($userId)->find($projectId);
        if (!$project) {
            return response()->json(['error' => 'Progetto non trovato'], 404);
        }

        $deleted = ['projects' => 0, 'messages' => 0];

        DB::transaction(function () use ($project, &$deleted, $userId) {
            $msgIds = Message::forUser($userId)->where('project_id', $project->id)->pluck('id');
            $deleted['messages'] = $msgIds->count();
            if ($deleted['messages'] > 0) {
                Message::whereIn('id', $msgIds)->delete();
            }

            $deleted['projects'] = 1;
            $project->delete();
        });

        $tree = $this->buildTree($userId);

        return response()->json([
            'ok'      => true,
            'deleted' => $deleted,
            'tree'    => $tree,
        ]);
    }

    public function deleteFolder(int $folderId)
    {
        $userId = (int) auth()->id();
        $root = Folder::forUser($userId)->find($folderId);
        if (!$root) {
            return response()->json(['error' => 'Cartella non trovata'], 404);
        }

        $deleted = ['folders' => 0, 'projects' => 0, 'messages' => 0];

        DB::transaction(function () use ($root, &$deleted, $userId) {
            $folderIds = $this->collectFolderIds($root->id, $userId);
            $deleted['folders'] = count($folderIds);

            $projectIds = Project::forUser($userId)->whereIn('folder_id', $folderIds)->pluck('id')->all();
            $deleted['projects'] = count($projectIds);

            if ($projectIds) {
                $msgIds = Message::forUser($userId)->whereIn('project_id', $projectIds)->pluck('id');
                $deleted['messages'] = $msgIds->count();
                if ($deleted['messages'] > 0) {
                    Message::whereIn('id', $msgIds)->delete();
                }
                Project::whereIn('id', $projectIds)->delete();
            }

            Folder::whereIn('id', $folderIds)->delete();
        });

        $tree = $this->buildTree($userId);

        return response()->json([
            'ok'      => true,
            'deleted' => $deleted,
            'tree'    => $tree,
        ]);
    }

    /**
     * Raccoglie tutti gli ID cartella del sottoalbero (BFS) includendo la root.
     */
    private function collectFolderIds(int $rootId, int $userId): array
    {
        $ids   = [];
        $queue = [$rootId];

        while (!empty($queue)) {
            $id = array_shift($queue);
            $ids[] = $id;

            $children = Folder::forUser($userId)->where('parent_id', $id)->pluck('id')->all();
            foreach ($children as $cid) {
                $queue[] = $cid;
            }
        }
        return $ids;
    }

    // ===== Core: invio messaggi =====

    public function send(Request $request)
    {
        $path   = trim($request->input('project_path',''));
        $prompt = (string)$request->input('prompt','');
        $auto   = $request->boolean('auto', true);
        if ($path === '') $path = 'Default';

        $userId = (int) (auth()->id() ?? 0);
        $project = $this->resolveOrCreateProjectByPath($path, $userId);
        $projectId = (int) data_get($project, 'id');
        if (!$projectId) {
            \Log::error('Impossibile risolvere/creare Project', ['path'=>$path,'project'=>$project]);
            return $this->respond($request, ['error'=>'Project non disponibile'], 500);
        }

        $model         = (string)$request->input('model', env('OPENAI_MODEL','openai:gpt-5'));
        // $compressModel = (string)$request->input('compress_model', 'openai:gpt-4o-mini');
        $compressModel = (string)$request->input('compress_model', 'openai:gpt-4.1-nano'); // prima era gpt-4o-mini
        // $maxCompletion = (int)($request->input('max_tokens') ?? 2000);
        // $maxCompletion = (int)($request->input('max_tokens') ?? env('LLM_MAX_COMPLETION', 4000));
        $maxCompletion = $this->resolveMaxTokens($request);

        // 👇 supporto a più alias per comodità (raw_user / raw / no_compress)
        $useRawUser = $request->boolean('raw_user',
                        $request->boolean('raw',
                            $request->boolean('no_compress', false)
                        )
                      );

        $dto = new SendRequest(
            projectId:     $projectId,
            projectPath:   (string)$project->path,
            userId:        $userId,
            prompt:        $prompt,
            auto:          $auto,
            model:         $model,
            compressModel: $compressModel,
            maxTokens:     $maxCompletion,
            useRawUser:    $useRawUser,
        );

        $result = $this->sender->handle($dto, fn(string $m,int $in,int $out) => $this->computeCostUsd($m,$in,$out));

        return $this->respond($request, [
            'answer'  => $result->answer,
            'project' => $result->project,
            'usage'   => $result->usage,
            'debug'   => $result->debug,
        ], 200);
    }

    // ===== Helpers minimi rimasti nel Controller =====

    protected function respond(Request $request, array $payload, int $status = 200)
    {
        return response()->json($payload, $status);
    }

    protected function computeCostUsd(string $model, int $in, int $out): float
    {
        $pricing = config("ai.pricing.$model");
        if (!$pricing) return 0.0;

        $pin  = (float) ($pricing['input_per_million']  ?? 0);
        $pout = (float) ($pricing['output_per_million'] ?? 0);

        return round((($in * $pin) + ($out * $pout)) / 1_000_000, 6);
    }

    protected function resolveOrCreateProjectByPath(string $path, int $userId): \App\Models\Project
    {
        $path = preg_replace('#/{2,}#','/',$path);
        $path = trim($path, '/');

        if ($existing = \App\Models\Project::where('path', $path)->where('user_id', $userId)->first()) {
            return $existing;
        }

        $parts = $path === '' ? ['Default'] : explode('/', $path);
        $name  = array_pop($parts);

        $parentId = null;
        if (!empty($parts)) {
            $folder   = $this->resolveOrCreateFolderPath(implode('/', $parts), $userId);
            $parentId = $folder->id;
        }

        return \App\Models\Project::create([
            'name'      => $name,
            'folder_id' => $parentId,
            'path'      => $path ?: 'Default',
            'user_id'   => $userId,
        ]);
    }

    private function resolveOrCreateFolderPath(string $path, int $userId): \App\Models\Folder
    {
        $path  = preg_replace('#/{2,}#','/',$path);
        $path  = trim($path, '/');
        $parts = $path === '' ? [] : explode('/', $path);

        $parentId = null;
        $folder   = null;

        foreach ($parts as $folderName) {
            $folderName = trim($folderName);
            $folder = \App\Models\Folder::firstOrCreate(
                ['name' => $folderName, 'parent_id' => $parentId, 'user_id' => $userId],
                []
            );
            $parentId = $folder->id;
        }

        if (!$folder) {
            $folder = \App\Models\Folder::firstOrCreate(
                ['name' => 'Generale', 'parent_id' => null, 'user_id' => $userId],
                []
            );
        }

        return $folder;
    }

    private function buildTree(int $userId): array
    {
        $toArray = function($folder) use (&$toArray){
            return [
                'id'       => $folder->id,
                'name'     => $folder->name,
                'projects' => $folder->projects()->orderBy('name')->get(['id','name','path'])->toArray(),
                'children' => $folder->children->sortBy('name')->values()->map(fn($f) => $toArray($f))->toArray(),
            ];
        };

        $roots = Folder::query()
            ->forUser($userId)
            ->with(['children.children','projects' => fn($q) => $q->forUser($userId)])
            ->whereNull('parent_id')->orderBy('name')->get();

        $tree     = array_map($toArray, $roots->all());
        $noFolder = Project::forUser($userId)
            ->whereNull('folder_id')->orderBy('name')->get(['id','name','path'])->toArray();

        return ['folders' => $tree, 'projectsNoFolder' => $noFolder];
    }

    private function resolveMaxTokens(\Illuminate\Http\Request $request): int
    {
        $defaultCap   = (int) env('LLM_MAX_COMPLETION_DEFAULT', 4000);
        $superCap     = (int) env('LLM_MAX_COMPLETION_SUPERUSER', 120000);
        // prende prima dall’header X-Max-Tokens, poi da input('max_tokens')
        $desired      = (int) ($request->header('X-Max-Tokens') ?? $request->input('max_tokens') ?? 0);

        $user         = $request->user();
        $allowListRaw = (string) env('LLM_UNCAPPED_USERS', '');
        $allowList    = array_filter(array_map('trim', explode(',', $allowListRaw)));

        if ($user && in_array((string) $user->email, $allowList, true)) {
            return $desired > 0 ? min($desired, $superCap) : $superCap;
        }
        return $desired > 0 ? min($desired, $defaultCap) : $defaultCap;
    }


}
