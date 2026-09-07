<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function ($table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact', 255)->nullable();
            $table->string('image', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};