<?php

// app/Models/UserMemory.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMemory extends Model
{
    protected $fillable = ['user_id','kind','content'];
    protected $casts = ['content' => 'array'];

    public static function forProfile(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId, 'kind' => 'profile'],
            ['content' => []]
        );
    }
}
