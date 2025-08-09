<?php
namespace App\Services\LLM\Providers;

use App\Services\LLM\Provider;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements Provider {

    private function mapModel(string $name): string
    {
        return match ($name) {
            // alias friendly → ufficiali
            'claude-3-5-haiku'  => 'claude-3-5-haiku-latest',
            'claude-3-5-sonnet' => 'claude-3-5-sonnet-latest',

            // vecchie 3.x con data
            'claude-3-opus'     => 'claude-3-opus-20240229',
            'claude-3-sonnet'   => 'claude-3-sonnet-20240229',
            'claude-3-haiku'    => 'claude-3-haiku-20240307',

            default             => $name,
        };
    }

    public function chat(array $messages, array $opts = []): array {
        $modelId = $opts['model'] ?? 'anthropic:claude-3-5-haiku';
        $model   = str_contains($modelId, ':') ? explode(':',$modelId,2)[1] : $modelId;
        $model   = $this->mapModel($model);   // <-- ✨ qui

        $base = rtrim(config('llm.providers.anthropic.base_url'),'/');
        $key  = config('llm.providers.anthropic.api_key');
        $ver  = config('llm.providers.anthropic.version','2023-06-01');

        $system   = '';
        $userText = '';
        foreach ($messages as $m) {
            if ($m['role']==='system') $system .= ($system? "\n":"").$m['content'];
        }
        $nonSystem = array_filter($messages, fn($m) => $m['role']!=='system');
        foreach ($nonSystem as $m) {
            $prefix = $m['role']==='assistant' ? "Assistant: " : "User: ";
            $userText .= $prefix.$m['content']."\n";
        }

        $payload = [
            'model'   => $model,
            'system'  => $system ?: null,
            'messages'=> [
                ['role'=>'user','content'=>[['type'=>'text','text'=>trim($userText)]]]
            ],
            'max_tokens' => $opts['max_tokens'] ?? 1000,
            'temperature'=> $opts['temperature'] ?? 0.7,
        ];

        $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => $ver,
                'content-type' => 'application/json',
            ])->timeout(90)->post($base.'/v1/messages', $payload);

        if (!$res->successful()) {
            // migliora l’errore: mostra messaggio o body
            $msg = data_get($res->json(),'error.message') ?? $res->body() ?? ('HTTP '.$res->status());
            throw new \RuntimeException("Anthropic: $msg");
        }

        $data = $res->json();
        $text = collect($data['content'] ?? [])
                    ->where('type','text')->pluck('text')->implode("\n");

        $in  = (int) (data_get($data,'usage.input_tokens')  ?? 0);
        $out = (int) (data_get($data,'usage.output_tokens') ?? 0);
        $tot = $in + $out;

        return ['text'=>$text,'usage'=>['input'=>$in,'output'=>$out,'total'=>$tot],'model'=>$modelId];
    }
}
