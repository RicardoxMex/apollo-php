<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_rounds', function ($table) {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->onDelete('cascade');
            $table->integer('round_number')->unsigned();
            $table->string('name', 100)->nullable();

            $table->unique(['draw_id', 'round_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_rounds');
    }
};