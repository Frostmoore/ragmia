<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // es. "ScuoleGuida"
            $table->foreignId('folder_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('path')->unique();       // es. "Consorzio/ScuoleGuida" o solo "ScuoleGuida"
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('projects');
    }
};
