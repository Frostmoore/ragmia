<?php
// app/Services/AI/LlmClient.php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LlmClient
 *
 * Adapter unico per chiamare modelli LLM eterogenei.
 * Supporta provider selezionato via prefix nel nome modello, es:
 *  - "openai:gpt-4o-mini"  -> OpenAI Chat Completions
 *  - "anthropic:claude-3-5-sonnet" -> Anthropic Messages
 *
 * Firma di ritorno (standardizzata):
 * [
 *   'text'  => string,
 *   'usage' => ['input'=>int, 'output'=>int, 'total'=>int],
 * ]
 */
class LlmClient
{
    /**
     * Esegue una completion.
     *
     * @param  string $model    es. "openai:gpt-4o-mini" oppure "gpt-4o-mini"
     * @param  array  $messages es. [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
     * @param  array  $opts     chiavi supportate: max_tokens, temperature, top_p, stop (array|string), response_format, tools, tool_choice, extra_headers (array)
     * @return array{ text:string, usage: array{input:int, output:int, total:int} }
     *
     * @throws \RuntimeException su errori HTTP o risposta non valida
     */
    public function call(string $model, array $messages, array $opts = []): array
    {
        [$provider, $plainModel] = $this->parseModel($model);

        return match ($provider) {
            'openai'    => $this->callOpenAI($plainModel, $messages, $opts),
            'anthropic' => $this->callAnthropic($plainModel, $messages, $opts),
            default     => $this->callOpenAI($plainModel ?: $model, $messages, $opts), // fallback OpenAI
        };
    }

    // ---------------------------------------------------------------------
    // OpenAI
    // ---------------------------------------------------------------------

    /**
     * OpenAI Chat Completions API
     * https://api.openai.com/v1/chat/completions
     */
    protected function callOpenAI(string $model, array $messages, array $opts): array
    {
        $apiKey  = (string) env('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY non configurata.');
        }

        $baseUrl = rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $url     = $baseUrl . '/chat/completions';

        $body = [
            'model'    => $model,
            'messages' => $this->normalizeOpenAiMessages($messages),
        ];

        // Pass-through opzioni comuni
        foreach (['max_tokens','temperature','top_p','stop','response_format','tools','tool_choice','logit_bias','presence_penalty','frequency_penalty'] as $k) {
            if (array_key_exists($k, $opts)) {
                $body[$k] = $opts[$k];
            }
        }

        // Timeout e headers extra
        $timeout = (int) ($opts['timeout'] ?? 60);
        $headers = array_merge([
            'Accept'        => 'application/json',
        ], (array) ($opts['extra_headers'] ?? []));

        $resp = Http::withToken($apiKey)
            ->withHeaders($headers)
            ->timeout($timeout)
            ->post($url, $body);

        if (!$resp->successful()) {
            $this->logHttpError('OpenAI', $resp->status(), $resp->body(), $body);
            throw new \RuntimeException("OpenAI error HTTP {$resp->status()}");
        }

        $json = $resp->json();

        $text = (string) Arr::get($json, 'choices.0.message.content', '');
        // In alcuni casi tool_calls -> content può essere null: fallback
        if ($text === '' && ($tc = Arr::get($json, 'choices.0.message.tool_calls'))) {
            $text = json_encode($tc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $usage = $this->mapOpenAiUsage(Arr::get($json, 'usage', []));

        return [
            'text'  => $text !== '' ? $text : 'Risposta vuota.',
            'usage' => $usage,
        ];
    }

    protected function normalizeOpenAiMessages(array $messages): array
    {
        // OpenAI accetta ['role' => 'system|user|assistant|tool', 'content' => '...']
        return array_values(array_map(function ($m) {
            $role    = (string) ($m['role'] ?? 'user');
            $content = (string) ($m['content'] ?? '');
            return ['role' => $role, 'content' => $content];
        }, $messages));
    }

    protected function mapOpenAiUsage(array $u): array
    {
        $in  = (int) ($u['prompt_tokens']     ?? $u['input']  ?? 0);
        $out = (int) ($u['completion_tokens'] ?? $u['output'] ?? 0);
        $tot = (int) ($u['total_tokens']      ?? ($in + $out));
        return ['input' => $in, 'output' => $out, 'total' => $tot];
    }

    // ---------------------------------------------------------------------
    // Anthropic
    // ---------------------------------------------------------------------

    /**
     * Anthropic Messages API
     * https://api.anthropic.com/v1/messages
     */
    protected function callAnthropic(string $model, array $messages, array $opts): array
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY non configurata.');
        }

        $baseUrl = rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/');
        $url     = $baseUrl . '/v1/messages';

        // Anthropic vuole i messaggi senza i "system" (che vanno nel campo system)
        [$system, $chat] = $this->splitSystemForAnthropic($messages);

        $body = [
            'model'       => $model,
            'max_tokens'  => (int) ($opts['max_tokens'] ?? 1024),
            'messages'    => $this->normalizeAnthropicMessages($chat),
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }
        foreach (['temperature','top_p','stop','metadata'] as $k) {
            if (array_key_exists($k, $opts)) $body[$k] = $opts[$k];
        }

        $timeout = (int) ($opts['timeout'] ?? 60);
        $headers = array_merge([
            'Accept'             => 'application/json',
            'x-api-key'          => $apiKey,
            'anthropic-version'  => (string) env('ANTHROPIC_VERSION', '2023-06-01'),
        ], (array) ($opts['extra_headers'] ?? []));

        $resp = Http::withHeaders($headers)->timeout($timeout)->post($url, $body);

        if (!$resp->successful()) {
            $this->logHttpError('Anthropic', $resp->status(), $resp->body(), $body);
            throw new \RuntimeException("Anthropic error HTTP {$resp->status()}");
        }

        $json = $resp->json();

        // content è un array di blocchi [{type:'text', text:'...'}, ...]
        $blocks = (array) Arr::get($json, 'content', []);
        $text = '';
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'text') {
                $text .= (string) ($b['text'] ?? '');
            }
        }

        $usage = $this->mapAnthropicUsage(Arr::get($json, 'usage', []));

        return [
            'text'  => $text !== '' ? $text : 'Risposta vuota.',
            'usage' => $usage,
        ];
    }

    protected function splitSystemForAnthropic(array $messages): array
    {
        $systems = [];
        $rest    = [];

        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            $cnt  = (string) ($m['content'] ?? '');
            if ($role === 'system') {
                $systems[] = $cnt;
            } else {
                $rest[] = $m;
            }
        }
        $system = trim(implode("\n\n", array_filter($systems, fn($s) => trim($s) !== '')));
        return [$system, $rest];
    }

    protected function normalizeAnthropicMessages(array $messages): array
    {
        // Anthropic: role user/assistant, content array di blocchi [{type:'text', text:'...'}]
        $out = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            if ($role !== 'user' && $role !== 'assistant') {
                // tool/system non ammessi qui, li saltiamo
                continue;
            }
            $text = (string) ($m['content'] ?? '');
            $out[] = [
                'role'    => $role,
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        }
        return $out;
    }

    protected function mapAnthropicUsage(array $u): array
    {
        $in  = (int) ($u['input_tokens']  ?? 0);
        $out = (int) ($u['output_tokens'] ?? 0);
        $tot = $in + $out;
        return ['input' => $in, 'output' => $out, 'total' => $tot];
    }

    // ---------------------------------------------------------------------
    // Utils
    // ---------------------------------------------------------------------

    protected function parseModel(string $model): array
    {
        // es: "openai:gpt-4o-mini" -> ['openai','gpt-4o-mini']
        //     "gpt-4o-mini"         -> ['openai','gpt-4o-mini'] (fallback)
        $parts = explode(':', $model, 2);
        if (count($parts) === 2) {
            return [strtolower(trim($parts[0])), trim($parts[1])];
        }
        return ['openai', trim($model)];
    }

    protected function logHttpError(string $provider, int $status, string $body, array $sent): void
    {
        try {
            Log::warning("[LlmClient][$provider] HTTP $status\nResponse: $body\nPayload: " . json_encode($sent, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            // noop
        }
    }
}
