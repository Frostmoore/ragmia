<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('message_embeddings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained()->cascadeOnDelete();
            $t->json('embedding'); // array di float
            $t->timestamps();
            $t->index('message_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('message_embeddings');
    }
};
