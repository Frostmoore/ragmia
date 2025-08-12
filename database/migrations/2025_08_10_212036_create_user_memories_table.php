<?php
// database/migrations/2025_08_10_000000_create_user_memories_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 50)->default('profile'); // per estensioni future
            $table->longText('content')->nullable();        // JSON (profilo utente)
            $table->timestamps();

            $table->unique(['user_id','kind']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
    public function down(): void {
        Schema::dropIfExists('user_memories');
    }
};
