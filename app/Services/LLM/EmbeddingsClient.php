<?php
declare(strict_types=1);

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;

class EmbeddingsClient
{
    public function __construct(
        private ?string $apiKey = null,
        private string $base = 'https://api.openai.com',
        private string $model = 'text-embedding-3-small' // snello ed economico
    ){
        $this->apiKey ??= env('OPENAI_API_KEY');
        $envModel = env('EMBEDDINGS_MODEL');
        if ($envModel) $this->model = preg_replace('#^openai:#','',$envModel);
    }

    public function embed(string $text): array
    {
        $resp = Http::withToken($this->apiKey)
            ->post(rtrim($this->base,'/').'/v1/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ])->throw()->json();

        return $resp['data'][0]['embedding'] ?? [];
    }
}
