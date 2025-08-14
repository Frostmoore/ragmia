<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['name', 'folder_id', 'path', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    /** Scope: limita per utente */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
