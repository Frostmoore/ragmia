<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Message;
use App\Models\MessageEmbedding;
use App\Services\LLM\EmbeddingsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CreateMessageEmbedding implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $messageId) {}

    public function handle(EmbeddingsClient $emb): void
    {
        $msg = Message::find($this->messageId);
        if (!$msg || !$msg->content) return;

        // evita duplicati
        if (MessageEmbedding::where('message_id', $msg->id)->exists()) return;

        $vec = $emb->embed($msg->content);
        if (!$vec) return;

        MessageEmbedding::create([
            'message_id' => $msg->id,
            'embedding'  => $vec,
        ]);
    }
}
