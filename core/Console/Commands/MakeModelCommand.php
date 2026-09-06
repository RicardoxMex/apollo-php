<?php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;

class MakeModelCommand extends Command
{
    protected string $signature = 'make:model';
    protected string $description = 'Create a new model class';

    public function handle(): int
    {
        global $argv;

        if (count($argv) < 3) {
            $this->error('Model name is required.');
            $this->line('Usage: php apollo make:model <ModelName> [--app=<app>]');
            return 1;
        }

        $modelName = ucfirst(preg_replace('/[^A-Za-z0-9_]/', '', $argv[2]));
        $appName = ucfirst($this->getAppOption($argv) ?: 'users');

        if (!$modelName) {
            $this->error('Invalid model name.');
            return 1;
        }

        $modelsDir = __DIR__ . '/../../../apps/' . $appName . '/Models';

        if (!is_dir($modelsDir) && !mkdir($modelsDir, 0755, true) && !is_dir($modelsDir)) {
            $this->error("Could not create directory: {$modelsDir}");
            return 1;
        }

        $filePath = $modelsDir . '/' . $modelName . '.php';

        if (file_exists($filePath)) {
            $this->error("Model already exists: {$modelName}");
            return 1;
        }

        $table = $this->tableize($modelName);

        $stub = <<<PHP
<?php

namespace Apps\\{$appName}\\Models;

use Apollo\\Core\\Database\\Model;

class {$modelName} extends Model
{
    protected \$table = '{$table}';

    protected \$fillable = [
        // Columnas asignables en masa
    ];

    protected \$hidden = [];

    protected \$casts = [];
}

PHP;

        if (file_put_contents($filePath, $stub) === false) {
            $this->error("Could not write file: {$filePath}");
            return 1;
        }

        $this->info("✅ Model created: {$modelName}");
        $this->line("  Location: " . (realpath($filePath) ?: $filePath));
        $this->line("  Table: {$table}");

        return 0;
    }

    private function getAppOption(array $argv): ?string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--app=')) {
                return substr($arg, 6);
            }
        }

        return null;
    }

    /**
     * Pluralización básica en inglés: Product -> products, Category -> categories
     */
    private function tableize(string $name): string
    {
        $word = strtolower($name);

        if (substr($word, -1) === 'y' && !in_array(substr($word, -2), ['ay', 'ey', 'oy', 'uy'])) {
            return substr($word, 0, -1) . 'ies';
        }

        if (preg_match('/(s|ss|x|z|ch|sh)$/', $word)) {
            return $word . 'es';
        }

        return $word . 's';
    }
}