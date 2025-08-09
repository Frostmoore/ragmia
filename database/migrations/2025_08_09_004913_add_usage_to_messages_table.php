<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->integer('tokens_input')->default(0)->after('content');
            $table->integer('tokens_output')->default(0)->after('tokens_input');
            $table->integer('tokens_total')->default(0)->after('tokens_output');
            $table->decimal('cost_usd', 12, 6)->default(0)->after('tokens_total');
            $table->string('model')->nullable()->after('cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['tokens_input','tokens_output','tokens_total','cost_usd','model']);
        });
    }
};

