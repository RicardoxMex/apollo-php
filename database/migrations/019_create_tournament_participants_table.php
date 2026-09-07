<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_participants', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->foreignId('registration_id')->constrained('tournament_registrations')->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->foreignId('player_id')->nullable()->constrained('players');
            $table->integer('seed')->unsigned()->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Un participante resuelto por (torneo, equipo) y (torneo, jugador)
            $table->unique(['tournament_id', 'team_id']);
            $table->unique(['tournament_id', 'player_id']);
            $table->index('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_participants');
    }
};