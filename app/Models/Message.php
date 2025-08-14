<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'project_id',
        'user_id',
        'role',
        'content',
        'tokens_input',
        'tokens_output',
        'tokens_total',
        'cost_usd',
        'model',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Scope: limita per utente */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
