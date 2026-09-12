<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeSeederCommand extends Command
{
    protected string $signature = 'make:seeder';
    protected string $description = 'Create a new seeder class';

    public function handle(): int
    {
        global $argv;

        if (count($argv) < 3) {
            $this->error('Seeder name is required.');
            $this->line('Usage: php apollo make:seeder <Name>');
            $this->line('Example: php apollo make:seeder ProductSeeder');
            return 1;
        }

        $seederName = ucfirst(preg_replace('/[^A-Za-z0-9_]/', '', $argv[2]));

        if (!str_ends_with($seederName, 'Seeder')) {
            $seederName .= 'Seeder';
        }

        if (!$seederName) {
            $this->error('Invalid seeder name.');
            return 1;
        }

        $seedsDir = realpath(__DIR__ . '/../../../database') . '/seeds';
        $filePath = $seedsDir . '/' . $seederName . '.php';

        if (file_exists($filePath)) {
            $this->error("Seeder already exists: {$seederName}");
            return 1;
        }

        $stub = <<<PHP
<?php

class {$seederName}
{
    public function run()
    {
        // TODO: insertar datos seed
        // Ejemplo:
        // \\App\\Models\\Product::create([
        //     'name' => 'Product example',
        //     'price' => 100,
        // ]);
    }
}

PHP;

        if (file_put_contents($filePath, $stub) === false) {
            $this->error("Could not write file: {$filePath}");
            return 1;
        }

        $this->info("✅ Seeder created: {$seederName}");
        $this->line("  Location: {$filePath}");
        $this->line('  Runners:  php apollo db:seed');

        return 0;
    }
}