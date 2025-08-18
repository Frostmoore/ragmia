<?php
namespace App\Services\LLM\Providers;

use App\Services\LLM\Provider;
use Illuminate\Support\Facades\Http;

// app/Services/LLM/Providers/OpenAIProvider.php
class OpenAIProvider implements Provider
{
    public function chat(array $messages, array $opts = []): array
    {
        $modelId = $opts['model'] ?? 'openai:gpt-5';
        $model   = str_contains($modelId, ':') ? explode(':', $modelId, 2)[1] : $modelId;

        $base = rtrim(config('llm.providers.openai.base_url'), '/');
        $key  = config('llm.providers.openai.api_key');

        // Responses API per o3* e gpt-5* (niente temperature!)
        $isResponses = preg_match('/^(o3|gpt-5)(?:$|[-.:])/', $model) === 1;

        if ($isResponses) {
            // ⚠️ Responses NON accetta 'temperature'. Usa max_output_tokens.
            $payload = array_filter([
                'model'             => $model,
                // Responses API: 'input' come array di messaggi role/content va bene
                'input'             => array_map(
                    fn ($m) => ['role' => $m['role'], 'content' => $m['content']],
                    $messages
                ),
                'reasoning'         => ['effort' => $opts['reasoning_effort'] ?? 'medium'],
                'max_output_tokens' => $opts['max_tokens'] ?? null,
                // 'temperature' => …  // ❌ rimosso
            ], fn ($v) => !is_null($v));

            $res = Http::withToken($key)->acceptJson()->asJson()
                ->timeout(90)
                ->post($base . '/responses', $payload);

            if (!$res->successful()) {
                $msg = $res->json('error.message') ?? ('HTTP ' . $res->status());
                throw new \RuntimeException("OpenAI: $msg");
            }

            $data = $res->json();

            // Testo: preferisci output_text, poi raccogli blocchi
            $text = trim((string) (data_get($data, 'output_text') ?? ''));
            if ($text === '') {
                $text = collect((array) data_get($data, 'output', []))
                    ->flatMap(fn ($o) => collect($o['content'] ?? [])->pluck('text'))
                    ->filter()
                    ->implode("\n");
                $text = trim($text);
            }

            $in  = (int) (data_get($data, 'usage.input_tokens')  ?? 0);
            $out = (int) (data_get($data, 'usage.output_tokens') ?? 0);
            $tot = (int) (data_get($data, 'usage.total_tokens')  ?? ($in + $out));

            return [
                'text'  => $text,
                'usage' => ['input' => $in, 'output' => $out, 'total' => $tot],
                'model' => $modelId,
            ];
        }

        // ===== Chat Completions (gpt-4o-mini, gpt-4.1-mini/nano, ecc.) =====
        $payload = array_filter([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $opts['max_tokens'] ?? null,
            'temperature' => $opts['temperature'] ?? 1,
        ], fn ($v) => !is_null($v));

        $res = Http::withToken($key)->acceptJson()->asJson()
            ->timeout(90)
            ->post($base . '/chat/completions', $payload);

        if (!$res->successful()) {
            $msg = $res->json('error.message') ?? ('HTTP ' . $res->status());
            throw new \RuntimeException("OpenAI: $msg");
        }

        $data = $res->json();

        // Estrazione testo tollerante
        $text = data_get($data, 'choices.0.message.content');
        if ($text === null || $text === '') {
            $text = data_get($data, 'choices.0.text', '');
            if ($text === '' && is_array(data_get($data, 'choices.0.message.content'))) {
                $text = collect(data_get($data, 'choices.0.message.content'))
                    ->pluck('text')
                    ->filter()
                    ->implode("\n");
            }
        }
        $text = trim((string) $text);

        $in  = (int) (data_get($data, 'usage.prompt_tokens')     ?? data_get($data, 'usage.input_tokens')  ?? 0);
        $out = (int) (data_get($data, 'usage.completion_tokens') ?? data_get($data, 'usage.output_tokens') ?? 0);
        $tot = (int) (data_get($data, 'usage.total_tokens')      ?? ($in + $out));

        return [
            'text'  => $text,
            'usage' => ['input' => $in, 'output' => $out, 'total' => $tot],
            'model' => $modelId,
        ];
    }
}
