<?php
// app/Services/Chat/MemoryService.php
namespace App\Services\Chat;

use App\Models\Memory;
use App\Models\UserMemory;

class MemoryService
{
    public function getProjectMemoryJson(int $projectId): string
    {
        $s = Memory::where('project_id',$projectId)->where('kind','summary')
            ->orderByDesc(\DB::raw('COALESCE(updated_at, created_at, id)'))
            ->value('content') ?? '';
        $s = trim((string)$s);
        return $this->isPlaceholder($s) ? '' : $s;
    }

    public function saveProjectMemoryJson(int $projectId, string $json): void
    {
        Memory::updateOrCreate(
            ['project_id'=>$projectId,'kind'=>'summary'],
            ['content'=>$json]
        );
    }

    public function getUserProfileJson(?int $userId): string
    {
        if (!$userId) return '';
        $row = UserMemory::firstOrCreate(['user_id'=>$userId,'kind'=>'profile'], ['content'=>[]]);
        $c = is_array($row->content) ? json_encode($row->content, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$row->content;
        return trim((string)$c);
    }

    public function saveUserProfileJson(int $userId, string $json): void
    {
        UserMemory::updateOrCreate(['user_id'=>$userId,'kind'=>'profile'], ['content'=>$json]);
    }

    public function decode(?string $json): array {
        $json = trim((string)$json);
        $arr = json_decode($json, true);
        if (is_array($arr)) return $arr;
        if (preg_match('/\{.*\}/s', $json, $m)) {
            $arr = json_decode($m[0], true);
            if (is_array($arr)) return $arr;
        }
        return [];
    }

    private function isPlaceholder(string $s): bool
    {
        $t = mb_strtolower(trim($s));
        return $t === '[memory corrente]' || $t === '—' || $t === '-';
    }
}
