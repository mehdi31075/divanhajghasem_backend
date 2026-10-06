<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use LogicException;

final class DatabaseSafety
{
    public static function assertReady(Application $app): void
    {
        // Resolve URL overrides too, without opening a PDO connection.
        self::validate(
            $app['db']->connection()->getConfig(),
            $app->environment(),
            $app['config']->get('divan.allow_database_tests', false),
        );
    }

    public static function validate(array $connection, string $environment, bool $allowed): void
    {
        if (! $allowed || $environment !== 'testing'
            || ! in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)
            || ! preg_match('/^[a-zA-Z0-9_]+_test$/D', $connection['database'] ?? '')) {
            throw new LogicException('Refusing database tests: use APP_ENV=testing, DIVAN_ALLOW_DATABASE_TESTS=true and a dedicated MySQL/MariaDB database ending in _test. Never use the live Divan database.');
        }
    }
}
