<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limits', function ($table) {
            $table->id();
            $table->string('key', 100); // IP, user_id, etc.
            $table->string('type', 50); // login, api, etc.
            $table->integer('attempts')->default(1);
            $table->timestamp('window_start');
            $table->timestamp('expires_at');
            $table->timestamps();
            
            // Índices
            $table->unique(['key', 'type']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limits');
    }
};