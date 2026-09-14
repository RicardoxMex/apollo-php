<?php
// core/Database/Migrator.php

namespace Apollo\Core\Database;

use PDO;

/**
 * Ejecutor de migraciones con tracking (tabla 'migrations').
 *
 * Complementa el "todo o nada" de db:setup con operaciones no destructivas:
 * - migrate()        → aplica SOLO las pendientes en un batch nuevo (max+1).
 * - rollbackLast()   → deshace el último batch (down() + borra el tracking).
 * - rollbackAll()    → deshace todos los batches en orden inverso.
 */
class Migrator
{
    public function __construct(private string $path)
    {
    }

    public static function ensureTrackingTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                migration TEXT PRIMARY KEY,
                batch INTEGER NOT NULL DEFAULT 1,
                executed_at TEXT NOT NULL
            )'
        );
    }

    /**
     * Registrar una migración como aplicada (REPLACE funciona en MySQL y SQLite).
     */
    public static function record(PDO $pdo, string $migration, string $executedAt, int $batch = 1): void
    {
        self::ensureTrackingTable($pdo);
        $stmt = $pdo->prepare(
            'REPLACE INTO migrations (migration, batch, executed_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$migration, $batch, $executedAt]);
    }

    /**
     * Archivos de migración ordenados (nombres base, p. ej. 001_create_users_table.php).
     *
     * @return string[]
     */
    public function files(): array
    {
        $files = glob($this->path . '/migrations/*.php') ?: [];
        sort($files);

        return array_map('basename', $files);
    }

    /**
     * Nombres de migración registrados como aplicados.
     *
     * @return string[]
     */
    public function applied(PDO $pdo): array
    {
        self::ensureTrackingTable($pdo);

        return $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Migraciones pendientes (en disco pero no aplicadas).
     *
     * @return string[]
     */
    public function pending(PDO $pdo): array
    {
        return array_values(array_diff($this->files(), $this->applied($pdo)));
    }

    /**
     * Aplica las pendientes en un batch nuevo (max+1). Devuelve las aplicadas.
     *
     * @return string[]
     */
    public function migrate(PDO $pdo): array
    {
        $pending = $this->pending($pdo);

        if ($pending === []) {
            return [];
        }

        $batch = 1 + (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();

        foreach ($pending as $name) {
            $this->load($name)->up();
            self::record($pdo, $name, now(), $batch);
        }

        return $pending;
    }

    /**
     * Deshace el último batch (down() + borra el tracking). Devuelve las revertidas.
     *
     * @return string[]
     */
    public function rollbackLast(PDO $pdo): array
    {
        self::ensureTrackingTable($pdo);

        $batch = (int) $pdo->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();

        if ($batch === 0) {
            return [];
        }

        return $this->rollbackBatch($pdo, $batch);
    }

    /**
     * Deshace todos los batches en orden inverso. Devuelve las revertidas.
     *
     * @return string[]
     */
    public function rollbackAll(PDO $pdo): array
    {
        $reverted = [];

        while (true) {
            $batch = $this->rollbackLast($pdo);
            if ($batch === []) {
                break;
            }
            $reverted = array_merge($reverted, $batch);
        }

        return $reverted;
    }

    /**
     * Deshace un batch concreto (orden inverso de ejecución).
     *
     * @return string[]
     */
    private function rollbackBatch(PDO $pdo, int $batch): array
    {
        $stmt = $pdo->prepare(
            'SELECT migration FROM migrations WHERE batch = ? ORDER BY executed_at DESC, migration DESC'
        );
        $stmt->execute([$batch]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($names as $name) {
            $this->load($name)->down();
            $pdo->prepare('DELETE FROM migrations WHERE migration = ?')->execute([$name]);
        }

        return $names;
    }

    private function load(string $name): Migration
    {
        $migration = require $this->path . '/migrations/' . $name;

        if (!$migration instanceof Migration) {
            throw new \RuntimeException("Migración inválida: {$name} (debe devolver una instancia de " . Migration::class . ')');
        }

        return $migration;
    }
}