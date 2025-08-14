<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    protected $fillable = ['name', 'parent_id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** Scope: limita per utente */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
