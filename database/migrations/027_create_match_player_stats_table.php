<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_player_stats', function ($table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('participant_id')->constrained('tournament_participants')->onDelete('cascade');
            $table->foreignId('player_id')->nullable()->constrained('players')->onDelete('set null');
            $table->foreignId('stat_id')->constrained('tournament_stats')->onDelete('cascade');
            $table->decimal('value', 12, 2)->default(0);

            $table->index(['match_id', 'stat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_player_stats');
    }
};