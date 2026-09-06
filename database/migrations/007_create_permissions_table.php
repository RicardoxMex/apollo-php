<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function ($table) {
            $table->id();
            $table->string('name', 100)->unique(); // "users.view", "*"...
            $table->string('display_name', 150)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};