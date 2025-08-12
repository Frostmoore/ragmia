<?php

namespace App\Services\Chat;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContextHintService
{
    /**
     * Upsert di una lista di hint:
     * ogni hint: ['tag'=>'style','value'=>'moderno','weight'=>1.2,'source'=>'planner']
     */
    public function addHints(?int $userId, ?int $projectId, array $hints): void
    {
        $now = now();
        foreach ($hints as $h) {
            $tag   = trim((string)($h['tag'] ?? ''));
            $value = trim((string)($h['value'] ?? ''));
            if ($tag === '' || $value === '') continue;

            $weight = (float)($h['weight'] ?? 1.0);
            $source = (string)($h['source'] ?? 'planner');

            // normalizzazione minima: lower per categorie "stabili"
            $normTag   = $this->normalizeTag($tag);
            $normValue = $this->normalizeValue($normTag, $value);

            // upsert manuale per incrementare counters
            $row = DB::table('user_context_hints')->where([
                'user_id'    => $userId,
                'project_id' => $projectId,
                'tag'        => $normTag,
                'value'      => $normValue,
            ])->first();

            if ($row) {
                DB::table('user_context_hints')->where('id',$row->id)->update([
                    'weight'       => $this->boundedWeight(($row->weight ?? 1.0) * 0.8 + $weight * 0.2),
                    'times_seen'   => (int)($row->times_seen ?? 1) + 1,
                    'last_seen_at' => $now,
                    'source'       => $source ?: ($row->source ?? 'planner'),
                    'updated_at'   => $now,
                ]);
            } else {
                DB::table('user_context_hints')->insert([
                    'user_id'      => $userId,
                    'project_id'   => $projectId,
                    'tag'          => $normTag,
                    'value'        => $normValue,
                    'weight'       => $this->boundedWeight($weight),
                    'times_seen'   => 1,
                    'last_seen_at' => $now,
                    'source'       => $source,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);
            }
        }
    }

    /**
     * Ritorna gli hint ordinati per rilevanza rispetto al NEW_USER/plan e per recency.
     * $limit = massimo di righe, default 12.
     */
    public function getHintsForPrompt(?int $userId, ?int $projectId, string $newUser, array $plan = [], int $limit = 12): array
    {
        $q = DB::table('user_context_hints')
            ->where(function($w) use ($userId, $projectId){
                // priorità: (user+project) → (solo user) → (solo project) → global (null,null)
                $w->where(function($w2) use ($userId, $projectId){
                    $w2->where('user_id', $userId)->where('project_id', $projectId);
                })
                ->orWhere(function($w2) use ($userId){
                    $w2->where('user_id', $userId)->whereNull('project_id');
                })
                ->orWhere(function($w2) use ($projectId){
                    $w2->whereNull('user_id')->where('project_id', $projectId);
                })
                ->orWhere(function($w2){
                    $w2->whereNull('user_id')->whereNull('project_id');
                });
            });

        $rows = $q->get()->all();

        $needle = $this->makeNeedle($newUser, $plan);
        $scored = [];
        foreach ($rows as $r) {
            $score = $this->score($needle, (array)$r);
            $scored[] = ['row'=>$r, 'score'=>$score];
        }

        usort($scored, function($a,$b){
            if (abs($b['score'] - $a['score']) > 1e-6) return $b['score'] <=> $a['score'];
            // tie-break: più recente prima
            $ab = Carbon::parse($a['row']->last_seen_at ?? $a['row']->updated_at ?? $a['row']->created_at);
            $bb = Carbon::parse($b['row']->last_seen_at ?? $b['row']->updated_at ?? $b['row']->created_at);
            return $bb <=> $ab;
        });

        $top = array_slice($scored, 0, $limit);
        return array_map(function($x){
            /** @var \stdClass $r */
            $r = $x['row'];
            return [
                'tag'         => $r->tag,
                'value'       => $r->value,
                'weight'      => (float)$r->weight,
                'times_seen'  => (int)$r->times_seen,
                'last_seen_at'=> (string)$r->last_seen_at,
                'score'       => (float)$x['score'],
                'source'      => $r->source,
            ];
        }, $top);
    }

    /** Render in forma “colonna” per il prompt. */
    public function renderForPrompt(array $hints): string
    {
        if (empty($hints)) return '';
        $lines = [];
        foreach ($hints as $h) {
            $date = $h['last_seen_at'] ? Carbon::parse($h['last_seen_at'])->toDateTimeString() : '';
            $lines[] = sprintf("- [%.2f | %s] %s: %s", $h['score'], $date, $h['tag'], $h['value']);
        }
        return implode("\n", $lines);
    }

    // ----------------- helpers -----------------

    private function normalizeTag(string $tag): string {
        $t = Str::of($tag)->lower()->trim();
        // mapping minimale
        return match((string)$t) {
            'subject' => 'topic',
            'theme'   => 'topic',
            default   => (string)$t
        };
    }

    private function normalizeValue(string $tag, string $value): string {
        $v = trim($value);
        if (in_array($tag, ['style','avoid','format','language','tone','topic'])) {
            $v = Str::of($v)->lower()->trim();
        }
        return (string)$v;
    }

    private function boundedWeight(float $w): float {
        return max(0.1, min(5.0, $w));
    }

    /** Needle testuale dalla richiesta e dal plan */
    private function makeNeedle(string $newUser, array $plan): string {
        $bag = [
            $newUser,
            $plan['final_user'] ?? '',
            $plan['subject'] ?? $plan['theme'] ?? '',
            implode(' ', (array)($plan['style'] ?? [])),
            implode(' ', (array)($plan['avoid'] ?? [])),
            (string)($plan['format'] ?? ''),
            (string)($plan['language'] ?? ''),
            (string)($plan['genre'] ?? ''),
        ];
        return Str::of(implode(' ', array_filter($bag)))->lower()->__toString();
    }

    /** Score euristico: 0..1 basato su overlap n-gram + recency + peso */
    private function score(string $needle, array $row): float
    {
        $hay = Str::of(trim(($row['tag'] ?? '').' '.($row['value'] ?? '')))->lower()->__toString();

        $sim = $this->ngramOverlap($needle, $hay);  // 0..1
        $rec = $this->recencyBoost($row['last_seen_at'] ?? null); // 0..1
        $w   = (float)($row['weight'] ?? 1.0);
        $wN  = max(0.0, min(1.0, ($w-0.1)/(5.0-0.1))); // normalizza 0..1

        // pesi: similitudine 0.55, recency 0.30, confidenza 0.15
        $score = 0.55*$sim + 0.30*$rec + 0.15*$wN;
        return max(0.0, min(1.0, $score));
    }

    private function ngramOverlap(string $a, string $b, int $n=3): float
    {
        $A = $this->ngrams($a, $n);
        $B = $this->ngrams($b, $n);
        if (!$A || !$B) return 0.0;
        $inter = count(array_intersect($A, $B));
        $union = count(array_unique(array_merge($A, $B)));
        return $union ? $inter / $union : 0.0;
    }

    private function ngrams(string $s, int $n): array
    {
        $t = preg_replace('/\s+/', ' ', trim($s));
        if ($t === '') return [];
        $out = [];
        for ($i=0;$i<=max(0, strlen($t)-$n);$i++){
            $out[] = substr($t, $i, $n);
        }
        return $out;
    }

    private function recencyBoost(?string $ts): float
    {
        if (!$ts) return 0.0;
        $dtDays = max(0.0, now()->diffInDays(Carbon::parse($ts)));
        // decadimento esponenziale ~ 30gg
        $lambda = 1.0/30.0;
        return exp(-$lambda * $dtDays); // 1 (oggi) → ~0.03 (90gg fa)
    }
}
