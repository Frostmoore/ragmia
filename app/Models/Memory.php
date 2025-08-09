<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    protected $fillable = ['project_id','content'];

    public function project(){ return $this->belongsTo(Project::class); }
}
