<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class MessageEmbedding extends Model
{
    protected $fillable = ['message_id','embedding'];
    protected $casts = ['embedding' => 'array'];
}
