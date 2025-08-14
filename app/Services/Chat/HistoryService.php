<?php
// app/Services/Chat/HistoryService.php
namespace App\Services\Chat;

use App\Models\Message;

class HistoryService
{
    /** @return array<int,array{role:string,content:string}> */
    public function recent(int $projectId, int $limit = 30): array
    {
        return Message::where('project_id', $projectId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')->limit($limit)->get(['role','content'])
            ->reverse()->values()
            ->map(fn($m) => ['role' => $m->role, 'content' => (string) $m->content])
            ->all();
    }

    public function appendUser(int $projectId, string $content): void
    {
        Message::create([
            'project_id' => $projectId,
            'user_id'    => auth()->id(),
            'role'       => 'user',
            'content'    => $content,
        ]);
    }

    public function appendAssistant(
        int $projectId,
        string $content,
        string $model,
        int $in,
        int $out,
        int $total,
        float $cost
    ): void {
        Message::create([
            'project_id'   => $projectId,
            'user_id'      => auth()->id(),
            'role'         => 'assistant',
            'content'      => $content,
            'tokens_input' => $in,
            'tokens_output'=> $out,
            'tokens_total' => $total,
            'cost_usd'     => $cost,
            'model'        => $model,
        ]);
    }
}
