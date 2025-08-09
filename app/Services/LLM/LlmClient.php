<?php
namespace App\Services\LLM;

use Illuminate\Support\Str;

class LlmClient {
    public static function forModel(string $modelId): Provider {
        [$provider] = Str::contains($modelId, ':') ? explode(':', $modelId, 2) : ['openai'];
        return match ($provider) {
            'anthropic' => new Providers\AnthropicProvider(),
            'google'    => new Providers\GoogleProvider(),
            default     => new Providers\OpenAIProvider(),
        };
    }
}
