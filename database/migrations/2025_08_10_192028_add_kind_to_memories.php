<?php
// database/migrations/xxxx_xx_xx_xxxxxx_add_kind_to_memories.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('kind', 32)->default('note')->after('project_id');
            $table->index(['project_id','kind']);
        });
        // opzionale: crea o aggiorna una riga "summary" per ogni progetto che già ha roba
        // lascerei a comando artisan se serve.
    }
    public function down() {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropIndex(['project_id','kind']);
            $table->dropColumn('kind');
        });
    }
};
