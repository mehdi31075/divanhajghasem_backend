<?php

namespace Tests;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app['config']->get('divan.allow_database_tests')) {
            $this->markTestSkipped('Database tests require .env.testing with a separate MySQL/MariaDB database ending in _test and DIVAN_ALLOW_DATABASE_TESTS=true.');
        }
        // Runs before RefreshDatabase can migrate or truncate anything.
        Support\DatabaseSafety::assertReady($app);

        return $app;
    }
}
