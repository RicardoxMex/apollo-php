<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_registrations', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->foreignId('player_id')->nullable()->constrained('players');
            $table->foreignId('applicant_id')->constrained('users');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');
            $table->text('message')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // Anti doble-inscripción: una solicitud por (torneo, equipo) y (torneo, jugador).
            // En MySQL las NULL no colisionan en UNIQUE, así que dos restricciones separadas
            // cubren ambas variantes (por equipos y torneos individuales).
            $table->unique(['tournament_id', 'team_id']);
            $table->unique(['tournament_id', 'player_id']);
            $table->index(['tournament_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_registrations');
    }
};