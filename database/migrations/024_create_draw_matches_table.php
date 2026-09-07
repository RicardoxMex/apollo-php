<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_matches', function ($table) {
            $table->id();
            $table->foreignId('round_id')->constrained('draw_rounds')->onDelete('cascade');
            $table->integer('match_number')->unsigned();
            $table->foreignId('participant_a_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->foreignId('participant_b_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->foreignId('winner_participant_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->enum('status', ['pending', 'scheduled', 'live', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('next_match_id')->nullable()->constrained('draw_matches')->onDelete('set null');
            $table->enum('next_slot', ['a', 'b'])->nullable();

            $table->index(['round_id', 'match_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_matches');
    }
};