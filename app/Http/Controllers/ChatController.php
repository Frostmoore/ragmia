<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Project;
use App\Models\Message;
use App\Models\Memory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index()
    {
        // Cartelle root con figli e nipoti + progetti a ogni livello
        $folders = Folder::with([
            'projects',
            'children.projects',
            'children.children.projects',
        ])
        ->whereNull('parent_id')
        ->orderBy('name')
        ->get();

        // Progetti senza cartella
        $projectsNoFolder = Project::whereNull('folder_id')
            ->orderBy('name')
            ->get();

        // 👉 usa la nuova view Breeze-based
        return view('chat.index', compact('folders', 'projectsNoFolder'));
    }


    public function listProjects()
    {
        $tree = $this->buildTree();
        return response()->json($tree);
    }

    public function listMessages(Request $req)
    {
        $projectId = (int)$req->query('project_id');
        $messages = Message::where('project_id', $projectId)
            ->orderBy('created_at','asc')
            ->get(['role','content','created_at']);

        return response()->json(['messages' => $messages]);
    }

    public function createProject(Request $req)
    {
        $path = trim($req->input('path'));
        if ($path === '') return response()->json(['error'=>'Path vuoto'], 422);

        $project = $this->resolveOrCreateProjectByPath($path);
        return response()->json(['project' => $project], 201);
    }

    public function send(Request $request)
    {
        $path    = trim($request->input('project_path') ?? '');
        $prompt  = $request->input('prompt');
        $auto    = $request->boolean('auto');

        if ($path === '') { $path = 'Default'; }

        $project = $this->resolveOrCreateProjectByPath($path);

        // salvataggio messaggio user
        Message::create([
            'project_id' => $project->id,
            'role'       => 'user',
            'content'    => $prompt,
        ]);

        // recupero memoria RAG (ultimi 10 blocchi)
        $memories = Memory::where('project_id', $project->id)
            ->latest()->take(10)->pluck('content')->implode("\n");

        $finalPrompt = "CONTESTO PROGETTO («{$project->path}»):\n{$memories}\n\nUTENTE:\n{$prompt}";

        try {
            $res = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(90)
                ->acceptJson()
                ->asJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'    => env('OPENAI_MODEL', 'gpt-5'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Sei un assistente tecnico. Rispondi conciso, in italiano, con codice quando serve.'],
                        ['role' => 'user',   'content' => $finalPrompt],
                    ],
                    'max_completion_tokens' => (int)($request->input('max_tokens') ?? 2000),
                    'temperature' => 1,
                ]);

            if (!$res->successful()) {
                $err = $res->json()['error']['message'] ?? ('HTTP '.$res->status());
                return $this->respond($request, ['error' => $err], 422);
            }

            $data   = $res->json();
            $answer = data_get($data, 'choices.0.message.content')
                   ?? data_get($data, 'choices.0.text')
                   ?? 'Nessun contenuto nella risposta API.';

        } catch (\Throwable $e) {
            return $this->respond($request, ['error' => 'Eccezione: '.$e->getMessage()], 500);
        }

        // salva risposta e aggiorna memoria
        Message::create([
            'project_id' => $project->id,
            'role'       => 'assistant',
            'content'    => $answer,
        ]);

        Memory::create([
            'project_id' => $project->id,
            'content'    => $prompt . "\n" . $answer,
        ]);

        return $this->respond($request, ['answer' => $answer, 'project' => $project->only('id','path')], 200);
    }

    private function respond(Request $r, array $payload, int $status=200)
    {
        if ($r->expectsJson() || $r->ajax()) {
            return response()->json($payload, $status);
        }
        return back()->with($payload);
    }

    private function resolveOrCreateProjectByPath(string $path): \App\Models\Project
    {
        $path = preg_replace('#/{2,}#','/',$path);
        $path = trim($path, '/');

        if ($existing = \App\Models\Project::where('path', $path)->first()) return $existing;

        $parts = $path === '' ? ['Default'] : explode('/', $path);
        $name  = array_pop($parts);

        $parentId = null;
        if (!empty($parts)) {
            // crea tutta la catena cartelle e prendi l'ultima come parent
            $folder = $this->resolveOrCreateFolderPath(implode('/', $parts));
            $parentId = $folder->id;
        }

        return \App\Models\Project::create([
            'name'      => $name,
            'folder_id' => $parentId,
            'path'      => $path ?: 'Default',
        ]);
    }


    private function buildTree(): array
    {
        $toArray = function($folder) use (&$toArray){
            return [
                'id' => $folder->id,
                'name' => $folder->name,
                'projects' => $folder->projects()->orderBy('name')->get(['id','name','path'])->toArray(),
                'children' => $folder->children->sortBy('name')->values()->map(fn($f) => $toArray($f))->toArray(),
            ];
        };

        $roots = Folder::with(['children.children','projects'])->whereNull('parent_id')->orderBy('name')->get();
        $tree = array_map($toArray, $roots->all());
        $noFolder = Project::whereNull('folder_id')->orderBy('name')->get(['id','name','path'])->toArray();

        return ['folders' => $tree, 'projectsNoFolder' => $noFolder];
    }

    private function resolveOrCreateFolderPath(string $path): \App\Models\Folder
    {
        $path = preg_replace('#/{2,}#','/',$path);
        $path = trim($path, '/');
        $parts = $path === '' ? [] : explode('/', $path);

        $parentId = null;
        $folder = null;
        foreach ($parts as $folderName) {
            $folderName = trim($folderName);
            $folder = \App\Models\Folder::firstOrCreate(
                ['name' => $folderName, 'parent_id' => $parentId],
                ['parent_id' => $parentId]
            );
            $parentId = $folder->id;
        }
        if (!$folder) { // nessuna parte → crea radice “Generale”
            $folder = \App\Models\Folder::firstOrCreate(['name' => 'Generale', 'parent_id' => null]);
        }
        return $folder;
    }

    public function createFolder(Request $req)
    {
        $path = trim($req->input('path') ?? '');
        if ($path === '') return response()->json(['error' => 'Path cartella vuoto'], 422);

        $folder = $this->resolveOrCreateFolderPath($path);

        // ritorna albero aggiornato
        $tree = $this->buildTree();
        return response()->json(['folder' => $folder, 'tree' => $tree], 201);
    }

}
