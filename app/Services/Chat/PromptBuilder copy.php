<?php
// App/Services/Chat/PromptBuilder.php
namespace App\Services\Chat;

use App\DTOs\Plan;

class PromptBuilder {
    public function determineSourceText(Plan $plan, array $historyArr): string {
        // 1) Scelta esplicita dal plan
        $pick = function(string $who) use ($historyArr): string {
            if ($who === 'last_user') {
                for ($i=count($historyArr)-1; $i>=0; $i--) {
                    if (($historyArr[$i]['role'] ?? '') === 'user') return (string)($historyArr[$i]['content'] ?? '');
                }
            } elseif ($who === 'last_assistant') {
                for ($i=count($historyArr)-1; $i>=0; $i--) {
                    if (($historyArr[$i]['role'] ?? '') === 'assistant') return (string)($historyArr[$i]['content'] ?? '');
                }
            }
            return '';
        };

        $src = '';
        if ($plan->needs_verbatim_source) {
            if ($plan->source_where === 'history_range' && is_array($plan->source_range)) {
                $a = max(0, (int)$plan->source_range[0]);
                $b = min(count($historyArr)-1, (int)$plan->source_range[1]);
                $parts = [];
                for ($i=$a; $i<=$b; $i++) $parts[] = ($historyArr[$i]['content'] ?? '');
                $src = trim(implode("\n", $parts));
            } else {
                $src = $pick($plan->source_where ?: 'last_user');
            }
        }

        // 2) Heuristics di riserva: se vuoto ma l’ultimo user ha codice, usa quello
        if ($src === '' && !empty($historyArr)) {
            $lastUser = '';
            for ($i=count($historyArr)-1; $i>=0; $i--) {
                if (($historyArr[$i]['role'] ?? '') === 'user') { $lastUser = (string)($historyArr[$i]['content'] ?? ''); break; }
            }
            if ($this->looksLikeCode($lastUser)) $src = $lastUser;
        }

        if ($src === '') return '';

        // 3) Estrai il primo blocco di codice se presente, altrimenti l’intero testo
        $code = $this->firstCodeBlock($src);
        $text = $code !== '' ? $code : $src;

        // 4) Clamp
        $max = max(1000, (int)$plan->source_chars_max ?: 8000);
        if (mb_strlen($text) > $max) $text = mb_substr($text, 0, $max - 1).'…';

        return $text;
    }

    public function buildFinalPayload(
        Plan   $plan,
        string $userProfileJson,
        string $projectMemoryJson,
        string $sourceText = '',
        string $memoryHintsText = ''
    ): array {
        $req = [];
        if ($plan->language) $req[] = "Lingua: {$plan->language}";
        if ($plan->format)   $req[] = "Formato: {$plan->format}";
        if (!empty($plan->style)) $req[] = "Stile: ".implode(', ', $plan->style);
        if (!empty($plan->avoid)) $req[] = "Evita: ".implode(', ', $plan->avoid);
        if ($plan->length)   $req[] = "Lunghezza: {$plan->length}";
        // $req[] = "bestemmie";

        $blocks = [];
        if ($req) $blocks[] = "[REQUIREMENTS]\n- ".implode("\n- ", $req);
        $blocks[] = "[USER_PROFILE]\n".$this->normalizeJsonOrEmpty($userProfileJson);

        if ($projectMemoryJson !== '') {
            if ($plan->drop_memory_for_turn) {
                $onlyPrefs = $this->extractPrefsFromProjectMemory($projectMemoryJson);
                if ($onlyPrefs !== '{}') $blocks[] = "[MEMORY_PREFS]\n".$onlyPrefs;
            } else {
                $blocks[] = "[MEMORY]\n".$projectMemoryJson;
            }
        }

        if (trim($memoryHintsText) !== '') $blocks[] = "[MEMORY_HINTS]\n".$memoryHintsText;

        if (trim($plan->context_summary) !== '') {
            $blocks[] = "[CONTEXT]\n".$plan->context_summary;
        } elseif ($plan->include_full_history && !empty($plan->history)) {
            $blocks[] = "[CONTEXT]\n".$this->renderHistoryPlain($plan->history, 600, 4500);
        } elseif (trim($plan->compressed_context ?? '') !== '') {
            $blocks[] = "[CONTEXT]\n".trim($plan->compressed_context);
        }

        $sourceBlock = '';
        if ($sourceText !== '') $sourceBlock = "[SOURCE]\n".$sourceText;

        $user = "[USER]\n".$plan->final_user;

        $payload = implode("\n\n", array_filter([
            $blocks ? implode("\n\n", $blocks) : '',
            $sourceBlock,
            $user
        ]));

        return ['payload'=>$payload,'debug'=>$payload,'needs_source_but_missing'=>false];
    }

    // ---------- helpers ----------
    private function normalizeJsonOrEmpty(?string $json): string {
        $t = trim((string)$json);
        if ($t === '' || $t === '[]') return '{}';
        return $t;
    }
    private function extractPrefsFromProjectMemory(string $json): string {
        $obj = json_decode($json, true);
        if (!is_array($obj)) return '{}';
        $prefs = $obj['prefs'] ?? null;
        if (!is_array($prefs)) return '{}';
        return json_encode(['prefs' => $prefs], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private function renderHistoryPlain(array $history, int $perMsgMax = 500, int $totalMax = 4000): string {
        $clamp = function(string $s, int $max): string {
            $s = preg_replace('/```[\s\S]*?```/m', '[codice omesso]', $s);
            $s = preg_replace('/\s+/', ' ', trim($s));
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
        };
        $out = []; $used=0;
        foreach ($history as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
            $line = $role.': '.$clamp((string)($m['content'] ?? ''), $perMsgMax);
            $len  = mb_strlen($line) + 1;
            if ($used + $len > $totalMax) break;
            $out[] = $line; $used += $len;
        }
        return implode("\n", $out);
    }
    private function looksLikeCode(string $s): bool {
        $t = trim($s);
        if ($t === '') return false;
        if (preg_match('/^```/m', $t)) return true;
        if (str_starts_with($t, '<?php')) return true;
        if (preg_match('/class\s+\w+|function\s+\w+\s*\(|\{|\};|<\/\w+>/', $t)) return true;
        return false;
    }
    private function firstCodeBlock(string $s): string {
        if (preg_match('/```[a-zA-Z0-9:+_-]*\s*\R([\s\S]*?)\R```/m', $s, $m)) {
            return rtrim($m[1]);
        }
        // supporta anche blocchi con '''
        if (preg_match("/'''[a-zA-Z0-9:+_-]*\s*\R([\s\S]*?)\R'''/m", $s, $m2)) {
            return rtrim($m2[1]);
        }
        return '';
    }
}
