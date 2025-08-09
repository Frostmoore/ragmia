<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['parent_id','name']); // niente doppioni nello stesso livello
        });
    }
    public function down(): void {
        Schema::dropIfExists('folders');
    }
};
