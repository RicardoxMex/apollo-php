<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeMigrationCommand extends Command
{
    protected string $signature = 'make:migration';
    protected string $description = 'Create a new migration file';

    public function handle(): int
    {
        global $argv;

        if (count($argv) < 3) {
            $this->error('Migration name is required.');
            $this->line('Usage: php apollo make:migration <name>');
            $this->line('Examples:');
            $this->line('  php apollo make:migration create_products_table');
            $this->line('  php apollo make:migration add_price_to_products');
            return 1;
        }

        $name = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $argv[2]));
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name); // camelCase -> snake

        if (!$name) {
            $this->error('Invalid migration name.');
            return 1;
        }

        $migrationsDir = realpath(__DIR__ . '/../../../database') . '/migrations';

        // Siguiente número secuencial (001, 002, ...)
        $next = 1;
        foreach (glob($migrationsDir . '/*.php') ?: [] as $file) {
            if (preg_match('#/(\d{3})_#', $file, $m)) {
                $next = max($next, (int) $m[1] + 1);
            }
        }

        $prefix = str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        $table = $this->guessTable($name);
        $filePath = $migrationsDir . "/{$prefix}_{$name}.php";

        if (file_exists($filePath)) {
            $this->error("Migration already exists: {$filePath}");
            return 1;
        }

        $stub = <<<PHP
<?php

use Apollo\Core\Database\Migration;
use Apollo\Core\Database\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (\$table) {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP;

        if (file_put_contents($filePath, $stub) === false) {
            $this->error("Could not write file: {$filePath}");
            return 1;
        }

        $this->info("✅ Migration created: {$name}");
        $this->line("  Location: {$filePath}");
        $this->line("  Table:    {$table}");

        return 0;
    }

    /**
     * Derivar el nombre de tabla para migrations create_<table>_table / add_*_to_<table>
     */
    private function guessTable(string $name): string
    {
        if (preg_match('/^create_(.+?)(_table)?$/', $name, $m)) {
            return $m[1];
        }

        if (preg_match('/^add_.+_to_(.+)$/', $name, $m)) {
            return $m[1];
        }

        return str_replace('_', '', $name);
    }
}