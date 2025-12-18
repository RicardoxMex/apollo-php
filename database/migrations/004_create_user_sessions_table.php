<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token_id', 64)->unique(); // JTI del JWT
            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_revoked')->default(false);
            $table->timestamps();
            
            // Índices
            $table->index(['user_id', 'is_revoked']);
            $table->index('expires_at');
            $table->index('token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};