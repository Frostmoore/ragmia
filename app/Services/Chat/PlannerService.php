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

        $sys = <<<SYS
Sei un planner-compressor conservativo.
- "final_user": riformula in ≤ ~18 parole, stesso significato, niente invenzioni.
- "context_summary": 1 riga neutra su cosa vuole ORA l’utente.
- Estrai subject/style/avoid solo se chiarissimi.
- Se l’utente ha incollato CODICE o testo da analizzare/spiegare/rifattorizzare, imposta:
  - "needs_verbatim_source": true
  - "source_where": "last_user"
- Se chiede di modificare/continuare l’ULTIMO OUTPUT dell’assistente (ed è testo/codice), usa "last_assistant".
Ritorna SOLO JSON.
SYS;

        $usr = <<<USR
[USER_PROFILE]
{$this->normalizeJson($userProfileJson)}

[PROJECT_MEMORY]
{$this->normalizeJson($projectMemoryJson)}

[RECENT_HISTORY]
{$hist}

[USER_PROMPT]
{$currentPrompt}

JSON atteso:
{
  "final_user": "...",
  "subject": "",
  "style": [],
  "avoid": [],
  "language": "",
  "length": "",
  "format": "",
  "include_full_history": false,
  "compressed_context": "",
  "context_summary": "",
  "needs_verbatim_source": false,
  "source_where": "none"
}
USR;

        $resp = $this->llm->call($compressModel, [
            ['role'=>'system','content'=>$sys],
            ['role'=>'user','content'=>$usr],
        ], ['max_tokens'=>500,'temperature'=>0]);

        $rawText = (string)($resp['text'] ?? '');
        $arr     = $this->extractJsonObject($rawText) ?? [];
        $plan    = Plan::fromArray($arr);
        $plan->raw = $rawText;

        // ------- Heuristics correttive (robuste, zero fantasia) -------
        $lastUser      = $this->lastByRole($historyArr, 'user');
        $lastAssistant = $this->lastByRole($historyArr, 'assistant');
        $userHasCode   = $this->looksLikeCode($lastUser);
        $askExplain    = $this->looksExplainIntent($currentPrompt);
        $askEdit       = $this->looksEditIntent($currentPrompt);

        if ($userHasCode && $askExplain) {
            $plan->needs_verbatim_source = true;
            $plan->source_where = 'last_user';
            $plan->task_type = 'explain';
        } elseif (!$userHasCode && $askEdit && $this->looksLikeCode($lastAssistant)) {
            $plan->needs_verbatim_source = true;
            $plan->source_where = 'last_assistant';
            if ($plan->task_type === 'generate') $plan->task_type = 'edit';
        }

        if ($plan->final_user === '') {
            $plan->final_user = $this->fallbackShorten($currentPrompt);
        }
        if ($plan->context_summary === '') {
            $plan->context_summary = $this->fallbackContextSummary($currentPrompt);
        }
        if ($plan->language === '') {
            $plan->language = $this->guessLanguage($currentPrompt) ?: 'it';
        }

        return $plan;
    }

    // ----------------- Helpers -----------------
    private function lastByRole(array $hist, string $role): string {
        for ($i=count($hist)-1; $i>=0; $i--) {
            if (($hist[$i]['role'] ?? '') === $role) return (string)($hist[$i]['content'] ?? '');
        }
        return '';
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

    private function normalizeJson(?string $json): string {
        $t = trim((string)$json);
        if ($t === '' || $t === '[]') return '{}';
        return $t;
    }

    private function extractJsonObject(string $s): ?array {
        $t = trim($s);
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $t, $m)) {
            try { $obj = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR); return is_array($obj)?$obj:null; }
            catch (\Throwable $e) {}
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
