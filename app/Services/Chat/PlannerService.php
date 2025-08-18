<?php
// app/Services/Chat/PlannerService.php
namespace App\Services\Chat;

use App\DTOs\Plan;
use App\Services\LLM\LlmClient;

class PlannerService
{
    public function __construct(private LlmClient $llm) {}

    public function plan(
        string $compressModel,
        string $userProfileJson,
        string $projectMemoryJson,
        array  $historyArr,
        string $currentPrompt
    ): Plan {
        $hist = $this->renderHistoryPlain($historyArr, 240, 1200);

        // ===================== SYSTEM PROMPT (compressor) =====================
        $sys = <<<SYS
Sei un compressor/planner conservativo. NON rispondere alla domanda dell’utente.
Obiettivi:
- Costruire un riassunto strutturato per il modello finale.
- Dare priorità assoluta all’ULTIMO messaggio dell’utente.

Regole:
1) "final_user": deve essere una COPIA ESATTA di USER_PROMPT (stesso testo; puoi normalizzare solo spazi finali). Niente parafrasi o completamenti.
2) "format": scegli SOLO tra ["prose","code","json","yaml","csv"] in base a trigger ESPLICITI in USER_PROMPT:
   - "json","in json","formato json"  -> format="json"
   - "yaml","in yaml","formato yaml"  -> format="yaml"
   - "csv","in csv","formato csv"     -> format="csv"
   - "codice","script","implementa","scrivi codice", presenza di ``` o pattern di codice -> format="code"
   - Altrimenti -> "prose"
   Non imporre JSON/YAML/CSV senza trigger. Non forzare "code" se non esplicitamente richiesto.
3) "context_summary": una sola riga su cosa vuole ORA l’utente, senza consigli o soluzioni.
4) "compressed_context": al massimo 2 bullet molto brevi e davvero utili alla richiesta corrente; se non servono, stringa vuota.
   - Vietato includere checklist/policy generiche, “passi rapidi”, elenchi di sicurezza, materiale off-topic.
5) "style" e "avoid": estrai solo se espliciti in USER_PROMPT; altrimenti [].
   - Per "style" usa solo token brevi e comuni (es. diretto, colloquiale, professionale, tecnico, sintetico, dettagliato, ironico, sarcastico, formale, informale, amichevole, pragmatico, chiaro, neutro, creativo).
6) Sorgente:
   - Se l’utente ha incollato CODICE o chiede di spiegare/analizzare/riscrivere contenuto appena incollato -> "needs_verbatim_source": true e "source_where": "last_user"
   - Se chiede di modificare/continuare l’ULTIMO OUTPUT dell’assistente (testo/codice) -> "needs_verbatim_source": true e "source_where": "last_assistant"
   - Altrimenti "needs_verbatim_source": false, "source_where": "none"
7) Non usare memoria/storia se non sono chiaramente utili: niente “include_full_history”; non generare "compressed_context" se non serve.
8) Ritorna SOLO lo JSON richiesto. Nessun testo fuori.

Valori ammessi:
- format: "prose" | "code" | "json" | "yaml" | "csv"
- source_where: "none" | "last_user" | "last_assistant"
SYS;

        // ===================== USER PROMPT (compressor) =====================
        $usr = <<<USR
[USER_PROFILE]
{$this->normalizeJson($userProfileJson)}

[PROJECT_MEMORY]
{$this->normalizeJson($projectMemoryJson)}

[RECENT_HISTORY]
{$hist}

[USER_PROMPT]
{$currentPrompt}

JSON atteso esatto:
{
  "final_user": "COPIA_ESATTA_DI_USER_PROMPT",
  "subject": "",
  "style": [],
  "avoid": [],
  "language": "",
  "length": "",
  "format": "prose",
  "include_full_history": false,
  "compressed_context": "",
  "context_summary": "",
  "needs_verbatim_source": false,
  "source_where": "none"
}
USR;

        // Chiamata al modello compressor
        $resp = $this->llm->call($compressModel, [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
        ], [
            'max_tokens'  => 500,
            'temperature' => 0
        ]);

        $rawText = (string)($resp['text'] ?? '');
        $arr     = $this->extractJsonObject($rawText) ?? [];
        $plan    = Plan::fromArray($arr);
        $plan->raw = $rawText;

        // ===================== GUARD-RAIL SERVER-SIDE =====================
        // 0) Normalizza lingua
        if ($plan->language === '') {
            $plan->language = $this->guessLanguage($currentPrompt) ?: 'it';
        }

        // 1) Pass-through duro: final_user deve essere identico all'input corrente
        if (trim((string)$plan->final_user) === '' || $this->notEqualLoose($plan->final_user, $currentPrompt)) {
            $plan->final_user = $currentPrompt;
        }

        // 2) Sanitize style tokens (whitelist breve)
        if (is_array($plan->style) && !empty($plan->style)) {
            $plan->style = $this->sanitizeStyleTokens($plan->style);
        }

        // 3) Format protetto: se inconsistente o assente, determina dai trigger dell'ultimo turno
        $allowedFormats = ['prose','code','json','yaml','csv'];
        if (!in_array($plan->format, $allowedFormats, true)) {
            $plan->format = $this->inferFormatFromUser($currentPrompt);
        } else {
            // Se compressor ha scelto json/yaml/csv senza trigger espliciti, forziamo prosa
            $explicitJson = $this->looksJsonRequested($currentPrompt);
            $explicitYaml = $this->looksYamlRequested($currentPrompt);
            $explicitCsv  = $this->looksCsvRequested($currentPrompt);
            if (in_array($plan->format, ['json','yaml','csv'], true) && !($explicitJson || $explicitYaml || $explicitCsv)) {
                $plan->format = 'prose';
            }
        }

        // 4) Domande “atomiche”: azzera contesto per evitare bias (es. cavallo di Napoleone)
        if ($this->looksAtomicQuestion($currentPrompt)) {
            $plan->include_full_history = false;
            $plan->compressed_context   = '';
            $plan->context_summary      = $plan->context_summary ?: 'Domanda atomica di conoscenza generale.';
        }

        // 5) Heuristics già esistenti: explain/edit + sorgente
        $lastUser      = $this->lastByRole($historyArr, 'user');
        $lastAssistant = $this->lastByRole($historyArr, 'assistant');
        $userHasCode   = $this->looksLikeCode($lastUser);
        $askExplain    = $this->looksExplainIntent($currentPrompt);
        $askEdit       = $this->looksEditIntent($currentPrompt);

        if ($userHasCode && $askExplain) {
            $plan->needs_verbatim_source = true;
            $plan->source_where          = 'last_user';
            if (property_exists($plan, 'task_type') && $plan->task_type === null) {
                $plan->task_type = 'explain';
            }
        } elseif (!$userHasCode && $askEdit && $this->looksLikeCode($lastAssistant)) {
            $plan->needs_verbatim_source = true;
            $plan->source_where          = 'last_assistant';
            if (property_exists($plan, 'task_type') && $plan->task_type === 'generate') {
                $plan->task_type = 'edit';
            }
        }

        // 6) Clamp e defaults finali
        $plan->subject = is_string($plan->subject) ? trim($plan->subject) : '';
        if (!is_array($plan->avoid))  $plan->avoid  = [];
        if (!is_array($plan->style))  $plan->style  = [];
        if (!is_string($plan->length)) $plan->length = '';

        return $plan;
    }

    // ----------------- Helpers -----------------
    private function lastByRole(array $hist, string $role): string {
        for ($i = count($hist) - 1; $i >= 0; $i--) {
            if (($hist[$i]['role'] ?? '') === $role) return (string)($hist[$i]['content'] ?? '');
        }
        return '';
    }

    private function notEqualLoose(string $a, string $b): bool {
        $na = trim(preg_replace('/\s+/', ' ', $a));
        $nb = trim(preg_replace('/\s+/', ' ', $b));
        return $na !== $nb;
    }

    private function looksLikeCode(string $s): bool {
        $t = trim($s);
        if ($t === '') return false;
        if (preg_match('/^```/m', $t)) return true;
        if (str_starts_with($t, '<?php')) return true;
        if (preg_match('/class\s+\w+|function\s+\w+\s*\(|\{|\};|<\/\w+>/', $t)) return true;
        return false;
    }

    private function looksExplainIntent(string $s): bool {
        $t = mb_strtolower($s);
        return str_contains($t,'spiega') || str_contains($t,'spiegami')
            || str_contains($t,'explain') || str_contains($t,'descrivi')
            || str_contains($t,'commenta') || str_contains($t,'analizza');
    }

    private function looksEditIntent(string $s): bool {
        $t = mb_strtolower($s);
        return str_contains($t,'riscrivi') || str_contains($t,'refactor')
            || str_contains($t,'rifattorizza') || str_contains($t,'migliora')
            || str_contains($t,'ottimizza') || str_contains($t,'continua')
            || str_contains($t,'completa') || str_contains($t,'correggi')
            || str_contains($t,'fixa') || str_contains($t,'fix');
    }

    private function looksAtomicQuestion(string $s): bool {
        $t = trim($s);
        $len = mb_strlen($t);
        $lower = mb_strtolower($t);
        $hasQ  = str_contains($t, '?');
        $wh    = preg_match('/\b(chi|cosa|come|quando|dove|perche|perché|quanto|quale|qual[\'\s]è)\b/u', $lower);
        return ($len <= 160 && ($hasQ || $wh));
    }

    private function looksJsonRequested(string $s): bool {
        return (bool)preg_match('/\b(json|in\s+json|formato\s+json)\b/i', $s);
    }

    private function looksYamlRequested(string $s): bool {
        return (bool)pregmatch('/\b(yaml|in\s+yaml|formato\s+yaml)\b/i', $s);
    }

    private function looksCsvRequested(string $s): bool {
        return (bool)preg_match('/\b(csv|in\s+csv|formato\s+csv)\b/i', $s);
    }

    private function looksCodeRequested(string $s): bool {
        $t = mb_strtolower($s);
        if (preg_match('/```/', $s)) return true;
        if ($this->looksLikeCode($s)) return true;
        return str_contains($t,'codice') || str_contains($t,'script')
            || str_contains($t,'implementa') || str_contains($t,'scrivi codice')
            || str_contains($t,'snippet') || str_contains($t,'classe')
            || str_contains($t,'funzione');
    }

    private function inferFormatFromUser(string $s): string {
        if ($this->looksJsonRequested($s)) return 'json';
        if ($this->looksYamlRequested($s)) return 'yaml';
        if ($this->looksCsvRequested($s))  return 'csv';
        if ($this->looksCodeRequested($s)) return 'code';
        return 'prose';
    }

    private function sanitizeStyleTokens(array $tokens): array {
        $allowed = [
            'diretto','colloquiale','professionale','tecnico','sintetico','dettagliato',
            'ironico','sarcastico','formale','informale','amichevole','pragmatico',
            'chiaro','neutro','creativo'
        ];
        $map = [
            'friendly' => 'amichevole',
            'conciso'  => 'sintetico',
            'preciso'  => 'tecnico',
        ];
        $out = [];
        foreach ($tokens as $t) {
            if (!is_string($t)) continue;
            $k = mb_strtolower(trim($t));
            if (mb_strlen($k) > 30) continue;
            if (preg_match('/\d/', $k)) continue;
            $k = $map[$k] ?? $k;
            if (in_array($k, $allowed, true)) $out[] = $k;
        }
        return array_values(array_unique($out));
    }

    private function normalizeJson(?string $json): string {
        $t = trim((string)$json);
        if ($t === '' || $t === '[]') return '{}';
        return $t;
    }

    private function extractJsonObject(string $s): ?array {
        $t = trim($s);
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $t, $m)) {
            try {
                $obj = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
                return is_array($obj) ? $obj : null;
            } catch (\Throwable $e) {}
        }
        return null;
    }

    private function renderHistoryPlain(array $history, int $perMsgMax = 240, int $totalMax = 1200): string {
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

    private function fallbackShorten(string $s): string {
        $t = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($t) <= 120 ? $t : mb_substr($t, 0, 118).'…';
    }

    private function fallbackContextSummary(string $s): string {
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/```[\s\S]*?```/m', '[codice]', $s)));
        if ($t === '') return 'Richiesta breve e generica dell’utente.';
        return 'L’utente ora vuole: '.mb_strtolower(mb_substr($t, 0, 140)).(mb_strlen($t)>140?'…':'');
    }

    private function guessLanguage(string $s): ?string {
        return preg_match('/[àèéìòóù]/i', $s) ? 'it' : null;
    }
}
