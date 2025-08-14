<?php
namespace App\Console\Commands;

use App\Jobs\CreateMessageEmbedding;
use App\Models\Message;
use Illuminate\Console\Command;

class BackfillEmbeddings extends Command
{
    protected $signature = 'ragmia:backfill-embeddings {projectId?} {--limit=5000}';
    protected $description = 'Crea embeddings per messaggi esistenti';

    public function handle(): int
    {
        $q = Message::query()->orderByDesc('id');
        if ($pid = $this->argument('projectId')) $q->where('project_id', (int)$pid);

        $q->limit((int)$this->option('limit'))->pluck('id')->each(
            fn ($id) => CreateMessageEmbedding::dispatch((int)$id)
        );

        $this->info('Ok, messi in coda.');
        return self::SUCCESS;
    }
}
