<?php
// app/Services/Chat/MemoryMerger.php
namespace App\Services\Chat;

class MemoryMerger
{
    public function mergeProject(array $old, array $new): array
    {
        $out = $old;
        foreach (['theme','subject','genre','format','language','length','notes'] as $k) {
            $v = trim((string)($new[$k] ?? ''));
            if ($v !== '') $out[$k] = $v;
        }
        // prefs.style/avoid
        $out['prefs']['style'] = array_values(array_unique(array_filter(array_map('strval', array_merge(
            (array)($old['prefs']['style'] ?? []), (array)($new['prefs']['style'] ?? [])
        )))));
        $out['prefs']['avoid'] = array_values(array_unique(array_filter(array_map('strval', array_merge(
            (array)($old['prefs']['avoid'] ?? []), (array)($new['prefs']['avoid'] ?? [])
        )))));
        // copy-through other prefs
        foreach (($new['prefs'] ?? []) as $k=>$v) {
            if (!in_array($k, ['style','avoid'], true)) $out['prefs'][$k] = $v;
        }
        // arrays cumulativi
        foreach (['facts','entities','goals'] as $k) {
            $o = (array)($old[$k] ?? []);
            $n = (array)($new[$k] ?? []);
            if ($o || $n) $out[$k] = array_values(array_unique(array_merge($o,$n), SORT_REGULAR));
        }
        return $out;
    }

    public function mergeUserProfile(array $old, array $new): array
    {
        $out = $old;
        if (!empty($new['identity'])) {
            $out['identity'] = array_merge($out['identity'] ?? [], $new['identity']);
        }
        foreach (['role'] as $k) if (!empty($new[$k])) $out[$k] = (string)$new[$k];

        if (!empty($new['skills'])) {
            $out['skills'] = array_values(array_unique(array_map('strval',
                array_merge((array)($out['skills'] ?? []), (array)$new['skills'])
            )));
        }
        if (!empty($new['preferences'])) {
            foreach ($new['preferences'] as $k=>$v) {
                $out['preferences'][$k] = isset($out['preferences'][$k]) && is_array($out['preferences'][$k]) && is_array($v)
                    ? array_replace_recursive($out['preferences'][$k], $v)
                    : $v;
            }
        }
        if (!empty($new['projects'])) {
            foreach ($new['projects'] as $k=>$v) $out['projects'][$k] = $v;
        }
        foreach (['fitness','diet','interests','goals','notes'] as $k) {
            if (array_key_exists($k, $new)) $out[$k] = $new[$k];
        }
        return $out;
    }
}
