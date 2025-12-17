<?php
namespace Apollo\Core\Database\Drivers;

interface DriverInterface
{
    public function connect(array $config): \PDO;
    public function getDsn(array $config): string;
    public function getOptions(array $config): array;
    public function getLastInsertId(\PDO $pdo, ?string $name = null): string;
}