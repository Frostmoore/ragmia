<?php
declare(strict_types=1);

namespace App\Services\Chat\Send;

use App\DTOs\Plan;
use App\Models\Project;
use App\Repositories\MessageRetrieval;
use App\Services\Chat\HistoryService;
use App\Services\Chat\MemoryMerger;
use App\Services\Chat\MemoryService;
use App\Services\Chat\PayloadInjector;
use App\Services\Chat\PlannerService;
use App\Services\Chat\PostTurnUpdater;
use App\Services\Chat\PromptBuilder;
use App\Services\Chat\PreTurnProfileUpdater;   // ✅ CORRETTO
use App\Services\Chat\ContextHintService;      // ✅ CORRETTO
use App\Services\LLM\LlmClient;
use App\Services\Rag\GlobalContextService;

class SendCoordinator
{
    public function __construct(
        private LlmClient            $llm,
        private HistoryService       $history,
        private MemoryService        $mem,
        private MemoryMerger         $merger,
        private PlannerService       $planner,
        private PromptBuilder        $builder,
        private PostTurnUpdater      $updater,
        private ?PreTurnProfileUpdater $preProfile = null,     // ✅ CORRETTO
        private ?ContextHintService    $hints      = null,     // ✅ CORRETTO
        private GlobalContextService    $globalCtx,
        private MessageRetrieval        $retrieval,
        private PayloadInjector         $injector,
    ) {}

    public function handle(SendRequest $r, ?callable $costFn = null): SendResult
    {
        // 0) append user
        $this->history->appendUser($r->projectId, $r->prompt);

        // 0.b) ===== RAG CONTEXT (GLOBAL + PROJECT + SOURCES) =====
        $userId  = (int)($r->userId ?? 0);
        $g       = $this->globalCtx->get($userId);
        $gState  = $g['state'];
        $gShort  = (string)$g['short'];

        $project = Project::find($r->projectId);
        $pState  = $this->normalizeProjectState($project?->thread_state ?? []);
        $pShort  = (string)($project?->short_summary ?? '');

        $retr        = $this->retrieval->retrieveForQuery($r->projectId, $r->prompt, 8, 3, 1200);
        $sourcesText = $this->renderSources($retr['chunks'] ?? []);

        // 1) memorie
        $projectMemoryJson = $r->auto ? $this->mem->getProjectMemoryJson($r->projectId) : '';
        $userProfileJson   = ($r->auto && $r->userId) ? $this->mem->getUserProfileJson($r->userId) : '';
        if ($r->auto && $r->userId && $this->preProfile) {
            $userProfileJson = $this->preProfile->ensureBeforeTurn(
                $r->compressModel, $r->userId, $r->prompt, $userProfileJson
            );
        }
        $projectMemArr  = $this->mem->decode($projectMemoryJson);
        $userProfileArr = $this->mem->decode($userProfileJson);

        // 2) history
        $historyArr = $this->history->recent($r->projectId, 30);

        // 3) planner
        $plan = new Plan();
        $plannerRaw = '';
        if ($r->auto) {
            try {
                $plan = $this->planner->plan(
                    $r->compressModel, $userProfileJson, $projectMemoryJson, $historyArr, $r->prompt, $memoryHintsText
                );
                $plannerRaw = $plan->raw;
            } catch (\Throwable $e) {
                \Log::warning('Planner fallito', ['err' => $e->getMessage()]);
            }
        }
        if (!$plan->final_user) {
            $plan->final_user = $r->prompt;
        }
        if ($r->useRawUser) {
            $plan->final_user = $r->prompt;
        }

        // 3b) merge memorie
        if ($r->auto) {
            $frame = [
                'theme'    => $plan->theme,
                'genre'    => $plan->genre,
                'format'   => $plan->format,
                'prefs'    => ['style' => $plan->style, 'avoid' => $plan->avoid],
                'language' => $plan->language,
                'length'   => $plan->length,
            ];
            $mergedProject     = $this->merger->mergeProject($projectMemArr, $frame);
            $mergedProjectJson = json_encode($mergedProject, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($mergedProjectJson && $mergedProjectJson !== $projectMemoryJson) {
                $this->mem->saveProjectMemoryJson($r->projectId, $mergedProjectJson);
                $projectMemoryJson = $mergedProjectJson;
                $projectMemArr     = $mergedProject;
            }
            if ($r->userId && $plan->update_user_profile && $plan->user_profile_update) {
                $mergedProfile     = $this->merger->mergeUserProfile($userProfileArr, $plan->user_profile_update);
                $mergedProfileJson = json_encode($mergedProfile, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if ($mergedProfileJson && $mergedProfileJson !== $userProfileJson) {
                    $this->mem->saveUserProfileJson($r->userId, $mergedProfileJson);
                    $userProfileJson   = $mergedProfileJson;
                    $userProfileArr    = $mergedProfile;
                }
            }
        }

        // 3c) hints (opzionale)
        $memoryHintsText = '';
        if ($r->auto && $this->hints) {
            $frameHints = [];
            if ($plan->subject || $plan->theme) $frameHints[] = ['tag'=>'topic','value'=>($plan->subject ?: $plan->theme),'weight'=>1.4,'source'=>'planner'];
            foreach ((array)$plan->style as $v) $frameHints[] = ['tag'=>'style','value'=>$v,'weight'=>1.1,'source'=>'planner'];
            foreach ((array)$plan->avoid as $v) $frameHints[] = ['tag'=>'avoid','value'=>$v,'weight'=>1.0,'source'=>'planner'];
            if ($plan->format)   $frameHints[] = ['tag'=>'format','value'=>$plan->format,'weight'=>1.0,'source'=>'planner'];
            if ($plan->language) $frameHints[] = ['tag'=>'language','value'=>$plan->language,'weight'=>1.2,'source'=>'planner'];
            if ($plan->genre)    $frameHints[] = ['tag'=>'genre','value'=>$plan->genre,'weight'=>1.0,'source'=>'planner'];

            try {
                $this->hints->addHints($r->userId ?: null, $r->projectId, $frameHints);
                $planArr = [
                    'final_user' => $plan->final_user,
                    'subject'    => $plan->subject ?: $plan->theme,
                    'style'      => $plan->style,
                    'avoid'      => $plan->avoid,
                    'format'     => $plan->format,
                    'language'   => $plan->language,
                    'genre'      => $plan->genre,
                ];
                $topHints = $this->hints->getHintsForPrompt(
                    $r->userId ?: null, $r->projectId, $r->prompt, $planArr, 12
                );
                $memoryHintsText = $this->hints->renderForPrompt($topHints);
            } catch (\Throwable $e) {
                \Log::warning('ContextHints failure', ['err' => $e->getMessage()]);
            }
        }

        // 4) payload base
        $sourceText = $this->builder->determineSourceText($plan, $historyArr);
        $final      = $this->builder->buildFinalPayload(
            $plan, $userProfileJson, $projectMemoryJson, $sourceText
        );

        // 4.b) ===== inietta SEMPRE RAG (GLOBAL+PROJECT+SOURCES) già compresso =====
        $extraRaw = $this->renderExtraContext($gState, $gShort, $pState, $pShort, $sourcesText);
        $extraCmp = $this->compressExtra($r->compressModel, $extraRaw, 1600);
        $src = $sourcesText ? "\n\n[SOURCES]\n".mb_strimwidth($sourcesText, 0, 2000, " […]") : '';
        $final = $this->injector->inject($final, $extraCmp.$src);

        if (!empty($final['needs_source_but_missing'])) {
            return new SendResult(
                "Per procedere devo avere il testo da modificare/continuare. Incollalo qui (o ripeti l’ultimo output a cui riferirti).",
                [],
                ['id'=>$r->projectId,'path'=>$r->projectPath],
                ['planner_raw'=>$plannerRaw,'final_input'=>$final['debug'] ?? '','need_source'=>true]
            );
        }

        // 5) main model
        $resp = $this->llm->call($r->model, [
            ['role'=>'system','content'=>
                'Se c’è [SOURCE], lavora su quello; altrimenti genera. Rispetta [REQUIREMENTS]. '.
                'Usa [USER_PROFILE]/[MEMORY]/[MEMORY_HINTS]/[CONTEXT] come contesto, non ristamparli. '.
                'Non restituire mai una risposta vuota: se l’input è vago (es. “di nuovo”, “ok”), chiedi una singola domanda di chiarimento o ripeti l’ultimo artefatto coerente. Italiano. '.
                'Evita codice se il formato richiesto non è "code"; per richieste di umorismo rispondi con testo breve e creativo.'
            ],
            ['role'=>'user','content'=>$final['payload'] ?? ''],
        ], ['max_tokens'=>$r->maxTokens,'temperature'=>1]);

        $answer = $resp['text'] ?? 'Nessun contenuto nella risposta API.';
        $in     = (int)($resp['usage']['input']  ?? 0);
        $out    = (int)($resp['usage']['output'] ?? 0);
        $tot    = (int)($resp['usage']['total']  ?? ($in + $out));
        $cost   = $costFn ? (float)$costFn($r->model, $in, $out) : 0.0;

        // 6) save
        $this->history->appendAssistant($r->projectId, $answer, $r->model, $in, $out, $tot, $cost);

        // 7) updater (profilo/memorie) + aggiornamento GLOBAL/PROJECT
        $compressUsage = null;
        if ($r->auto) {
            $frame = [
                'theme'=>$plan->theme,'genre'=>$plan->genre,'format'=>$plan->format,
                'prefs'=>['style'=>$plan->style,'avoid'=>$plan->avoid],
                'language'=>$plan->language,'length'=>$plan->length,
            ];
            try {
                $upd = $this->updater->update(
                    $r->compressModel, $r->projectId, $r->userId ?: null, $frame,
                    $userProfileJson, $projectMemoryJson, $r->prompt, $answer
                );
                $compressUsage = $upd['compress_usage'] ?? null;
            } catch (\Throwable $e) {
                \Log::warning('PostTurnUpdater fallito', ['err'=>$e->getMessage()]);
            }

            // 7.b) GLOBAL & PROJECT context (sempre)
            try { $this->globalCtx->updateFromTurn($userId, $r->prompt, $answer); }
            catch (\Throwable $e) { \Log::warning('GlobalContext update fallito', ['err'=>$e->getMessage()]); }

            try { $this->updateProjectContext($r->compressModel, $project, $pShort, $pState, $r->prompt, $answer); }
            catch (\Throwable $e) { \Log::warning('ProjectContext update fallito', ['err'=>$e->getMessage()]); }
        }

        return new SendResult(
            $answer,
            ['input'=>$in,'output'=>$out,'total'=>$tot,'cost'=>$cost,'model'=>$r->model,'compress'=>$compressUsage],
            ['id'=>$r->projectId,'path'=>$r->projectPath],
            ['planner_raw'=>$plannerRaw,'final_input'=>$final['debug'] ?? '','need_source'=>false]
        );
    }

    // ============== Helpers privati ==============

    private function renderSources(array $chunks): string
    {
        $lines = [];
        foreach ($chunks as $i => $c) {
            $head = "SOURCE #{$i} (type={$c['type']}; id={$c['id']}; why={$c['why']})";
            $lines[] = $head."\n".trim((string)$c['content']);
        }
        return implode("\n\n", $lines);
    }

    private function renderExtraContext(array $gState, string $gShort, array $pState, string $pShort, string $sources): string
    {
        // Comprimi solo GLOBAL/PROJECT; le SOURCES le terremo a parte
        return
            "[GLOBAL_STATE]\n".json_encode($gState, JSON_UNESCAPED_UNICODE)
            ."\n\n[GLOBAL_SUMMARY]\n".$gShort
            ."\n\n[PROJECT_STATE]\n".json_encode($pState, JSON_UNESCAPED_UNICODE)
            ."\n\n[PROJECT_SUMMARY]\n".$pShort;
    }

    private function compressExtra(string $compressModel, string $extra, int $targetTokens): string
    {
        $resp = $this->llm->call($compressModel, [
            ['role'=>'system','content'=>"Sei un compressore di contesto. Mantieni SOLO policy/fatti/decisioni utili. Target {$targetTokens} token. Rispondi con testo secco, senza preamboli."],
            ['role'=>'user','content'=>$extra],
        ], ['max_tokens'=>$targetTokens,'temperature'=>0]);
        return (string)($resp['text'] ?? $extra);
    }

    private function normalizeProjectState($state): array
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

    private function updateProjectContext(string $compressModel, ?Project $project, string $prevShort, array $prevState, string $u, string $a): void
    {
        if (!$project) return;

        // Short summary
        $sum = $this->llm->call($compressModel, [
            ['role'=>'system','content'=>'Running summary PROGETTO (10–20 righe): decisioni, vincoli, next.'],
            ['role'=>'user','content'=>"PREV:\n{$prevShort}\n\nUSER:\n{$u}\n\nASSISTANT:\n{$a}"],
        ], ['max_tokens'=>400,'temperature'=>0]);
        $newShort = $sum['text'] ?? $prevShort;

        // State JSON
        $schema = json_encode([
            'obiettivi'=>[],'vincoli'=>[],'decisioni'=>[],'file_toccati'=>[],
            'schema_db'=>[],'todo_aperti'=>[],'assunzioni'=>[],'glossario'=>[]
        ], JSON_UNESCAPED_UNICODE);
        $st = $this->llm->call($compressModel, [
            ['role'=>'system','content'=>"Dato stato JSON del PROGETTO e ultimo scambio, restituisci SOLO JSON valido con le stesse chiavi. Schema: {$schema}"],
            ['role'=>'user','content'=>"PREV_STATE:\n".json_encode($prevState, JSON_UNESCAPED_UNICODE)."\n\nUSER:\n{$u}\n\nASSISTANT:\n{$a}"],
        ], ['max_tokens'=>700,'temperature'=>0]);
        $newState = json_decode($st['text'] ?? '', true);
        if (!is_array($newState)) $newState = $prevState;

        $project->short_summary = $newShort;
        $project->thread_state  = $newState;
        $project->save();
    }
}
