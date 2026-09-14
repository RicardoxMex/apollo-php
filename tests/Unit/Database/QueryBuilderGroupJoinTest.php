<?php

namespace Tests\Unit\Database;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * groupBy() y join() del QueryBuilder sobre SQLite :memory:.
 * Requiere extension=pdo_sqlite (se omite automáticamente si no está cargada).
 */
class QueryBuilderGroupJoinTest extends TestCase
{
    private QueryBuilder $q;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite requerido para QueryBuilderGroupJoinTest');
        }

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();
    }

    protected function setUp(): void
    {
        $pdo = DatabaseManager::getConnection();

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, team_id INTEGER NULL)');
        $pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO teams (id, name) VALUES (1, 'Alpha'), (2, 'Beta')");
        $pdo->exec("INSERT INTO users (name, team_id) VALUES ('Ana', 1), ('Luis', 1), ('Sara', 2), ('Sin equipo', NULL)");

        $this->q = new QueryBuilder($pdo);
    }

    protected function tearDown(): void
    {
        $pdo = DatabaseManager::getConnection();
        $pdo->exec('DROP TABLE IF EXISTS users');
        $pdo->exec('DROP TABLE IF EXISTS teams');
    }

    public function test_join_combines_rows(): void
    {
        $rows = $this->q
            ->table('users')
            ->select('users.name, teams.name AS team')
            ->join('teams', 'teams.id', '=', 'users.team_id')
            ->get();

        $teams = array_column($rows, 'team');
        sort($teams);

        $this->assertSame(['Alpha', 'Alpha', 'Beta'], $teams);
        $this->assertCount(3, $rows);
    }

    public function test_left_join_keeps_unmatched_rows(): void
    {
        $rows = $this->q
            ->table('users')
            ->select('users.name, teams.name AS team')
            ->leftJoin('teams', 'teams.id', '=', 'users.team_id')
            ->get();

        $names = array_column($rows, 'name');
        sort($names);

        $this->assertCount(4, $rows);
        $this->assertSame(['Ana', 'Luis', 'Sara', 'Sin equipo'], $names);
    }

    public function test_join_with_where_filters_combined_result(): void
    {
        $rows = $this->q
            ->table('users')
            ->select('users.name, teams.name AS team')
            ->join('teams', 'teams.id', '=', 'users.team_id')
            ->where('teams.name', 'Alpha')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['Ana', 'Luis'], array_column($rows, 'name'));
    }

    public function test_join_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JOIN no permitido');

        $this->q->table('users')->join('teams', 'teams.id', '=', 'users.team_id', 'HACK');
    }

    public function test_group_by_aggregates(): void
    {
        $rows = $this->q
            ->table('users')
            ->select('team_id, COUNT(*) AS total')
            ->groupBy('team_id')
            ->get();

        $byTeam = [];
        foreach ($rows as $row) {
            $byTeam[$row['team_id']] = (int) $row['total'];
        }

        $this->assertSame(2, $byTeam[1]);
        $this->assertSame(1, $byTeam[2]);
    }

    public function test_group_by_with_where(): void
    {
        $rows = $this->q
            ->table('users')
            ->select('team_id, COUNT(*) AS total')
            ->whereNotNull('team_id')
            ->groupBy('team_id')
            ->get();

        $this->assertCount(2, $rows);
    }

    public function test_group_by_multiple_columns(): void
    {
        $rows = $this->q
            ->table('users')
            ->select('team_id, name, COUNT(*) AS total')
            ->groupBy(['team_id', 'name'])
            ->get();

        $this->assertCount(4, $rows);
    }

    public function test_count_with_group_by_counts_groups(): void
    {
        $total = $this->q
            ->table('users')
            ->groupBy('team_id')
            ->count();

        // 2 grupos con equipo + 1 sin equipo
        $this->assertSame(3, $total);
    }

    public function test_count_with_join_and_where(): void
    {
        $total = $this->q
            ->table('users')
            ->join('teams', 'teams.id', '=', 'users.team_id')
            ->where('teams.name', 'Alpha')
            ->count();

        $this->assertSame(2, $total);
    }
}