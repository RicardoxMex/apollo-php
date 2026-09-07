<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_scores', function ($table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('stat_id')->constrained('tournament_stats')->onDelete('cascade');
            $table->decimal('score_a', 12, 2)->default(0);
            $table->decimal('score_b', 12, 2)->default(0);

            // Un marcador por (partido, stat)
            $table->unique(['match_id', 'stat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_scores');
    }
};