<?php
declare(strict_types=1);

namespace App\Services\Chat\Send;

final class SendRequest
{
    public function __construct(
        public int    $projectId,
        public string $projectPath,
        public int    $userId,
        public string $prompt,
        public bool   $auto,
        public string $model,
        public string $compressModel,
        public int    $maxTokens,
        public bool   $useRawUser = false,
    ) {}
}
