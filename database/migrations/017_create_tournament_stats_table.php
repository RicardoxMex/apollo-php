<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_stats', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->string('label', 100);
            $table->enum('type', ['number', 'boolean']);
            $table->boolean('per_player')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_stats');
    }
};