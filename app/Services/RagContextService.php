<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Message;
use App\Repositories\MessageRetrieval;
use App\Support\LlmClient;
use App\Services\SendCoordinator;

class RagContextService
{
    public function __construct(
        private MessageRetrieval $retrieval,
        private LlmClient $llm,
        private SendCoordinator $coordinator,
    ) {}

    public function answer(int $projectId, string $userText, array $opts = []): array
    {
        $project = Project::findOrFail($projectId);

        // 1) stato+summary
        $state = $this->normalizeState($project->thread_state ?? []);
        $short = (string)($project->short_summary ?? '');

        // 2) retrieval
        $k     = $opts['k']     ?? 8;
        $recN  = $opts['rec_n'] ?? 3;
        $limit = $opts['ctx_limit_tokens'] ?? 1200;
        $retr  = $this->retrieval->retrieveForQuery($projectId, $userText, $k, $recN, $limit);

        // 3) pacchetto
        $system = $opts['system'] ?? "Se manca contesto, fai UNA domanda mirata. Non inventare. Fornisci patch/diff quando tocchi codice.";
        $messages = $this->assemble($system, $state, $short, $retr, $userText);

        // 4) compress su cheap
        $target = $opts['compressed_target_tokens'] ?? 1600;
        $compressed = $this->llm->compress($messages, $target); // ritorna un unico system compresso

        // 5) invio al provider “serio” tramite il tuo coordinator
        $provider = $opts['provider'] ?? 'openai'; // o anthropic/google a tua scelta
        $model    = $opts['model']    ?? config('services.openai.main_model','gpt-5-thinking');

        $result = $this->coordinator->sendChat([
            'provider' => $provider,
            'model'    => $model,
            'messages' => array_merge($compressed, [['role'=>'user','content'=>$userText]]),
            'max_tokens' => $opts['max_tokens'] ?? 1200,
            'temperature'=> $opts['temperature'] ?? 0.2,
        ]);
        $answerText = $result['text'] ?? ($result['message'] ?? '');

        // 6) persistenza messaggi
        Message::create(['project_id'=>$projectId,'role'=>'user','content'=>$userText]);
        Message::create([
            'project_id'=>$projectId,'role'=>'assistant','content'=>$answerText,
            'meta'=>[
                'used_chunks'=>array_column($retr['chunks'],'id'),
                'model'=>$model,'provider'=>$provider,
                'usage'=>$result['usage'] ?? null
            ]
        ]);

        // 7) aggiorna short summary + stato con modello cheap
        $newShort = $this->llm->summarizeShort($short, $userText, $answerText);
        $newState = $this->llm->updateState($state, $userText, $answerText);
        $project->short_summary = $newShort;
        $project->thread_state  = $newState;
        $project->save();

        return [
            'answer' => $answerText,
            'debug'  => [
                'picked_chunks'=>array_column($retr['chunks'],'id'),
                'state_bytes'=>strlen(json_encode($state)),
                'summary_len'=>mb_strlen($short),
                'provider'=>$provider,'model'=>$model,
            ]
        ];
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

    private function assemble(string $system, array $state, string $short, array $retr, string $userText): array
    {
        $msgs = [[
            'role'=>'system',
            'content'=> $system
                ."\n\n[STATE]\n".json_encode($state, JSON_UNESCAPED_UNICODE)
                ."\n\n[SHORT_SUMMARY]\n".$short
        ]];

        foreach ($retr['chunks'] as $i => $c) {
            $msgs[] = [
                'role' => 'system',
                'content' => "SOURCE #{$i} (type={$c['type']}; id={$c['id']}; why={$c['why']})\n"
                           . trim($c['content'])
            ];
        }
        $msgs[] = ['role'=>'user','content'=>$userText];
        return $msgs;
    }
}
