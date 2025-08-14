<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('global_contexts', function (Blueprint $t) {
            $t->id();
            // se vuoi un contesto per-utente, tieni user_id; se no lascialo null e usa una singola riga
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->json('global_state')->nullable();        // JSON strutturato
            $t->mediumText('global_short_summary')->nullable(); // 10–20 righe
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('global_contexts');
    }
};
