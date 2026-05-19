<?php

namespace Maileon\Test;

use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected static function requireIntegrationEnv(): void
    {
        if (!getenv('MAILEON_RUN_INTEGRATION') || !getenv('MAILEON_API_KEY')) {
            self::markTestSkipped(
                'Set MAILEON_RUN_INTEGRATION=1 and MAILEON_API_KEY to run integration tests.'
            );
        }
    }

    protected static function config(): array
    {
        return $GLOBALS['config'];
    }

    protected static function testdata(): array
    {
        return $GLOBALS['testdata'];
    }
}
