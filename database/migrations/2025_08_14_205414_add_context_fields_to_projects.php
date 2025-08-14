<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('projects', function (Blueprint $t) {
            if (!Schema::hasColumn('projects','thread_state')) $t->json('thread_state')->nullable();
            if (!Schema::hasColumn('projects','short_summary')) $t->mediumText('short_summary')->nullable();
        });
    }
    public function down(): void {
        Schema::table('projects', function (Blueprint $t) {
            if (Schema::hasColumn('projects','thread_state')) $t->dropColumn('thread_state');
            if (Schema::hasColumn('projects','short_summary')) $t->dropColumn('short_summary');
        });
    }
};
