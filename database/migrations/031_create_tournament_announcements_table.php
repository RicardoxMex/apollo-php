<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_announcements', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->string('title', 200);
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            // Índices
            $table->index('tournament_id');
            $table->index(['tournament_id', 'pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_announcements');
    }
};