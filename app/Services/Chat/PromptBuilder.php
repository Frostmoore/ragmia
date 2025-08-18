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
     * - 1 system compatto (lingua/stile/policy/stack + regole di precedenza)
     * - 0..1 assistant_context (facoltativo, breve e ripulito)
     * - 1 user (testo utente + eventuale SORGENTE + eventuale forcing di formato)
     */
    // public function buildFinalPayload(
    //     Plan   $plan,
    //     string $userProfileJson,
    //     string $projectMemoryJson,
    //     string $sourceText = '',
    //     string $memoryHintsText = ''
    // ): array {
    //     // ---- 1) Merge preferenze (REQUIREMENTS > USER_PROFILE > MEMORY > Hints) ----
    //     $userProfile = $this->decodeJson($userProfileJson);
    //     $projectMem  = $this->decodeJson($projectMemoryJson);
    //     $memPrefs    = is_array($projectMem) ? ($projectMem['prefs'] ?? []) : [];

    //     $lang = $this->pickFirstNonEmpty([
    //         $plan->language,
    //         $userProfile['language'] ?? null,
    //         'it',
    //     ]);

    //     $style = $this->uniqueFlat([
    //         $plan->style ?? [],
    //         $userProfile['tone'] ?? [],
    //         $memPrefs['style'] ?? [],
    //     ]);
    //     // hints testuali (se li passi già aggregati)
    //     $hintsParsed = $this->parseHintsList($memoryHintsText);
    //     if (!empty($hintsParsed['style'])) {
    //         $style = array_values(array_unique(array_merge($style, $hintsParsed['style'])));
    //     }
    //     // pulizia stile (evita frasi lunghe/rumorose tipo "Passi rapidi...")
    //     $style = $this->sanitizeStyleTokens($style);

    //     // Evita: forza sempre "diff"; e scoraggia formati strutturati non richiesti
    //     $avoid = $this->uniqueFlat([
    //         $plan->avoid ?? [],
    //         $userProfile['avoid'] ?? [],
    //         $memPrefs['avoid'] ?? [],
    //         $hintsParsed['avoid'] ?? [],
    //     ]);
    //     $avoidLower = array_map('mb_strtolower', $avoid);
    //     if (!in_array('diff', $avoidLower, true)) {
    //         $avoid[] = 'diff';
    //     }

    //     $length = $this->pickFirstNonEmpty([
    //         $plan->length,
    //         $projectMem['length'] ?? null,
    //         'short',
    //     ]);

    //     // Modalità "codice": quando il planner richiede codice, imponiamo regole più rigide
    //     $strictCodeMode = is_string($plan->format) && mb_stripos($plan->format, 'code') !== false;

    //     // Rileva se L'ULTIMO messaggio chiede esplicitamente JSON/YAML/CSV
    //     $finalUser = (string)$plan->final_user;
    //     $explicitJson = $this->explicitJsonRequested($finalUser);
    //     $explicitYaml = $this->explicitYamlRequested($finalUser);
    //     $explicitCsv  = $this->explicitCsvRequested($finalUser);

    //     // Regole formato/codice
    //     $codeRules = $strictCodeMode
    //         ? 'per NUOVO codice: inizia SUBITO con un unico blocco racchiuso tra tre apici (```), indicando il linguaggio se evidente (es. ```python); termina con ```; nessun diff (+/-), nessuna prosa prima o dopo salvo esplicita richiesta.'
    //         : 'se includi codice, racchiudilo sempre tra tre apici (```), indica il linguaggio se evidente; non usare diff (+/-).';

    //     // target stack (solo come riferimento per PHP/Laravel)
    //     $phpVersion     = '8.3.21';
    //     $laravelVersion = '12.x';

    //     // politiche
    //     $askIfMissing = true;
    //     $neverInvent  = true;

    //     // ---- 2) System compatta (con regole di precedenza chiare) ----
    //     $styleLine = $this->styleLine($style);
    //     $avoidLine = $this->avoidLine($avoid);
    //     $lenLine   = $this->lengthLine($length);

    //     $precedence = "Regole di precedenza:\n"
    //         . "1) Obbedisci alla richiesta PIÙ RECENTE dell’utente (questo messaggio) anche se contraddice contesto o preferenze storiche.\n"
    //         . "2) Se l’utente specifica un FORMATO (es. JSON/YAML/prosa/codice), quel formato ha priorità assoluta.\n"
    //         . "3) Se l’utente NON specifica un formato, rispondi in prosa naturale; NON usare JSON/YAML/CSV salvo richiesta esplicita.\n"
    //         . "4) Non cambiare il formato richiesto e non aggiungere testo extra attorno a output strutturati.";

    //     $system = trim(
    //         "Sei un assistente che risponde in {$lang}.\n\n"
    //         . $precedence . "\n\n"
    //         . "Regole di stile:\n"
    //         . "- Tono: {$styleLine}\n"
    //         . "- Formato: {$codeRules}\n"
    //         . "- Lunghezza: {$lenLine}\n\n"
    //         . "Politiche:\n"
    //         . ($askIfMissing ? "- Se manca il contesto recente, chiedi chiarimenti brevi.\n" : "")
    //         . ($neverInvent  ? "- Non inventare mai fatti.\n" : "")
    //         . "Quando fornisci codice PHP/Laravel, target: PHP {$phpVersion}, Laravel {$laravelVersion}.\n"
    //         . ($avoidLine ? "\nEvita: {$avoidLine}\n" : "")
    //     );

    //     // ---- 3) Assistant context (brevissimo e ripulito) ----
    //     $contextSnippets = [];
    //     if (trim((string)$plan->context_summary) !== '') {
    //         $contextSnippets[] = trim((string)$plan->context_summary);
    //     } elseif ($plan->include_full_history && !empty($plan->history)) {
    //         $contextSnippets[] = $this->renderHistoryPlain($plan->history, 200, 1200);
    //     } elseif (trim((string)($plan->compressed_context ?? '')) !== '') {
    //         $contextSnippets[] = trim((string)$plan->compressed_context);
    //     }
        
    //     // if ($memoryHintsText !== '') {
    //     //     $briefHints = $this->takeNonEmptyLines($memoryHintsText, 3);
    //     //     if ($briefHints !== '') {
    //     //         $contextSnippets[] = "Hints: " . $briefHints;
    //     //     }
    //     // }

    //     // Se il compressor ha deciso di sospendere il contesto, non passare alcun assistant_context
    //     if (!empty($plan->suspend_context)) {
    //         $contextSnippets = [];
    //     }
    //     // filtra rumore/metainstruzioni e rimuovi richieste di formato che CONTRASTANO con l'ultimo messaggio
    //     $contextSnippets = $this->filterContextSnippets($contextSnippets, $explicitJson, $explicitYaml, $explicitCsv);

    //     $assistantContext = null;
    //     if (!empty($contextSnippets)) {
    //         $assistantContext = "Contesto recente utile:\n- " . implode("\n- ", array_map('trim', $contextSnippets));
    //     }

    //     // ---- 4) User message (+ eventuale SORGENTE + forcing formato se richiesto ORA) ----
    //     $userContent = $finalUser;

    //     if ($explicitJson) {
    //         $userContent = "Rispondi ESCLUSIVAMENTE con JSON valido UTF-8, senza testo extra prima/dopo, senza commenti. "
    //                      . "Se non hai dati, restituisci un JSON vuoto coerente con la richiesta.\n\n"
    //                      . $userContent;
    //     } elseif ($explicitYaml) {
    //         $userContent = "Rispondi ESCLUSIVAMENTE in YAML valido, senza testo extra prima/dopo, senza commenti.\n\n"
    //                      . $userContent;
    //     } elseif ($explicitCsv) {
    //         $userContent = "Rispondi ESCLUSIVAMENTE con un CSV valido (prima riga intestazioni), senza testo extra prima/dopo.\n\n"
    //                      . $userContent;
    //     } elseif ($strictCodeMode) {
    //         $userContent = "Restituisci il codice richiesto in un UNICO blocco markdown tra tre apici (```), "
    //                      . "con l'etichetta del linguaggio se evidente; nessun diff, nessuna prosa extra.\n\n"
    //                      . $userContent;
    //     } else {
    //         // Nessun formato imposto: rafforza la prosa naturale
    //         $userContent = "Rispondi in prosa naturale (niente JSON/YAML/CSV se non richiesto esplicitamente).\n\n"
    //                      . $userContent;
    //     }

    //     if ($sourceText !== '') {
    //         $userContent .= "\n\n---\nSORGENTE:\n" . $sourceText;
    //     }

    //     // ---- 5) Messaggi finali ----
    //     $messages = [
    //         ['role' => 'system', 'content' => $system],
    //     ];
    //     if ($assistantContext) {
    //         $messages[] = ['role' => 'assistant', 'name' => 'assistant_context', 'content' => $assistantContext];
    //     }
    //     $messages[] = ['role' => 'user', 'content' => $userContent];

    //     // ---- 6) Stringa payload/debug (retrocompat) ----
    //     $payloadStr = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    //     return [
    //         'messages' => $messages,
    //         'payload'  => $payloadStr,
    //         'debug'    => $payloadStr,
    //         'needs_source_but_missing' => false,
    //     ];
    // }

    public function buildFinalPayload(
        Plan   $plan,
        string $userProfileJson,
        string $projectMemoryJson,
        string $sourceText = '',
        string $memoryHintsText = ''
    ): array {
        // ---- 1) Merge preferenze (REQUIREMENTS > USER_PROFILE > MEMORY) ----
        $userProfile = $this->decodeJson($userProfileJson);

        // IGNORA completamente la project memory se contiene rumore operativo
        $projectMemRaw = $projectMemoryJson ?: '{}';
        if ($this->looksOpsNoise($projectMemRaw)) {
            $projectMem = [];
        } else {
            $projectMem = $this->decodeJson($projectMemRaw);
        }
        $memPrefs = is_array($projectMem) ? ($projectMem['prefs'] ?? []) : [];

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
        // pulizia stile (no frasi lunghe)
        $style = $this->sanitizeStyleTokens($style);

        // Evita diff sempre
        $avoid = $this->uniqueFlat([
            $plan->avoid ?? [],
            $userProfile['avoid'] ?? [],
            $memPrefs['avoid'] ?? [],
        ]);
        $avoidLower = array_map('mb_strtolower', $avoid);
        if (!in_array('diff', $avoidLower, true)) {
            $avoid[] = 'diff';
        }

        $length = $this->pickFirstNonEmpty([
            $plan->length,
            is_array($projectMem) ? ($projectMem['length'] ?? null) : null,
            'short',
        ]);

        $strictCodeMode = is_string($plan->format) && mb_stripos($plan->format, 'code') !== false;

        // ---- 2) System minimale ma rigido ----
        $styleLine = $this->styleLine($style);
        $avoidLine = $this->avoidLine($avoid);
        $lenLine   = $this->lengthLine($length);

        $precedence = "Regole di precedenza:\n"
            . "1) Obbedisci alla richiesta PIÙ RECENTE dell’utente.\n"
            . "2) Se l’utente specifica un FORMATO (JSON/YAML/CSV/codice), usa SOLO quel formato.\n"
            . "3) Se NON specifica un formato, rispondi in prosa naturale.\n"
            . "4) Non aggiungere testo extra attorno a output strutturati.";

        $codeRules = $strictCodeMode
            ? "Per NUOVO codice: restituisci un SOLO blocco markdown tra tre apici (```linguaggio …```), senza diff né prosa."
            : "Se includi codice, racchiudilo sempre tra tre apici (```), indica il linguaggio se evidente; non usare diff (+/-).";

        $system = trim(
            "Sei un assistente che risponde in {$lang}.\n\n"
            . $precedence . "\n\n"
            . "Regole di stile:\n"
            . "- Tono: {$styleLine}\n"
            . "- Formato: {$codeRules}\n"
            . "- Lunghezza: {$lenLine}\n\n"
            . "Politiche:\n"
            . "- Se manca il contesto recente, chiedi chiarimenti brevi.\n"
            . "- Non inventare mai fatti.\n"
            . "Quando fornisci codice PHP/Laravel, target: PHP 8.3.21, Laravel 12.x.\n"
            . ($avoidLine ? "\nEvita: {$avoidLine}\n" : "")
        );

        // ---- 3) Assistant context: SOLO se esplicitamente utile e ripulito ----
        $assistantContext = null;
        $snippets = [];

        if ($plan->include_full_history && !empty($plan->history)) {
            $snippets[] = $this->renderHistoryPlain($plan->history, 200, 800);
        }
        if (trim((string)$plan->context_summary) !== '') {
            $snippets[] = trim((string)$plan->context_summary);
        }
        if (trim((string)($plan->compressed_context ?? '')) !== '') {
            $snippets[] = trim((string)$plan->compressed_context);
        }

        // Filtra rumore (runbook/checklist/504/INJECTED/SOURCES…) e tieni al massimo 1 voce
        $snippets = $this->filterContextSnippets($snippets);
        if (!empty($snippets)) {
            $assistantContext = "Contesto utile:\n- " . $snippets[0];
        }

        // ---- 4) User message: SOLO l’input dell’utente; niente prefissi verbosi ----
        $finalUser = (string)$plan->final_user;
        $userContent = $finalUser;

        // Aggiungi la SORGENTE solo se davvero richiesta dal plan
        if ($plan->needs_verbatim_source && $sourceText !== '') {
            $userContent .= "\n\n---\nSORGENTE:\n" . $sourceText;
        }

        // ---- 5) Messaggi finali ----
        $messages = [
            ['role' => 'system', 'content' => $system],
        ];
        if ($assistantContext) {
            $messages[] = ['role' => 'assistant', 'name' => 'assistant_context', 'content' => $assistantContext];
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        // ---- 6) Payload/debug ----
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

    // --------- NUOVI helper per robustezza formato/stile/context ---------

    private function explicitJsonRequested(string $user): bool
    {
        return (bool)preg_match('/\b(json|in\s+json|formato\s+json)\b/i', $user);
    }

    private function explicitYamlRequested(string $user): bool
    {
        return (bool)preg_match('/\b(yaml|in\s+yaml|formato\s+yaml)\b/i', $user);
    }

    private function explicitCsvRequested(string $user): bool
    {
        return (bool)preg_match('/\b(csv|in\s+csv|formato\s+csv)\b/i', $user);
    }

    private function sanitizeStyleTokens(array $tokens): array
    {
        // Whitelist semplice per evitare mostri tipo "Passi rapidi per risolvere i 504"
        $allowed = [
            'diretto','colloquiale','professionale','tecnico','sintetico','dettagliato',
            'ironico','sarcastico','formale','informale','amichevole','pragmatico',
            'chiaro','neutro','creativo'
        ];
        $normalized = [];
        foreach ($tokens as $t) {
            $k = mb_strtolower(trim($t));
            // scarta frasi troppo lunghe o con numeri/simboli sospetti
            if (mb_strlen($k) > 30) continue;
            if (preg_match('/\d/', $k)) continue;
            // mappa sinonimi comuni
            $map = [
                'friendly' => 'amichevole',
                'conciso'  => 'sintetico',
                'preciso'  => 'tecnico',
            ];
            $k = $map[$k] ?? $k;
            if (in_array($k, $allowed, true)) {
                $normalized[] = $k;
            }
        }
        // guardie su parolacce/blasfemia sempre aggiunte altrove da styleLine()
        return array_values(array_unique($normalized));
    }

    private function looksOpsNoise(string $s): bool {
        $t = mb_strtolower($s);
        $kw = [
            'runbook','checklist','incident','504','gateway','reverse proxy','proxy','cloudflare','alb','nginx',
            'content-type','application/json','text/html','redirect','30x','401','403',
            'jwks','kid','firma','signature','rotazione chiavi','rotate',
            'parsing html','parse json','non-json','timeout','p95','p99',
            '[injected]','[sources]'
        ];
        foreach ($kw as $k) {
            if (str_contains($t, $k)) return true;
        }
        return false;
    }

    private function filterContextSnippets(array $snippets): array {
        $out = [];
        foreach ($snippets as $s) {
            $st = trim((string)$s);
            if ($st === '') continue;
            if ($this->looksOpsNoise($st)) continue;
            // niente meta come Policy/Decisioni/Obiettivi/Vincoli
            if (preg_match('/\b(policy|decisioni|obiettivi|vincoli|prossimi passi|assunzioni|checklist|runbook)\b/i', $st)) {
                continue;
            }
            $out[] = $st;
            if (count($out) >= 1) break; // massimo 1
        }
        return $out;
    }

}
