<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->enum('role', ['user','assistant']);
            $table->longText('content');
            $table->timestamps();
            $table->index(['project_id','created_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('messages');
    }
};
