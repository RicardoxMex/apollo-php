<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function ($table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users');
            $table->foreignId('season_id')->nullable()->constrained('tournament_seasons')->onDelete('set null');
            $table->string('title', 200);
            $table->string('slug')->unique();
            $table->string('sport', 100);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->boolean('is_online')->default(false);
            $table->string('image', 500)->nullable();
            $table->enum('status', ['draft', 'paused', 'open', 'live', 'finished'])->default('draft');
            $table->enum('format', ['single_elimination', 'double_elimination', 'round_robin', 'groups', 'league']);
            $table->integer('max_participants')->unsigned();
            $table->integer('clasificados_eliminacion')->unsigned()->default(0);
            // Round-robin / liga: true = fase de todos contra todos a ida y
            // vuelta (doble ronda) antes del bracket.
            $table->boolean('ida_vuelta')->default(false);
            $table->integer('players_per_team')->unsigned()->nullable();
            $table->boolean('is_individual')->default(false);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->timestamp('registration_deadline')->nullable();
            $table->decimal('registration_fee', 12, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->tinyInteger('minimum_age')->unsigned()->nullable();
            $table->text('rules')->nullable();
            $table->integer('max_substitutes')->unsigned()->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('organizer_id');
            $table->index(['status', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};