<?php
// App/Services/Chat/PromptBuilder.php
namespace App\Services\Chat;

use App\DTOs\Plan;

class PromptBuilder
{
    /**
     * Estrae il testo sorgente da history secondo il Plan.
     */
    public function determineSourceText(Plan $plan, array $historyArr): string
    {
        // 1) Scelta esplicita dal plan
        $pick = function (string $who) use ($historyArr): string {
            if ($who === 'last_user') {
                for ($i = count($historyArr) - 1; $i >= 0; $i--) {
                    if (($historyArr[$i]['role'] ?? '') === 'user') {
                        return (string)($historyArr[$i]['content'] ?? '');
                    }
                }
            } elseif ($who === 'last_assistant') {
                for ($i = count($historyArr) - 1; $i >= 0; $i--) {
                    if (($historyArr[$i]['role'] ?? '') === 'assistant') {
                        return (string)($historyArr[$i]['content'] ?? '');
                    }
                }
            }
            return '';
        };

        $src = '';
        if ($plan->needs_verbatim_source) {
            if ($plan->source_where === 'history_range' && is_array($plan->source_range)) {
                $a = max(0, (int)$plan->source_range[0]);
                $b = min(count($historyArr) - 1, (int)$plan->source_range[1]);
                $parts = [];
                for ($i = $a; $i <= $b; $i++) {
                    $parts[] = ($historyArr[$i]['content'] ?? '');
                }
                $src = trim(implode("\n", $parts));
            } else {
                $src = $pick($plan->source_where ?: 'last_user');
            }
        }

        // 2) Heuristics di riserva: se vuoto ma l’ultimo user ha codice, usa quello
        if ($src === '' && !empty($historyArr)) {
            $lastUser = '';
            for ($i = count($historyArr) - 1; $i >= 0; $i--) {
                if (($historyArr[$i]['role'] ?? '') === 'user') {
                    $lastUser = (string)($historyArr[$i]['content'] ?? '');
                    break;
                }
            }
            if ($this->looksLikeCode($lastUser)) {
                $src = $lastUser;
            }
        }

        if ($src === '') {
            return '';
        }

        // 3) Estrai il primo blocco di codice se presente, altrimenti l’intero testo
        $code = $this->firstCodeBlock($src);
        $text = $code !== '' ? $code : $src;

        // 4) Clamp
        $max = max(1000, (int)$plan->source_chars_max ?: 8000);
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max - 1) . '…';
        }

        return $text;
    }

    /**
     * Costruisce i messaggi finali per il modello:
     * - 1 system compatto (lingua/stile/policy/stack)
     * - 0..1 assistant_context (facoltativo, breve)
     * - 1 user (testo utente + eventuale SORGENTE)
     *
     * Ritorna sia 'messages' (per il provider) sia 'payload'/'debug' (stringa per log/retrocompatibilità).
     */
    public function buildFinalPayload(
        Plan   $plan,
        string $userProfileJson,
        string $projectMemoryJson,
        string $sourceText = '',
        string $memoryHintsText = ''
    ): array {
        // ---- 1) Merge preferenze (REQUIREMENTS > USER_PROFILE > MEMORY > Hints) ----
        $userProfile = $this->decodeJson($userProfileJson);
        $projectMem  = $this->decodeJson($projectMemoryJson);
        $memPrefs    = is_array($projectMem) ? ($projectMem['prefs'] ?? []) : [];

        $lang = $this->pickFirstNonEmpty([
            $plan->language,
            $userProfile['language'] ?? null,
            'it',
        ]);

        $style = $this->uniqueFlat([
            $plan->style ?? [],
            $userProfile['tone'] ?? [],
            $memPrefs['style'] ?? [],
        ]);
        // hints testuali (se li passi già aggregati)
        $hintsParsed = $this->parseHintsList($memoryHintsText);
        if (!empty($hintsParsed['style'])) {
            $style = array_values(array_unique(array_merge($style, $hintsParsed['style'])));
        }

        $avoid = $this->uniqueFlat([
            $plan->avoid ?? [],
            $userProfile['avoid'] ?? [],
            $memPrefs['avoid'] ?? [],
            $hintsParsed['avoid'] ?? [],
        ]);

        $length = $this->pickFirstNonEmpty([
            $plan->length,
            $projectMem['length'] ?? null,
            'short',
        ]);

        // regole codice
        $codeRules = 'usa diff unificato quando tocchi codice';

        // target stack
        $phpVersion     = '8.3.21';
        $laravelVersion = '12.x';

        // politiche
        $askIfMissing = true;
        $neverInvent  = true;

        // ---- 2) System compatta ----
        $styleLine = $this->styleLine($style);
        $avoidLine = $this->avoidLine($avoid);
        $lenLine   = $this->lengthLine($length);

        $system = trim(
            "Sei un assistente che risponde in {$lang}.\n\n" .
            "Regole di stile:\n" .
            "- Tono: {$styleLine}\n" .
            "- Formato: nessun formato speciale di default. Se tocchi codice, {$codeRules}.\n" .
            "- Lunghezza: {$lenLine}\n\n" .
            "Politiche:\n" .
            ($askIfMissing ? "- Se manca il contesto recente, chiedi chiarimenti brevi.\n" : "") .
            ($neverInvent  ? "- Non inventare mai fatti.\n" : "") .
            "Quando fornisci codice, target: PHP {$phpVersion}, Laravel {$laravelVersion}.\n" .
            ($avoidLine ? "\nEvita: {$avoidLine}\n" : "")
        );

        // ---- 3) Assistant context (facoltativo e breve) ----
        $contextSnippets = [];
        if (trim((string)$plan->context_summary) !== '') {
            $contextSnippets[] = trim((string)$plan->context_summary);
        } elseif ($plan->include_full_history && !empty($plan->history)) {
            // sintetico: usa lo stesso renderer ma con limiti più bassi
            $contextSnippets[] = $this->renderHistoryPlain($plan->history, 200, 1200);
        } elseif (trim((string)($plan->compressed_context ?? '')) !== '') {
            $contextSnippets[] = trim((string)$plan->compressed_context);
        }
        if ($memoryHintsText !== '') {
            // tieni solo 2-3 hint non ridondanti
            $briefHints = $this->takeNonEmptyLines($memoryHintsText, 3);
            if ($briefHints !== '') {
                $contextSnippets[] = "Hints: " . $briefHints;
            }
        }
        $assistantContext = null;
        if (!empty($contextSnippets)) {
            $assistantContext = "Contesto recente utile:\n- " . implode("\n- ", array_map('trim', $contextSnippets));
        }

        // ---- 4) User message (+ eventuale SORGENTE) ----
        $userContent = (string)$plan->final_user;
        if ($sourceText !== '') {
            // niente etichette rumorose, ma separatore chiaro
            $userContent .= "\n\n---\nSORGENTE:\n" . $sourceText;
        }

        // ---- 5) Messaggi finali ----
        $messages = [
            ['role' => 'system', 'content' => $system],
        ];
        if ($assistantContext) {
            // nome speciale, non tutti i provider lo usano ma non dà fastidio
            $messages[] = ['role' => 'assistant', 'name' => 'assistant_context', 'content' => $assistantContext];
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        // ---- 6) Stringa payload/debug (retrocompat) ----
        $payloadStr = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'messages' => $messages,
            'payload'  => $payloadStr,
            'debug'    => $payloadStr,
            'needs_source_but_missing' => false,
        ];
    }

    // ---------- helpers ----------
    private function decodeJson(?string $json): array
    {
        $t = trim((string)$json);
        if ($t === '' || $t === '[]' || $t === '{}') {
            return [];
        }
        $arr = json_decode($t, true);
        return is_array($arr) ? $arr : [];
    }

    private function pickFirstNonEmpty(array $candidates)
    {
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return trim($c);
            }
        }
        return null;
    }

    private function uniqueFlat($arrays): array
    {
        $out = [];
        foreach ((array)$arrays as $a) {
            if (is_string($a)) {
                $a = [$a];
            }
            if (is_array($a)) {
                foreach ($a as $v) {
                    if (is_string($v)) {
                        $v = trim($v);
                        if ($v !== '') {
                            $out[] = $v;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function styleLine(array $style): string
    {
        if (!$style) {
            return 'diretto e colloquiale; ironia/sarcasmo ok. Parolacce/blasfemia solo se l’utente le usa.';
        }

        // normalizza alcune keyword note
        $map = [
            'colorito'   => 'colorito (solo se l’utente apre)',
            'parolacce'  => 'parolacce solo se l’utente le usa',
            'bestemmie'  => 'blasfemia solo se l’utente la usa',
        ];
        $style = array_map(function ($s) use ($map) {
            $k = mb_strtolower($s);
            return $map[$k] ?? $s;
        }, $style);

        // Garantisci la guardia su parolacce/blasfemia
        if (!preg_grep('/parolacce/i', $style)) {
            $style[] = 'parolacce solo se l’utente le usa';
        }
        if (!preg_grep('/blasfem/i', $style)) {
            $style[] = 'blasfemia solo se l’utente la usa';
        }

        return implode(', ', $style);
    }

    private function avoidLine(array $avoid): string
    {
        if (!$avoid) {
            return '';
        }
        return implode(', ', $avoid);
    }

    private function lengthLine(?string $len): string
    {
        $len = strtolower((string)$len);
        return match ($len) {
            'short', 'breve' => 'breve per richieste semplici; estesa solo se necessario',
            'long', 'estesa' => 'estesa e dettagliata',
            default          => 'breve per richieste semplici; estesa solo se necessario',
        };
    }

    private function renderHistoryPlain(array $history, int $perMsgMax = 500, int $totalMax = 4000): string
    {
        $clamp = function (string $s, int $max): string {
            $s = preg_replace('/```[\s\S]*?```/m', '[codice omesso]', $s);
            $s = preg_replace('/\s+/', ' ', trim($s));
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
        };
        $out = [];
        $used = 0;
        foreach ($history as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
            $line = $role . ': ' . $clamp((string)($m['content'] ?? ''), $perMsgMax);
            $len  = mb_strlen($line) + 1;
            if ($used + $len > $totalMax) {
                break;
            }
            $out[] = $line;
            $used += $len;
        }
        return implode("\n", $out);
    }

    private function looksLikeCode(string $s): bool
    {
        $t = trim($s);
        if ($t === '') return false;
        if (preg_match('/^```/m', $t)) return true;
        if (str_starts_with($t, '<?php')) return true;
        if (preg_match('/class\s+\w+|function\s+\w+\s*\(|\{|\};|<\/\w+>/', $t)) return true;
        return false;
    }

    private function firstCodeBlock(string $s): string
    {
        if (preg_match('/```[a-zA-Z0-9:+_-]*\s*\R([\s\S]*?)\R```/m', $s, $m)) {
            return rtrim($m[1]);
        }
        // supporta anche blocchi con '''
        if (preg_match("/'''[a-zA-Z0-9:+_-]*\s*\R([\s\S]*?)\R'''/m", $s, $m2)) {
            return rtrim($m2[1]);
        }
        return '';
    }

    private function parseHintsList(string $text): array
    {
        // accetta righe tipo "- style: professionale", "- avoid: diff"
        $out = ['style' => [], 'avoid' => []];
        foreach (preg_split('/\R+/', trim($text)) as $line) {
            $line = trim($line, "- \t");
            if ($line === '') continue;
            if (stripos($line, 'style:') === 0) {
                $val = trim(substr($line, 6));
                if ($val !== '') $out['style'][] = $val;
            } elseif (stripos($line, 'avoid:') === 0) {
                $val = trim(substr($line, 6));
                if ($val !== '') $out['avoid'][] = $val;
            }
        }
        return $out;
    }

    private function takeNonEmptyLines(string $text, int $maxLines = 3): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string)$text)), fn($l) => $l !== ''));
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }
        return implode('; ', $lines);
    }
}
