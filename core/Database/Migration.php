<?php

namespace Apollo\Core\Database;

abstract class Migration
{
    public function up(): void
    {
        // Override in child classes
    }

    public function down(): void
    {
        // Override in child classes
    }
}