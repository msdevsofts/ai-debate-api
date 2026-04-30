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
        Schema::create('debate_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('topic');
            $table->string('discord_thread_id')->nullable();
            $table->integer('current_turn')->default(0);
            $table->integer('max_turns')->default(10);
            $table->string('dify_conversation_id')->nullable();
            $table->string('status')->default('running');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debate_sessions');
    }
};
