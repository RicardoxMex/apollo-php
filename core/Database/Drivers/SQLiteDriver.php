<?php
namespace Apollo\Core\Database\Drivers;

/**
 * SQLiteDriver — conexión SQLite (archivo o :memory:) sin servidor.
 * Habilita PRAGMA foreign_keys para respetar las constraints del Schema.
 */
class SQLiteDriver implements DriverInterface
{
    public function connect(array $config): \PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);

        $pdo = new \PDO($dsn, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    public function getDsn(array $config): string
    {
        $database = $config['database'] ?? ':memory:';

        // Crear el archivo si no existe (a menos que sea SQLite en memoria)
        if ($database !== ':memory:') {
            $path = str_replace('\\', '/', $database);

            if (!file_exists($path)) {
                $dir = dirname($path);
                if ($dir && !is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @touch($path);
            }
        }

        return "sqlite:{$database}";
    }

    public function getOptions(array $config): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
    }

    public function getLastInsertId(\PDO $pdo, ?string $name = null): string
    {
        return (string) $pdo->lastInsertId();
    }
}