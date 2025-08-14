<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ===== folders =====
        Schema::table('folders', function (Blueprint $table) {
            if (!Schema::hasColumn('folders', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()           // ->references('id')->on('users')
                    ->cascadeOnDelete()
                    ->after('id');
                $table->index(['user_id', 'parent_id']);
            }
        });

        // ===== projects =====
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete()
                    ->after('id');
                $table->index('user_id');
            }
        });

        // ===== messages =====
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->cascadeOnDelete()
                    ->after('id');
                $table->index(['user_id', 'project_id']);
            }
        });

        // ===== Backfill "soft" (non obbligatorio ma utile per non lasciare NULL) =====
        try {
            $firstUserId = DB::table('users')->min('id');

            if ($firstUserId) {
                // Metti un owner di default a cartelle/progetti senza user
                DB::table('folders')->whereNull('user_id')->update(['user_id' => $firstUserId]);
                DB::table('projects')->whereNull('user_id')->update(['user_id' => $firstUserId]);

                // Sincronizza messages.user_id con projects.user_id (solo dove manca)
                // NB: richiede MySQL/MariaDB. Se usi SQLite, fai un comando ad hoc.
                DB::statement("
                    UPDATE messages m
                    JOIN projects p ON p.id = m.project_id
                    SET m.user_id = p.user_id
                    WHERE m.user_id IS NULL
                ");
            }
        } catch (\Throwable $e) {
            // Non bloccare la migrazione se il backfill fallisce (es. DB diverso)
            logger()->warning('Backfill user_id parziale', ['err' => $e->getMessage()]);
        }

        // Facoltativo (quando hai sistemato le query lato app):
        // - rendere NOT NULL le colonne user_id
        //   -> serve doctrine/dbal per alter, quindi lo lascio a una migrazione successiva
        // - aggiungere vincoli di unicità "per utente"
        //   Esempio:
        //   Schema::table('projects', fn(Blueprint $t) => $t->unique(['user_id','path']));
        //   Schema::table('folders',  fn(Blueprint $t) => $t->unique(['user_id','parent_id','name']));
        // Fai prima pulizia dati e poi li attivi.
    }

    public function down(): void
    {
        // messages
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id', 'project_id']);
                $table->dropColumn('user_id');
            }
        });

        // projects
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id']);
                $table->dropColumn('user_id');
            }
        });

        // folders
        Schema::table('folders', function (Blueprint $table) {
            if (Schema::hasColumn('folders', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['user_id', 'parent_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
