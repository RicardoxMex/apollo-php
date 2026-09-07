<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_groups', function ($table) {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->onDelete('cascade');
            $table->string('name', 100);
            $table->integer('position')->unsigned();

            $table->index('draw_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_groups');
    }
};