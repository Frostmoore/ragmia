<?php
declare(strict_types=1);

namespace App\Observers;

use App\Models\Message;
use App\Jobs\CreateMessageEmbedding;

class MessageObserver
{
    public function created(Message $message): void
    {
        if (!trim((string)$message->content)) return;
        // Metti in coda la creazione dell'embedding (non blocca la risposta)
        CreateMessageEmbedding::dispatch($message->id);
    }
}
