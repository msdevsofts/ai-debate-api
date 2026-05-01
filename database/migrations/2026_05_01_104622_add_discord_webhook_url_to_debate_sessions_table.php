<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('debate_sessions', function (Blueprint $table) {
            $table->string('discord_webhook_url')->nullable()->after('discord_channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debate_sessions', function (Blueprint $table) {
            $table->dropColumn('discord_webhook_url');
        });
    }
};
