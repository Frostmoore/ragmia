<?php
namespace App\Services\LLM\Providers;

use App\Services\LLM\Provider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleProvider implements Provider {
    public function chat(array $messages, array $opts = []): array {
        $modelId = $opts['model'] ?? 'google:gemini-1.5-flash';
        $model   = str_contains($modelId, ':') ? explode(':',$modelId,2)[1] : $modelId;

        $base = rtrim(config('llm.providers.google.base_url'),'/');
        $key  = config('llm.providers.google.api_key');

        // Gemini expects contents: array di {role:"user","parts":[{"text":"..."}]}
        // Facciamo un collapse semplice: concateniamo system+user+assistant in un testo unico "chat-style".
        $system = collect($messages)->firstWhere('role','system')['content'] ?? '';
        $chat   = collect($messages)->reject(fn($m)=>$m['role']==='system')->map(function($m){
            $who = $m['role']==='assistant' ? 'Assistant' : 'User';
            return "{$who}: ".$m['content'];
        })->implode("\n");

        $userText = trim(($system ? "System: $system\n\n" : '').$chat);

        $url = "{$base}/models/{$model}:generateContent?key={$key}";
        $payload = [
            'contents' => [[ 'role'=>'user', 'parts'=> [['text' => $userText]] ]],
            'generationConfig' => [
                'temperature' => $opts['temperature'] ?? 0.7,
                'maxOutputTokens' => $opts['max_tokens'] ?? 1000,
            ],
        ];

        $res = Http::acceptJson()->asJson()->timeout(90)->post($url, $payload);

        if (!$res->successful()) {
            $msg = data_get($res->json(),'error.message') ?? ('HTTP '.$res->status());
            throw new \RuntimeException("Google: $msg");
        }

        $data = $res->json();
        $text = data_get($data,'candidates.0.content.parts.0.text') ?? '';

        // usageMetadata è facoltativo
        $in  = (int) (data_get($data,'usageMetadata.promptTokenCount')     ?? 0);
        $out = (int) (data_get($data,'usageMetadata.candidatesTokenCount') ?? 0);
        $tot = (int) (data_get($data,'usageMetadata.totalTokenCount')      ?? ($in+$out));

        return ['text'=>$text,'usage'=>['input'=>$in,'output'=>$out,'total'=>$tot],'model'=>$modelId];
    }
}
