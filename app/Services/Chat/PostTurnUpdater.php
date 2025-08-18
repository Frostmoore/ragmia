<?php
// app/Services/Chat/PostTurnUpdater.php
// App/Services/Chat/PostTurnUpdater.php
namespace App\Services\Chat;

use App\Services\LLM\LlmClient;

class PostTurnUpdater {
    public function __construct(private LlmClient $llm, private MemoryService $mem, private MemoryMerger $merger) {}

    public function update(string $compressModel, int $projectId, ?int $userId, array $frame, string $userProfileJson, string $projectMemoryJson, string $prompt, string $answer): array
    {
        $usage = null;

        // 1) PROJECT MEMORY (come già facevi): prompt snello
        $projPrompt = <<<EOT
        Sei un gestore di memorie di progetto.

        Aggiorna la memoria SOLO con fatti utili e persistenti (tema, scelte stilistiche, vincoli di formato).
        Ignora testi creativi, saluti, cose effimere.

        Ignora checklist operative, runbook, passi rapidi, diagnostica di rete/HTTP: non sono memorie di progetto.

        Output SOLO JSON: {"memory":"..."} (stringa JSON valida o vuota).

        MEMORIA_CORRENTE:
        {$projectMemoryJson}

        SCAMBIO:
        USER: {$prompt}
        ASSISTANT: {$answer}
        EOT;

        try {
            $res = $this->llm->call($compressModel, [
                ['role'=>'system','content'=>'Rispondi solo con JSON {"memory":"..."}'],
                ['role'=>'user','content'=>$projPrompt],
            ], ['max_tokens'=>500,'temperature'=>0]);

            $usage = ['model'=>$compressModel,'tokens'=>(int)($res['usage']['total'] ?? 0)];
            $new = $res['text'] ?? '';
            $memStr = $this->extractMemory($new);
            if ($memStr !== null && trim($memStr) !== '') {
                $this->mem->saveProjectMemoryJson($projectId, $memStr);
                $projectMemoryJson = $memStr;
            }
        } catch (\Throwable $e) {
            $usage = ['model'=>$compressModel,'tokens'=>0,'error'=>$e->getMessage()];
        }

        // 2) USER PROFILE: aggiorna quando
        // - è vuoto
        // - oppure emergono preferenze durevoli (lingua, toni, “mai in francese”, “dammi output conciso”, ecc.)
        if ($userId) {
            $needUpdate = ($userProfileJson === '' || mb_strlen($userProfileJson) < 4);
            $profilePrompt = <<<EOT
Sei un gestore di profilo utente (persistente).

Dal seguente scambio estrai SOLO preferenze durevoli (lingua, tono, "mai in X", livello di dettaglio, formati preferiti).
NON includere temi del progetto o dettagli effimeri.
Se NON ci sono preferenze nuove, restituisci il profilo invariato.

Output SOLO JSON, schema:
{"profile": { "language":"it|en|...", "tone":["diretto","ironico",...], "avoid":["francese",...], "formats_preferred":["code","prose","steps",... ] }}

PROFILO_CORRENTE:
{$userProfileJson}

SCAMBIO:
USER: {$prompt}
ASSISTANT: {$answer}
EOT;

            try {
                $res2 = $this->llm->call($compressModel, [
                    ['role'=>'system','content'=>'Rispondi solo con JSON valido con chiave "profile"'],
                    ['role'=>'user','content'=>$profilePrompt],
                ], ['max_tokens'=>500,'temperature'=>0]);

                $usage['tokens'] = (int)($usage['tokens'] ?? 0) + (int)($res2['usage']['total'] ?? 0);

                $raw = trim($res2['text'] ?? '');
                if (preg_match('/\{.*\}/s', $raw, $m)) {
                    $obj = json_decode($m[0], true);
                    $newP = $obj['profile'] ?? null;
                    if (is_array($newP) && !empty($newP)) {
                        // merge con precedenza alle nuove preferenze
                        $curr = $userProfileJson ? json_decode($userProfileJson, true) : [];
                        $merged = $this->mergeProfile($curr, $newP);
                        $this->mem->saveUserProfileJson($userId, json_encode($merged, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    }
                }
            } catch (\Throwable $e) {
                // silenziosamente ignora errori del profilo
            }
        }

        return ['compress_usage'=>$usage];
    }

    private function extractMemory(string $raw): ?string {
        if (preg_match('/\{.*\}/s', trim($raw), $m)) {
            $obj = json_decode($m[0], true);
            if (isset($obj['memory'])) return trim((string)$obj['memory']);
        }
        return null;
    }

    private function mergeProfile(array $curr, array $new): array {
        $out = $curr;
        foreach (['language'] as $k) {
            if (!empty($new[$k])) $out[$k] = $new[$k];
        }
        foreach (['tone','avoid','formats_preferred'] as $k) {
            $a = (array)($curr[$k] ?? []);
            $b = (array)($new[$k] ?? []);
            $out[$k] = array_values(array_unique(array_merge($a,$b)));
        }
        return $out;
    }
}

