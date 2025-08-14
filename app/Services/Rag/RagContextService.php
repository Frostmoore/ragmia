<?php
declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\Project;
use App\Models\Message;
use App\Repositories\MessageRetrieval;
use App\Services\LLM\LlmClient;

class RagContextService
{
    public function __construct(
        private MessageRetrieval $retrieval,
        private LlmClient $llm,
        private GlobalContextService $global, // 👈 aggiunto
    ) {}

    public function answer(int $projectId, string $userText, array $opts = []): array
    {
        $project = Project::findOrFail($projectId);
        $userId  = (int)(auth()->id() ?? 0);

        // === GLOBAL ===
        $g = $this->global->get($userId);     // ['model'=>GlobalContext, 'state'=>[], 'short'=>'' ]
        $gState = $g['state'];
        $gShort = $g['short'];

        // === PROJECT ===
        $pState = $this->normalizeState($project->thread_state ?? []);
        $pShort = (string)($project->short_summary ?? '');

        // Retrieval
        $retr = $this->retrieval->retrieveForQuery(
            $projectId, $userText,
            k: (int)($opts['k'] ?? 8),
            recN: (int)($opts['rec_n'] ?? 3),
            limitTokens: (int)($opts['ctx_limit_tokens'] ?? 1200)
        );

        // Pacchetto grezzo (GLOBAL + PROJECT + SOURCES)
        $system =
            ($opts['system'] ?? "Se manca contesto, fai UNA domanda mirata. Non inventare. Fornisci patch/diff quando tocchi codice.")
            . "\n\n[GLOBAL_STATE]\n".json_encode($gState, JSON_UNESCAPED_UNICODE)
            . "\n\n[GLOBAL_SUMMARY]\n".$gShort
            . "\n\n[PROJECT_STATE]\n".json_encode($pState, JSON_UNESCAPED_UNICODE)
            . "\n\n[PROJECT_SUMMARY]\n".$pShort;

        $msgs = [['role'=>'system','content'=>$system]];
        foreach ($retr['chunks'] as $i=>$c) {
            $msgs[] = ['role'=>'system','content'=>"SOURCE #{$i} (type={$c['type']}; id={$c['id']}; why={$c['why']})\n".trim($c['content'])];
        }
        $msgs[] = ['role'=>'user','content'=>$userText];

        // Compress
        $compressed = $this->compress($msgs, (int)($opts['compressed_target_tokens'] ?? 1600));

        // Call modello finale
        $model = env('OPENAI_MODEL','openai:gpt-5');
        $resp  = $this->llm->call($model, array_merge($compressed, [['role'=>'user','content'=>$userText]]), [
            'max_tokens'  => (int)($opts['max_tokens'] ?? 1200),
            'temperature' => (float)($opts['temperature'] ?? 0.2),
        ]);
        $answer = $resp['text'] ?? '—';

        // Persist turn
        $u = Message::create(['project_id'=>$projectId,'role'=>'user','content'=>$userText]);
        $a = Message::create([
            'project_id'=>$projectId,'role'=>'assistant','content'=>$answer,
            'meta'=>['used_chunks'=>array_column($retr['chunks'],'id'),'model'=>$model,'usage'=>$resp['usage'] ?? null]
        ]);

        // Update PROJECT summary/state
        $project->short_summary = $this->summarizeProjectShort($pShort, $userText, $answer);
        $project->thread_state  = $this->updateProjectState($pState, $userText, $answer);
        $project->save();

        // Update GLOBAL summary/state (solo parti di sistema estratte)
        $this->global->updateFromTurn($userId, $userText, $answer);

        return [
            'answer' => $answer,
            'debug'  => [
                'picked_chunks' => array_column($retr['chunks'],'id'),
                'global_state_bytes'  => strlen(json_encode($gState)),
                'project_state_bytes' => strlen(json_encode($pState)),
                'model' => $model,
                'usage' => $resp['usage'] ?? null,
            ],
            'message_ids' => [$u->id, $a->id],
        ];
    }

    private function compress(array $messages, int $targetTokens): array
    {
        $compressModel = env('COMPRESS_MODEL','openai:gpt-4o-mini');
        $sys = ['role'=>'system','content'=>"Sei un compressore di contesto. Mantieni SOLO fatti/decisioni/policy utili. Target max {$targetTokens} token."];
        $resp = $this->llm->call($compressModel, array_merge([$sys], $messages), ['max_tokens'=>$targetTokens,'temperature'=>0]);
        return [['role'=>'system','content'=>$resp['text'] ?? '']];
    }

    // === Project-level summary/state (come prima) ===
    private function summarizeProjectShort(string $prevShort, string $userText, string $assistantText): string
    {
        $compressModel = env('COMPRESS_MODEL','openai:gpt-4o-mini');
        $msgs = [
            ['role'=>'system','content'=>'Aggiorna un running summary di 10–20 righe per QUESTO PROGETTO (decisioni, vincoli, next).'],
            ['role'=>'user','content'=>"PREV:\n{$prevShort}\n\nUSER:\n{$userText}\n\nASSISTANT:\n{$assistantText}"],
        ];
        $resp = $this->llm->call($compressModel, $msgs, ['max_tokens'=>400,'temperature'=>0]);
        return $resp['text'] ?? $prevShort;
    }

    private function updateProjectState(array $prevState, string $userText, string $assistantText): array
    {
        $compressModel = env('COMPRESS_MODEL','openai:gpt-4o-mini');
        $schema = json_encode([
            'obiettivi'=>[],'vincoli'=>[],'decisioni'=>[],'file_toccati'=>[],
            'schema_db'=>[],'todo_aperti'=>[],'assunzioni'=>[],'glossario'=>[]
        ], JSON_UNESCAPED_UNICODE);

        $msgs = [
            ['role'=>'system','content'=>"Dato stato JSON del PROGETTO e ultimo scambio, restituisci SOLO JSON valido con le stesse chiavi. Schema: {$schema}"],
            ['role'=>'user','content'=>"PREV_STATE:\n".json_encode($prevState, JSON_UNESCAPED_UNICODE)."\n\nUSER:\n{$userText}\n\nASSISTANT:\n{$assistantText}"],
        ];
        $resp = $this->llm->call($compressModel, $msgs, ['max_tokens'=>700,'temperature'=>0]);
        $new  = json_decode($resp['text'] ?? '', true);
        return is_array($new) ? array_replace($prevState, $new) : $prevState;
    }

    private function normalizeState($state): array
    {
        if (!is_array($state)) $state = json_decode((string)$state, true) ?: [];
        $state += [
            'project'      => $state['project'] ?? null,
            'obiettivi'    => $state['obiettivi'] ?? [],
            'vincoli'      => $state['vincoli'] ?? [],
            'decisioni'    => $state['decisioni'] ?? [],
            'file_toccati' => $state['file_toccati'] ?? [],
            'schema_db'    => $state['schema_db'] ?? [],
            'todo_aperti'  => $state['todo_aperti'] ?? [],
            'assunzioni'   => $state['assunzioni'] ?? [],
            'glossario'    => $state['glossario'] ?? [],
        ];
        return $state;
    }
}
