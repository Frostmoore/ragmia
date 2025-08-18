<?php
// App/Services/Chat/PreTurnProfileUpdater.php
namespace App\Services\Chat;

use App\Services\LLM\LlmClient;

class PreTurnProfileUpdater {
    public function __construct(private LlmClient $llm, private MemoryService $mem) {}

    public function ensureBeforeTurn(string $compressModel, int $userId, string $prompt, string $currentProfileJson): string
    {
        $curr = trim($currentProfileJson ?? '');
        $needs = ($curr === '' || $curr === '{}' || $curr === '[]' || $this->hasStrongPreferenceSignal($prompt));

        if (!$userId || !$needs) return $currentProfileJson ?: '{}';

        // prompt minimalista (pochi token)
        $p = <<<EOT
        Sei un estrattore di preferenze utente PERSISTENTI dal SOLO messaggio sottostante.
        Se non emergono preferenze durevoli, restituisci {}.

        Preferenze ammesse:
        - language: "it"|"en"|...
        - tone: array (es. ["diretto","ironico"])
        - avoid: array (es. ["francese"])
        - formats_preferred: array (es. ["prose","code","steps"])

        NON salvare istruzioni operative, checklist, runbook, riferimenti a errori (es. 504/JSON parsing), o richieste una tantum.


        Output SOLO JSON oggetto (no testo fuori da {}).

        MESSAGGIO:
        {$prompt}
        EOT;

        try {
            $res = $this->llm->call($compressModel, [
                ['role'=>'system','content'=>'Rispondi solo con JSON oggetto.'],
                ['role'=>'user','content'=>$p],
            ], ['max_tokens'=>200,'temperature'=>0]);

            $raw = trim($res['text'] ?? '');
            if ($raw === '' || $raw === 'Risposta vuota.') return $currentProfileJson ?: '{}';

            if (!preg_match('/\{.*\}/s', $raw, $m)) return $currentProfileJson ?: '{}';
            $obj = json_decode($m[0], true);
            if (!is_array($obj)) return $currentProfileJson ?: '{}';

            $merged = $this->merge($currentProfileJson ? json_decode($currentProfileJson, true) : [], $obj);
            $json = json_encode($merged, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $this->mem->saveUserProfileJson($userId, $json);

            return $json;
        } catch (\Throwable $e) {
            return $currentProfileJson ?: '{}';
        }
    }

    private function hasStrongPreferenceSignal(string $t): bool {
        $t = mb_strtolower($t);
        return (bool) preg_match('/\b(mai|sempre|preferisc[oa]|non voglio|parlami|scrivimi|in italiano|in inglese|francese)\b/u', $t);
    }

    private function merge(array $curr, array $new): array {
        $out = $curr;
        foreach (['language'] as $k) if (!empty($new[$k])) $out[$k] = $new[$k];
        foreach (['tone','avoid','formats_preferred'] as $k) {
            $a = (array)($curr[$k] ?? []);
            $b = (array)($new[$k] ?? []);
            if ($a || $b) $out[$k] = array_values(array_unique(array_map('strval', array_merge($a,$b))));
        }
        return $out ?: $curr;
    }
}
