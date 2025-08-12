<?php
declare(strict_types=1);

namespace App\Services\Chat\Send;

use App\DTOs\Plan;
use App\Services\LLM\LlmClient;
use App\Services\Chat\HistoryService;
use App\Services\Chat\MemoryService;
use App\Services\Chat\MemoryMerger;
use App\Services\Chat\PlannerService;
use App\Services\Chat\PromptBuilder;
use App\Services\Chat\PostTurnUpdater;

/**
 * Coordina l'intero turno di /send in step piccoli e testabili.
 * Dipendenze opzionali:
 *  - PreProfileService  (ensureBeforeTurn)
 *  - ContextHints       (addHints / getHintsForPrompt / renderForPrompt)
 */
class SendCoordinator
{
    public function __construct(
        private LlmClient      $llm,
        private HistoryService $history,
        private MemoryService  $mem,
        private MemoryMerger   $merger,
        private PlannerService $planner,
        private PromptBuilder  $builder,
        private PostTurnUpdater $updater,
        private ?\App\Services\Chat\PreProfileService $preProfile = null,
        private ?\App\Services\Chat\ContextHints      $hints      = null,
    ) {}

    public function handle(SendRequest $r, ?callable $costFn = null): SendResult
    {
        // 0) append user
        $this->history->appendUser($r->projectId, $r->prompt);

        // 1) memorie (come prima)
        $projectMemoryJson = $r->auto ? $this->mem->getProjectMemoryJson($r->projectId) : '';
        $userProfileJson   = ($r->auto && $r->userId) ? $this->mem->getUserProfileJson($r->userId) : '';
        if ($r->auto && $r->userId && $this->preProfile) {
            $userProfileJson = $this->preProfile->ensureBeforeTurn($r->compressModel, $r->userId, $r->prompt, $userProfileJson);
        }
        $projectMemArr  = $this->mem->decode($projectMemoryJson);
        $userProfileArr = $this->mem->decode($userProfileJson);

        // 2) history
        $historyArr = $this->history->recent($r->projectId, 30);

        // 3) planner
        $plan = new \App\DTOs\Plan();
        $plannerRaw = '';
        if ($r->auto) {
            try {
                $plan = $this->planner->plan($r->compressModel, $userProfileJson, $projectMemoryJson, $historyArr, $r->prompt);
                $plannerRaw = $plan->raw;
            } catch (\Throwable $e) {
                \Log::warning('Planner fallito', ['err' => $e->getMessage()]);
            }
        }
        if (!$plan->final_user) $plan->final_user = $r->prompt;

        // 👇 NUOVO: se richiesto, usa SEMPRE il testo utente raw nel blocco [USER]
        if ($r->useRawUser) {
            $plan->final_user = $r->prompt;
        }

        // 3b) merge memorie (identico)
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

        // 3c) hints (come già fatto)
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
                $topHints = $this->hints->getHintsForPrompt($r->userId ?: null, $r->projectId, $r->prompt, $planArr, 12);
                $memoryHintsText = $this->hints->renderForPrompt($topHints);
            } catch (\Throwable $e) {
                \Log::warning('ContextHints failure', ['err' => $e->getMessage()]);
            }
        }

        // 4) payload (identico, ma con $memoryHintsText se il tuo builder lo supporta)
        $sourceText = $this->builder->determineSourceText($plan, $historyArr);
        $final      = $this->builder->buildFinalPayload($plan, $userProfileJson, $projectMemoryJson, $sourceText, $memoryHintsText);

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
        $in  = (int)($resp['usage']['input']  ?? 0);
        $out = (int)($resp['usage']['output'] ?? 0);
        $tot = (int)($resp['usage']['total']  ?? ($in + $out));
        $cost= $costFn ? (float)$costFn($r->model, $in, $out) : 0.0;

        // 6) save
        $this->history->appendAssistant($r->projectId, $answer, $r->model, $in, $out, $tot, $cost);

        // 7) updater
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
        }

        return new SendResult(
            $answer,
            ['input'=>$in,'output'=>$out,'total'=>$tot,'cost'=>$cost,'model'=>$r->model,'compress'=>$compressUsage],
            ['id'=>$r->projectId,'path'=>$r->projectPath],
            ['planner_raw'=>$plannerRaw,'final_input'=>$final['debug'] ?? '','need_source'=>false]
        );
    }
}

