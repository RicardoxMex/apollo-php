<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draws', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->enum('type', ['groups', 'bracket', 'manual']);
            $table->integer('version')->unsigned()->default(1);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draws');
    }
};