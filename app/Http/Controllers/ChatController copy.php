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
        $folders = Folder::with([
            'projects',
            'children.projects',
            'children.children.projects',
        ])->whereNull('parent_id')->orderBy('name')->get();

        $projectsNoFolder = Project::whereNull('folder_id')->orderBy('name')->get();

        return view('chat.index', compact('folders', 'projectsNoFolder'));
    }

    public function listProjects()
    {
        return response()->json($this->buildTree());
    }

    public function listMessages(Request $req)
    {
        $projectId = (int) $req->query('project_id');

        $messages = Message::where('project_id', $projectId)
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

        $project = $this->resolveOrCreateProjectByPath($path);
        return response()->json(['project' => $project], 201);
    }

    public function createFolder(Request $req)
    {
        $path = trim($req->input('path',''));
        if ($path === '') return response()->json(['error' => 'Path cartella vuoto'], 422);

        $folder = $this->resolveOrCreateFolderPath($path);
        $tree   = $this->buildTree();

        return response()->json(['folder' => $folder, 'tree' => $tree], 201);
    }

    public function stats()
    {
        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();

        $q = \DB::table('messages')
            ->whereBetween('created_at', [$start, $end])
            ->where('role', 'assistant');

        // Se vuoi filtrare per utente, aggiungi qui i tuoi where
        $tokens = (int) $q->sum('tokens_total');
        $cost   = (float) $q->sum('cost_usd');

        return response()->json([
            'tokens' => $tokens,
            'cost'   => round($cost, 4),
        ]);
    }

    public function deleteProject(int $projectId)
    {
        $project = Project::find($projectId);
        if (!$project) {
            return response()->json(['error' => 'Progetto non trovato'], 404);
        }

        $deleted = ['projects' => 0, 'messages' => 0];

        DB::transaction(function () use ($project, &$deleted) {
            // Messaggi del progetto (embedding si cancellano in cascata se hai messo FK -> cascade)
            $msgIds = Message::where('project_id', $project->id)->pluck('id');
            $deleted['messages'] = $msgIds->count();
            if ($deleted['messages'] > 0) {
                Message::whereIn('id', $msgIds)->delete();
            }

            // Progetto
            $deleted['projects'] = 1;
            $project->delete();
        });

        // Ricostruisci l’albero per la sidebar
        $tree = $this->buildTree();

        return response()->json([
            'ok'      => true,
            'deleted' => $deleted,
            'tree'    => $tree,
        ]);
    }

    public function deleteFolder(int $folderId)
    {
        $root = Folder::find($folderId);
        if (!$root) {
            return response()->json(['error' => 'Cartella non trovata'], 404);
        }

        $deleted = ['folders' => 0, 'projects' => 0, 'messages' => 0];

        DB::transaction(function () use ($root, &$deleted) {
            // 1) raccogli tutti gli id cartella del sottoalbero (inclusa root)
            $folderIds = $this->collectFolderIds($root->id);
            $deleted['folders'] = count($folderIds);

            // 2) prendi tutti i progetti nelle cartelle raccolte
            $projectIds = Project::whereIn('folder_id', $folderIds)->pluck('id')->all();
            $deleted['projects'] = count($projectIds);

            // 3) cancella messaggi dei progetti (embedding cascata via FK)
            if ($projectIds) {
                $msgIds = Message::whereIn('project_id', $projectIds)->pluck('id');
                $deleted['messages'] = $msgIds->count();
                if ($deleted['messages'] > 0) {
                    Message::whereIn('id', $msgIds)->delete();
                }
                // 4) cancella progetti
                Project::whereIn('id', $projectIds)->delete();
            }

            // 5) cancella cartelle (foglie -> root). Con whereIn basta: FK figli->parent con cascade non è necessario se non definito.
            Folder::whereIn('id', $folderIds)->delete();
        });

        $tree = $this->buildTree();

        return response()->json([
            'ok'      => true,
            'deleted' => $deleted,
            'tree'    => $tree,
        ]);
    }

    /**
     * Raccoglie tutti gli ID cartella del sottoalbero (BFS) includendo la root.
     */
    private function collectFolderIds(int $rootId): array
    {
        $ids   = [];
        $queue = [$rootId];

        while (!empty($queue)) {
            $id = array_shift($queue);
            $ids[] = $id;

            $children = Folder::where('parent_id', $id)->pluck('id')->all();
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

        $project = $this->resolveOrCreateProjectByPath($path);
        $projectId = (int) data_get($project, 'id');
        if (!$projectId) {
            \Log::error('Impossibile risolvere/creare Project', ['path'=>$path,'project'=>$project]);
            return $this->respond($request, ['error'=>'Project non disponibile'], 500);
        }

        $model         = (string)$request->input('model', env('OPENAI_MODEL','openai:gpt-5'));
        $compressModel = (string)$request->input('compress_model', 'openai:gpt-4o-mini');
        $maxCompletion = (int)($request->input('max_tokens') ?? 2000);
        $userId        = (int)(auth()->id() ?? 0);

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
            useRawUser:    $useRawUser,  // 👈 qui
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
        // Se hai un file di pricing, leggi da config; altrimenti 0.0
        $pricing = config("ai.pricing.$model");
        if (!$pricing) return 0.0;

        $pin  = (float) ($pricing['input_per_million']  ?? 0);
        $pout = (float) ($pricing['output_per_million'] ?? 0);

        return round((($in * $pin) + ($out * $pout)) / 1_000_000, 6);
    }

    protected function resolveOrCreateProjectByPath(string $path): \App\Models\Project
    {
        $path = preg_replace('#/{2,}#','/',$path);
        $path = trim($path, '/');

        if ($existing = \App\Models\Project::where('path', $path)->first()) return $existing;

        $parts = $path === '' ? ['Default'] : explode('/', $path);
        $name  = array_pop($parts);

        $parentId = null;
        if (!empty($parts)) {
            $folder   = $this->resolveOrCreateFolderPath(implode('/', $parts));
            $parentId = $folder->id;
        }

        return \App\Models\Project::create([
            'name'      => $name,
            'folder_id' => $parentId,
            'path'      => $path ?: 'Default',
        ]);
    }

    private function resolveOrCreateFolderPath(string $path): \App\Models\Folder
    {
        $path  = preg_replace('#/{2,}#','/',$path);
        $path  = trim($path, '/');
        $parts = $path === '' ? [] : explode('/', $path);

        $parentId = null;
        $folder   = null;

        foreach ($parts as $folderName) {
            $folderName = trim($folderName);
            $folder = \App\Models\Folder::firstOrCreate(
                ['name' => $folderName, 'parent_id' => $parentId],
                ['parent_id' => $parentId]
            );
            $parentId = $folder->id;
        }

        if (!$folder) {
            $folder = \App\Models\Folder::firstOrCreate(['name' => 'Generale', 'parent_id' => null]);
        }

        return $folder;
    }

    private function buildTree(): array
    {
        $toArray = function($folder) use (&$toArray){
            return [
                'id'       => $folder->id,
                'name'     => $folder->name,
                'projects' => $folder->projects()->orderBy('name')->get(['id','name','path'])->toArray(),
                'children' => $folder->children->sortBy('name')->values()->map(fn($f) => $toArray($f))->toArray(),
            ];
        };

        $roots = Folder::with(['children.children','projects'])
            ->whereNull('parent_id')->orderBy('name')->get();

        $tree     = array_map($toArray, $roots->all());
        $noFolder = Project::whereNull('folder_id')->orderBy('name')->get(['id','name','path'])->toArray();

        return ['folders' => $tree, 'projectsNoFolder' => $noFolder];
    }
}
