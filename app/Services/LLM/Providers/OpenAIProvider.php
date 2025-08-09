<?php
namespace App\Services\LLM\Providers;

use App\Services\LLM\Provider;
use Illuminate\Support\Facades\Http;

// App/Services/LLM/Providers/OpenAIProvider.php

class OpenAIProvider implements Provider {
  public function chat(array $messages, array $opts = []): array {
    $modelId = $opts['model'] ?? 'openai:gpt-5';
    $model   = str_contains($modelId, ':') ? explode(':',$modelId,2)[1] : $modelId;

    $base = rtrim(config('llm.providers.openai.base_url'),'/');
    $key  = config('llm.providers.openai.api_key');

    $isO3 = preg_match('/^o3($|-)/i', $model) === 1;

    if ($isO3) {
      // === Responses API per o3 / o3-pro ===
      $payload = array_filter([
        'model'             => $model,
        // mappo i tuoi messages nel formato "input"
        'input'             => array_map(fn($m) => [
                                  'role'    => $m['role'],
                                  'content' => $m['content'],
                                ], $messages),
        'reasoning'         => ['effort' => $opts['reasoning_effort'] ?? 'medium'],
        'max_output_tokens' => $opts['max_tokens'] ?? null,
        'temperature'       => $opts['temperature'] ?? 1,
      ], fn($v) => !is_null($v));

      $res = Http::withToken($key)->acceptJson()->asJson()
              ->timeout(90)
              ->post($base.'/responses', $payload);

      if (!$res->successful()) {
        $msg = $res->json('error.message') ?? ('HTTP '.$res->status());
        throw new \RuntimeException("OpenAI: $msg");
      }

      $data = $res->json();

      // testo: prima output_text (shortcut), poi estraggo dai blocchi
      $text = data_get($data, 'output_text');
      if (!$text) {
        $text = collect((array) data_get($data, 'output', []))
                  ->flatMap(fn($o) => collect($o['content'] ?? [])->pluck('text'))
                  ->filter()->implode("\n");
      }

      $in  = (int) (data_get($data,'usage.input_tokens')  ?? 0);
      $out = (int) (data_get($data,'usage.output_tokens') ?? 0);
      $tot = (int) (data_get($data,'usage.total_tokens')  ?? ($in+$out));

      return ['text'=>$text,'usage'=>['input'=>$in,'output'=>$out,'total'=>$tot],'model'=>$modelId];
    }

    // === Chat Completions per gli altri modelli ===
    $res = Http::withToken($key)->acceptJson()->asJson()
      ->timeout(90)
      ->post($base.'/chat/completions', array_filter([
        'model'                  => $model,
        'messages'               => $messages,
        'max_completion_tokens'  => $opts['max_tokens'] ?? null,
        'temperature'            => $opts['temperature'] ?? 1,
        // niente "reasoning" qui: non è supportato
      ], fn($v) => !is_null($v)));

    if (!$res->successful()) {
      $msg = $res->json('error.message') ?? ('HTTP '.$res->status());
      throw new \RuntimeException("OpenAI: $msg");
    }

    $data = $res->json();
    $text = data_get($data,'choices.0.message.content') ?? data_get($data,'choices.0.text') ?? '';
    $in   = (int)(data_get($data,'usage.prompt_tokens')     ?? data_get($data,'usage.input_tokens')  ?? 0);
    $out  = (int)(data_get($data,'usage.completion_tokens') ?? data_get($data,'usage.output_tokens') ?? 0);
    $tot  = (int)(data_get($data,'usage.total_tokens')      ?? ($in+$out));

    return ['text'=>$text,'usage'=>['input'=>$in,'output'=>$out,'total'=>$tot],'model'=>$modelId];
  }
}

