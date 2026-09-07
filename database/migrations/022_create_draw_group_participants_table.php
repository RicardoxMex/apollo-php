<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_group_participants', function ($table) {
            $table->foreignId('group_id')->constrained('draw_groups')->onDelete('cascade');
            $table->foreignId('tournament_participant_id')->constrained('tournament_participants')->onDelete('cascade');
            $table->integer('position')->unsigned()->nullable();

            // PK compuesta: un participante aparece una sola vez por grupo
            $table->primaryKey(['group_id', 'tournament_participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_group_participants');
    }
};