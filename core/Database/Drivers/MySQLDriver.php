<?php
namespace Apollo\Core\Database\Drivers;

class MySQLDriver implements DriverInterface
{
    public function connect(array $config): \PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);

        return new \PDO($dsn, $config['username'], $config['password'], $options);
    }

    public function getDsn(array $config): string
    {
        $charset = $config['charset'] ?? 'utf8mb4';
        $port = isset($config['port']) ? ";port={$config['port']}" : "";

        return "mysql:host={$config['host']}{$port};dbname={$config['database']};charset={$charset}";
    }

    public function getOptions(array $config): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['charset']} COLLATE {$config['collation']}",
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ];
    }

    public function getLastInsertId(\PDO $pdo, ?string $name = null): string
    {
        return $pdo->lastInsertId();
    }
}