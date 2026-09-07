<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_prizes', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->tinyInteger('position')->unsigned();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('label', 150)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_prizes');
    }
};