<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['name','folder_id','path'];

    public function folder(){ return $this->belongsTo(Folder::class); }
    public function messages(){ return $this->hasMany(Message::class); }
    public function memories(){ return $this->hasMany(Memory::class); }
}
