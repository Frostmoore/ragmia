<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalContext extends Model
{
    protected $fillable = ['user_id','global_state','global_short_summary'];
    protected $casts = [
        'global_state' => 'array',
    ];
}
