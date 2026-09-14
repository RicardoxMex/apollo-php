<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function ($table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->onDelete('cascade');
            $table->foreignId('registration_id')->nullable()->constrained('tournament_registrations')->onDelete('set null');
            $table->foreignId('participant_id')->nullable()->constrained('tournament_participants')->onDelete('set null');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('method', 30);                 // efectivo | transferencia | tarjeta | otro
            $table->string('reference', 150)->nullable(); // folio/comprobante
            $table->enum('status', ['pending', 'paid', 'refunded'])->default('paid');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Índices
            $table->index('tournament_id');
            $table->index('registration_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};