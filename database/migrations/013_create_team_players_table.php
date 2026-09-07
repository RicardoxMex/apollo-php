<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_players', function ($table) {
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('player_id')->constrained('players')->onDelete('cascade');
            $table->string('jersey_number', 20)->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();

            // PK compuesta: un jugador pertenece a un equipo una sola vez
            $table->primaryKey(['team_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_players');
    }
};