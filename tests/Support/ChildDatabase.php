<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Support;

final class ChildDatabase
{
    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    public static function withPassword(array $env, string $password): array
    {
        if ($password === '') {
            $env['FUZEO_QUEUE_TEST_DB_PASS'] = 'root';
            $env['FUZEO_QUEUE_TEST_DB_EMPTY_PASS'] = '1';
        } else {
            $env['FUZEO_QUEUE_TEST_DB_PASS'] = $password;
            $env['FUZEO_QUEUE_TEST_DB_EMPTY_PASS'] = '0';
        }

        return $env;
    }

    public static function passwordFromEnvironment(): string
    {
        if (getenv('FUZEO_QUEUE_TEST_DB_EMPTY_PASS') === '1') {
            return '';
        }
        $password = getenv('FUZEO_QUEUE_TEST_DB_PASS');

        return $password === false ? 'root' : $password;
    }
}
