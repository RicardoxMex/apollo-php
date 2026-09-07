<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_seasons', function ($table) {
            $table->id();
            $table->string('name', 150);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_seasons');
    }
};