<?php
declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\GlobalContext;
use App\Services\LLM\LlmClient;

final class GlobalContextService
{
    public function __construct(private LlmClient $llm) {}

    public function get(int $userId = 0): array
    {
        $gc = GlobalContext::firstOrCreate(['user_id' => $userId ?: null], [
            'global_state' => $this->bootstrap(),
            'global_short_summary' => '',
        ]);
        $state = is_array($gc->global_state)
            ? $gc->global_state
            : (json_decode((string)$gc->global_state, true) ?: []);
        $state += $this->bootstrap();
        return ['model'=>$gc, 'state'=>$state, 'short'=>(string)($gc->global_short_summary ?? '')];
    }

    public function updateFromTurn(int $userId, string $userText, string $assistantText): void
    {
        $gc = GlobalContext::firstOrCreate(['user_id' => $userId ?: null], [
            'global_state' => $this->bootstrap(),
            'global_short_summary' => '',
        ]);
        $prevShort = (string)($gc->global_short_summary ?? '');
        $prevState = is_array($gc->global_state)
            ? $gc->global_state
            : (json_decode((string)$gc->global_state, true) ?: []);

        $gc->global_short_summary = $this->summarizeShort($prevShort, $userText, $assistantText);
        $delta = $this->extractGlobalDelta($userText, $assistantText);
        $gc->global_state = $this->merge($prevState, $delta);
        $gc->save();
    }

    private function bootstrap(): array
    {
        return [
            'policy' => [
                'ask_if_missing_context' => true,
                'never_invent' => true,
                'default_language' => 'it',
            ],
            'style' => [
                'tone' => 'diretto, colloquiale, sarcasmo ok',
                'code_rules' => 'usa diff unificato quando tocchi codice',
            ],
            'stack' => ['php'=>'8.3.21', 'laravel'=>'12.x'],
            'convenzioni' => [],
            'glossario' => [],
            'endpoints' => [],
            'naming' => [],
            'todo_globali' => [],
            'decisioni_globali' => [],
        ];
    }

    private function summarizeShort(string $prevShort, string $u, string $a): string
    {
        $m = env('COMPRESS_MODEL','openai:gpt-4o-mini');
        $resp = $this->llm->call($m, [
            ['role'=>'system','content'=>'Running summary GLOBALE (10–20 righe): norme e decisioni trasversali.'],
            ['role'=>'user','content'=>"PREV:\n{$prevShort}\n\nUSER:\n{$u}\n\nASSISTANT:\n{$a}"],
        ], ['max_tokens'=>400,'temperature'=>0]);
        return $resp['text'] ?? $prevShort;
    }

    private function extractGlobalDelta(string $u, string $a): array
    {
        $m = env('COMPRESS_MODEL','openai:gpt-4o-mini');
        $schema = json_encode([
            'policy'=>[], 'style'=>[], 'stack'=>[], 'convenzioni'=>[],
            'glossario'=>[], 'endpoints'=>[], 'naming'=>[],
            'todo_globali'=>[], 'decisioni_globali'=>[],
        ], JSON_UNESCAPED_UNICODE);
        $resp = $this->llm->call($m, [
            ['role'=>'system','content'=>"Estrai SOLO decisioni GLOBALI. Ritorna JSON merge-abile conforme allo schema: {$schema}"],
            ['role'=>'user','content'=>"USER:\n{$u}\n\nASSISTANT:\n{$a}"],
        ], ['max_tokens'=>600,'temperature'=>0]);
        $json = json_decode($resp['text'] ?? '', true);
        return is_array($json) ? $json : [];
    }

    private function merge(array $prev, array $delta): array
    {
        foreach ($delta as $k=>$v) {
            if (is_array($v) && isset($prev[$k]) && is_array($prev[$k])) {
                $prev[$k] = array_replace_recursive($prev[$k], $v);
            } else { $prev[$k] = $v; }
        }
        return $prev;
    }
}
