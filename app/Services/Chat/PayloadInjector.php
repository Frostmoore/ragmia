<?php
declare(strict_types=1);

namespace App\Services\Chat;

final class PayloadInjector
{
    /**
     * Inietta $extra (già compresso) subito dopo il tag [CONTEXT].
     * Se [CONTEXT] non esiste, prepend all'inizio.
     */
    public function inject(array $final, string $extra): array
    {
        if (!is_string($extra) || trim($extra) === '') return $final;

        $payload = (string)($final['payload'] ?? '');
        if ($payload === '') return $final;

        $tag = "[CONTEXT]";
        if (strpos($payload, $tag) !== false) {
            $final['payload'] = preg_replace(
                '/(\[CONTEXT\]\s*)/u',
                "$1\n".$extra."\n",
                $payload,
                1
            );
        } else {
            $final['payload'] = $extra."\n\n".$payload;
        }

        // (opzionale) metti una copia nell'area debug
        if (isset($final['debug'])) {
            $final['debug'] .= "\n\n[INJECTED]\n".$extra;
        } else {
            $final['debug'] = "[INJECTED]\n".$extra;
        }
        return $final;
    }
}
