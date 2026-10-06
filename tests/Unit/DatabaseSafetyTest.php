<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseSafety;

final class DatabaseSafetyTest extends TestCase
{
    public static function unsafeConfigurations(): array
    {
        return [
            'live database' => ['mysql', 'divanhaj_db', 'testing', true],
            'production environment' => ['mysql', 'divanhaj_db_test', 'production', true],
            'missing opt-in' => ['mysql', 'divanhaj_db_test', 'testing', false],
            'other driver' => ['pgsql', 'divanhaj_db_test', 'testing', true],
            'missing database' => ['mysql', '', 'testing', true],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_refuses_unsafe_database_before_any_connection(string $driver, string $database, string $environment, bool $allowed): void
    {
        $this->expectException(LogicException::class);
        DatabaseSafety::validate(compact('driver', 'database'), $environment, $allowed);
    }

    public function test_accepts_explicit_isolated_mysql_and_mariadb_databases(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            DatabaseSafety::validate(['driver' => $driver, 'database' => 'divanhaj_db_test'], 'testing', true);
        }
        $this->addToAssertionCount(2);
    }
}
