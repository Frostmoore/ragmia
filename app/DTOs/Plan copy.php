<?php
// app/DTOs/Plan.php
namespace App\DTOs;

class Plan
{
    public string $task_type = 'generate';
    public string $theme = '';
    public string $genre = '';
    public string $format = '';
    /** @var string[] */
    public array $style = [];
    /** @var string[] */
    public array $avoid = [];
    public string $language = 'it';
    public string $length = '';

    public bool $include_full_history = false;
    /** @var array<int,array{role:string,content:string}> */
    public array $history = [];
    public string $compressed_context = '';

    public bool $needs_verbatim_source = false;
    public string $source_where = 'none'; // last_assistant|last_user|history_range|none
    /** @var array{0:int,1:int}|null */
    public ?array $source_range = null;
    public int $source_chars_max = 8000;
    public bool $ask_for_source = false;
    public string $ask_reason = '';

    public string $final_user = '';
    public bool $reset_context = false;          // quando il turno *cambia tema* (es. “qualcosa di divertente”)
    public bool $drop_memory_for_turn = false;   // non allegare [MEMORY] nel payload di questo turno
    public bool $update_user_profile = false;    // chiedi all’updater di valutare update profilo
    public array $user_profile_update = [];      // patch proposta dal compressore (es. {"language":"it","avoid":["francese"]})


    // raw planner JSON (per debug)
    public string $raw = '';
    public string $subject = '';

    public static function fromArray(?array $a): self {
        $p = new self();
        if (!$a) return $p;
        foreach ($a as $k=>$v) {
            if (property_exists($p, $k)) $p->$k = $v;
        }
        // auto-fix edit-like
        if (in_array($p->task_type, ['rewrite','edit','continue'], true) && $p->needs_verbatim_source === false) {
            $p->needs_verbatim_source = true;
            if ($p->source_where === 'none') $p->source_where = 'last_assistant';
        }
        return $p;
    }

    public function isEditLike(): bool {
        return in_array($this->task_type, ['rewrite','edit','continue'], true);
    }
}
