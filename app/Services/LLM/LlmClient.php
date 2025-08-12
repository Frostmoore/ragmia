<?php
// app/Services/LLM/LlmClient.php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Provider;
use App\Services\LLM\Providers\OpenAIProvider;
use App\Services\LLM\Providers\AnthropicProvider;
use App\Services\LLM\Providers\GoogleProvider;
use Illuminate\Support\Facades\Log;

/**
 * LlmClient
 *
 * Router/adapter unico sopra i tuoi Provider concreti.
 * Usa il prefix del modello per scegliere il provider:
 *  - openai:gpt-5, openai:o3, openai:o3-pro         -> OpenAIProvider
 *  - anthropic:claude-3-5-sonnet, ...               -> AnthropicProvider
 *  - google:gemini-1.5-flash, ...                   -> GoogleProvider
 *
 * Ritorna sempre:
 * [
 *   'text'  => string,                                // testo generato (mai null)
 *   'usage' => ['input'=>int,'output'=>int,'total'=>int],
 *   'model' => string,                                // id modello richiesto (prefissato)
 * ]
 */
class LlmClient
{
    /**
     * @var array<string,Provider>
     */
    protected array $providers = [];

    /**
     * @param array<string,Provider>|null $providers  mappa facoltativa { 'openai' => Provider, ... }
     */
    public function __construct(?array $providers = null)
    {
        // Se non iniettato, inizializza con i tuoi provider
        $this->providers = $providers ?? [
            'openai'    => new OpenAIProvider(),
            'anthropic' => new AnthropicProvider(),
            'google'    => new GoogleProvider(),
        ];
    }

    /**
     * Registra/sovrascrive un provider a runtime.
     */
    public function registerProvider(string $key, Provider $provider): void
    {
        $this->providers[strtolower($key)] = $provider;
    }

    /**
     * Chiamata unica per ottenere una risposta dal modello desiderato.
     *
     * @param  string $modelId  es. "openai:gpt-5" | "anthropic:claude-3-5-sonnet" | "google:gemini-1.5-flash"
     *                           Se il prefix manca, usa config('llm.default_provider','openai').
     * @param  array  $messages es. [['role'=>'system','content'=>'...'],['role'=>'user','content'=>'...']]
     * @param  array  $opts     opzioni pass-through per il provider (max_tokens, temperature, reasoning_effort, ecc.)
     * @return array{ text:string, usage: array{input:int, output:int, total:int}, model:string }
     */
    public function call(string $modelId, array $messages, array $opts = []): array
    {
        [$providerKey, $normalizedModelId] = $this->resolveProviderAndModel($modelId, $opts);

        // Provider o fallback openai
        $provider = $this->providers[$providerKey] ?? ($this->providers['openai'] ?? null);
        if (!$provider) {
            throw new \RuntimeException("Provider '{$providerKey}' non registrato e nessun fallback disponibile.");
        }

        // i Provider tuoi si aspettano sempre opts['model']
        $opts = ['model' => $normalizedModelId] + $opts;

        // util per normalizzare output provider
        $normalize = function (array $out): array {
            $text  = trim((string)($out['text'] ?? ''));
            $usage = (array)($out['usage'] ?? []);
            $in    = (int)($usage['input']  ?? 0);
            $outT  = (int)($usage['output'] ?? 0);
            $tot   = (int)($usage['total']  ?? ($in + $outT));
            return [$text, ['input'=>$in, 'output'=>$outT, 'total'=>$tot]];
        };

        try {
            $res = $provider->chat($messages, $opts);
            [$text, $usage] = $normalize($res);

            if ($text === '') {
                // Retry 1, guidato per evitare ancora vuoto
                $retryMessages = $messages;
                $retryMessages[] = [
                    'role'    => 'system',
                    'content' => 'La risposta precedente è risultata vuota. Fornisci ora un output NON VUOTO in italiano: almeno un paragrafo (≥20 parole). Se l’input è ambiguo (es. “di nuovo”), fai UNA sola domanda di chiarimento. Non restituire mai una stringa vuota.'
                ];

                $retryOpts = $opts;
                $retryOpts['max_tokens']  = min((int)($opts['max_tokens'] ?? 512), 512);
                $retryOpts['temperature'] = 0.2;

                try {
                    $retry = $provider->chat($retryMessages, $retryOpts);
                    [$retryText, $retryUsage] = $normalize($retry);

                    if ($retryText !== '') {
                        // somma usage tra primo tentativo e retry
                        $usage = [
                            'input'  => (int)$usage['input']  + (int)$retryUsage['input'],
                            'output' => (int)$usage['output'] + (int)$retryUsage['output'],
                            'total'  => (int)$usage['total']  + (int)$retryUsage['total'],
                        ];
                        return ['text' => $retryText, 'usage' => $usage, 'model' => $normalizedModelId, '_retry' => true];
                    }
                } catch (\Throwable $e) {
                    // se il retry fallisce, logga ma non interrompere
                    $this->logProviderError($providerKey, $normalizedModelId, $e);
                }

                // fallback finale esplicito
                return ['text' => 'Risposta vuota.', 'usage' => $usage, 'model' => $normalizedModelId];
            }

            // risposta normale
            return ['text' => $text, 'usage' => $usage, 'model' => $normalizedModelId];

        } catch (\Throwable $e) {
            $this->logProviderError($providerKey, $normalizedModelId, $e);
            throw new \RuntimeException("{$this->prettyProvider($providerKey)}: " . $e->getMessage(), previous: $e);
        }
    }


    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Torna una coppia [providerKey, normalizedModelId].
     * Se manca il prefix nel modello, usa default_provider da config (openai di default).
     * Permette override con $opts['force_provider'].
     */
    protected function resolveProviderAndModel(string $modelId, array $opts): array
    {
        $force = strtolower((string) ($opts['force_provider'] ?? ''));
        if ($force !== '' && isset($this->providers[$force])) {
            // Normalizza il modelId aggiungendo il prefix forzato se manca
            $normalized = str_contains($modelId, ':') ? $modelId : "{$force}:{$modelId}";
            return [$force, $normalized];
        }

        if (str_contains($modelId, ':')) {
            [$prov, $rest] = explode(':', $modelId, 2);
            return [strtolower($prov), "{$prov}:{$rest}"];
        }

        $defaultProv = strtolower((string) config('llm.default_provider', 'openai'));
        if (!isset($this->providers[$defaultProv])) {
            $defaultProv = 'openai';
        }
        return [$defaultProv, "{$defaultProv}:{$modelId}"];
    }

    protected function prettyProvider(string $key): string
    {
        return match (strtolower($key)) {
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic',
            'google'    => 'Google',
            default     => ucfirst($key),
        };
    }

    protected function logProviderError(string $providerKey, string $modelId, \Throwable $e): void
    {
        try {
            Log::warning(sprintf(
                '[LlmClient] Provider error (%s, model=%s): %s',
                $providerKey,
                $modelId,
                $e->getMessage()
            ));
        } catch (\Throwable) {
            // no-op
        }
    }
}
