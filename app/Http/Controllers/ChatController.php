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
use App\Services\LLM\LlmClient;

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
        $path   = trim($request->input('project_path') ?? '');
        $prompt = (string) $request->input('prompt', '');
        $auto   = $request->boolean('auto', true);

        if ($path === '') { $path = 'Default'; }

        $project = $this->resolveOrCreateProjectByPath($path);

        $model = (string) $request->input('model', env('OPENAI_MODEL', 'openai:gpt-5'));
        $compressModel = (string) $request->input('compress_model', 'openai:gpt-4o-mini');

        $project = $this->resolveOrCreateProjectByPath($path);

        // Salvo subito il messaggio utente
        $userMsg = Message::create([
            'project_id' => $project->id,
            'role'       => 'user',
            'content'    => $prompt,
        ]);

        // Ultime coppie di messaggi
        $history = $this->buildCompressedContext($project->id, pairs: 6);

        // Memorie brevi
        $memories = '';
        if ($auto) {
            $memories = \App\Models\Memory::where('project_id', $project->id)
                ->latest()->take(3)
                ->pluck('content')
                ->map(fn($c) => $this->clamp($c, 400))
                ->implode("\n---\n");
        }

        // === COMPRESSORE ===
        $compressKey   = env(strtoupper(strtok($compressModel, ':')) . '_API_KEY');

        $compressionPrompt = <<<EOT
    Il seguente blocco è il CONTEXT di una chat tecnica.
    Devi riassumerlo in massimo 500 caratteri, mantenendo SOLO le informazioni rilevanti per capire la domanda.
    NON aggiungere consigli, NON inventare testo, NON cambiare la domanda.

    === CONTEXT START ===
    {$history}
    === CONTEXT END ===

    Domanda attuale (NON modificare):
    {$prompt}

    Rispondi con:
    [Contesto compresso]
    DOMANDA: [domanda invariata]
    EOT;

        $compressedPrompt = $compressionPrompt;

        // === COMPRESSORE ===
        try {
            $comp = $this->callProvider($compressModel, [
                ['role' => 'system', 'content' => 'Sei un compressore di contesto. Non aggiungere testo tuo.'],
                ['role' => 'user',   'content' => $compressionPrompt],
            ], [
                'max_tokens'  => 800,
                'temperature' => 0,
            ]);

            $compressedPrompt = trim($comp['text'] ?? $compressionPrompt);
            $compressUsage = [
                'model'  => $compressModel,
                'tokens' => (int)($comp['usage']['total'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return $this->respond($request, ['error' => 'Errore compressore: '.$e->getMessage()], 500);
        }


        // === GPT-5 (o altro) ===
        $model = (string) $request->input('model', env('OPENAI_MODEL', 'openai:gpt-5'));
        $maxCompletion = (int)($request->input('max_tokens') ?? 2000);

        try {
            $final = $this->callProvider($model, [
                ['role' => 'system', 'content' => 'Sei un assistente tecnico. Rispondi conciso, in italiano, con codice quando serve.'],
                ['role' => 'user',   'content' => $compressedPrompt],
            ], [
                'max_tokens'  => $maxCompletion,
                'temperature' => 1,
            ]);

            $answer   = $final['text'] ?? 'Nessun contenuto nella risposta API.';
            $inTokens  = (int)($final['usage']['input']  ?? 0);
            $outTokens = (int)($final['usage']['output'] ?? 0);
            $totTokens = (int)($final['usage']['total']  ?? ($inTokens + $outTokens));
            $costUsd   = $this->computeCostUsd($model, $inTokens, $outTokens);
        } catch (\Throwable $e) {
            return $this->respond($request, ['error' => 'Eccezione: '.$e->getMessage()], 500);
        }


        // Salva risposta
        $assistantMsg = Message::create([
            'project_id'    => $project->id,
            'role'          => 'assistant',
            'content'       => $answer,
            'tokens_input'  => $inTokens,
            'tokens_output' => $outTokens,
            'tokens_total'  => $totTokens,
            'cost_usd'      => $costUsd,
            'model'         => $model,
        ]);

        Memory::create([
            'project_id' => $project->id,
            'content'    => $prompt . "\n" . $answer,
        ]);

        return $this->respond($request, [
            'answer'  => $answer,
            'project' => $project->only('id', 'path'),
            'usage'   => [
                'input'  => $inTokens,
                'output' => $outTokens,
                'total'  => $totTokens,
                'cost'   => $costUsd,
                'model'  => $model,
                'compress' => $compressUsage ?? null,
                'compressed_prompt_chars' => strlen($compressedPrompt),
            ],
        ], 200);
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

    public function stats()
    {
        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();

        $q = \DB::table('messages')
            ->whereBetween('created_at', [$start, $end])
            ->where('role', 'assistant');

        // opzionale: per utente
        if (auth()->check()) {
            // se hai relazione user->project o direttamente user_id in messages, filtra qui
            // $q->where('user_id', auth()->id());
        }

        $tokens = (int) $q->sum('tokens_total');
        $cost   = (float) $q->sum('cost_usd');

        return response()->json([
            'tokens' => $tokens,
            'cost'   => round($cost, 4),
        ]);
    }


    private function fetchRecentConversation(int $projectId, int $pairs = 8): string {
        $msgs = \App\Models\Message::where('project_id', $projectId)
            ->whereIn('role', ['user','assistant'])
            ->orderByDesc('id')->limit($pairs * 2)->get()->reverse();

        $lines = [];
        foreach ($msgs as $m) {
            $role = $m->role === 'user' ? 'Utente' : 'Assistente';
            // NON rimuoviamo i blocchi di codice qui: decide il compressore
            $lines[] = $role.': '.$m->content;
        }
        return implode("\n", $lines);
    }

    /** calcola costo in USD dato il modello e i token */
    private function computeCostUsd(string $model, int $inputTokens, int $outputTokens): float
    {
        $pricing = config("ai.pricing.$model");
        if (!$pricing) return 0.0;

        $in  = (float) ($pricing['input_per_million']  ?? 0);
        $out = (float) ($pricing['output_per_million'] ?? 0);

        return round((($inputTokens * $in) + ($outputTokens * $out)) / 1_000_000, 6);
    }


    private function clamp(string $s, int $max): string {
        // rimuovi i blocchi di codice (fanno salire i token a cavolo)
        $s = preg_replace('/```[\s\S]*?```/m', '[codice omesso]', $s);
        // compatta spazi e righe
        $s = preg_replace('/\s+/', ' ', trim($s));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }

    private function buildCompressedContext(int $projectId, int $pairs = 3, int $perMsgMax = 220, int $totalBudget = 1400): string {
        // ultime N coppie (2N messaggi), in ordine cronologico
        $msgs = \App\Models\Message::where('project_id', $projectId)
            ->whereIn('role', ['user','assistant'])
            ->orderByDesc('id')
            ->limit($pairs * 2)
            ->get()
            ->reverse();

        $out = [];
        $used = 0;
        foreach ($msgs as $m) {
            $role = $m->role === 'user' ? 'Utente' : 'Assistente';
            $line = $role.': '.$this->clamp($m->content ?? '', $perMsgMax);
            $len  = mb_strlen($line) + 1;
            if ($used + $len > $totalBudget) break;
            $out[] = $line;
            $used += $len;
        }
        return implode("\n", $out);
    }









    /**
     * Restituisce l'URL dell'endpoint API in base al provider.
     */
    private function getApiUrl(string $model): string
    {
        $provider = strtolower(strtok($model, ':'));

        return match ($provider) {
            'openai'   => 'https://api.openai.com/v1/chat/completions',
            'anthropic'=> 'https://api.anthropic.com/v1/messages',
            'google'   => 'https://generativelanguage.googleapis.com/v1beta/models/' .
                        $this->mapModelName($model) . ':generateContent?key=' . env('GOOGLE_API_KEY'),
            default    => throw new \Exception("Provider API non supportato: {$provider}"),
        };
    }

    /**
     * Mappa il nome del modello in base al provider.
     */
    private function mapModelName(string $model): string
    {
        $provider = strtolower(strtok($model, ':'));
        $name     = trim(strstr($model, ':'), ':') ?: $model;

        return match ($provider) {
            // OpenAI
            'openai'    => $name, // es. gpt-5, gpt-4o-mini
            // Anthropic
            'anthropic' => match ($name) {
                'claude-3-opus'   => 'claude-3-opus-20240229',
                'claude-3-sonnet'=> 'claude-3-sonnet-20240229',
                'claude-3-haiku' => 'claude-3-haiku-20240307',
                default           => $name
            },
            // Google
            'google'    => match ($name) {
                'gemini-1.5-pro'  => 'gemini-1.5-pro',
                'gemini-1.5-flash'=> 'gemini-1.5-flash',
                default           => $name
            },
            default     => throw new \Exception("Provider non supportato: {$provider}"),
        };
    }






    private function callProvider(string $modelId, array $messages, array $opts = []): array
    {
        $provider = strtolower(strtok($modelId, ':'));
        $opts['model'] = $modelId;

        switch ($provider) {
            case 'openai':
                $p = new \App\Services\LLM\Providers\OpenAIProvider();
                break;
            case 'anthropic':
                $p = new \App\Services\LLM\Providers\AnthropicProvider();
                break;
            case 'google':
                $p = new \App\Services\LLM\Providers\GoogleProvider();
                break;
            default:
                throw new \RuntimeException("Provider non supportato: {$provider}");
        }

        return $p->chat($messages, $opts);
    }








}
