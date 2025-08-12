<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_context_hints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('tag', 64);      // es: 'topic', 'style', 'avoid', 'format', 'language', 'tone'
            $table->string('value', 255);   // es: 'humor/entertainment', 'moderno', 'no-code', 'it'
            $table->float('weight')->default(1.0);  // confidenza
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('source', 32)->default('planner'); // planner|rule|manual
            $table->timestamps();

            // dedupe per utente+progetto+tag+value (case-insensitive su MySQL default collation)
            $table->unique(['user_id','project_id','tag','value'], 'u_ctx_hints_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_context_hints');
    }
};
