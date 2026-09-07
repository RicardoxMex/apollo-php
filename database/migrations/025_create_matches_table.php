<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->foreignId('draw_match_id')->nullable()->constrained('draw_matches')->onDelete('set null');
            $table->integer('round_number')->unsigned();
            $table->integer('match_number')->unsigned()->nullable();
            $table->foreignId('participant_a_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->foreignId('participant_b_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->foreignId('winner_participant_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->enum('status', ['pending', 'scheduled', 'live', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tournament_id', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};