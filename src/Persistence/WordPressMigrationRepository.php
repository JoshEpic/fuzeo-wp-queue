<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

final class WordPressMigrationRepository implements MigrationRepository
{
    public function currentVersion(): int
    {
        if (function_exists('get_network_option')) {
            return (int) get_network_option(null, SchemaOwner::OPTION_VERSION, 0);
        }
        if (function_exists('get_site_option')) {
            return (int) get_site_option(SchemaOwner::OPTION_VERSION, 0);
        }
        if (function_exists('get_option')) {
            return (int) get_option(SchemaOwner::OPTION_VERSION, 0);
        }

        return 0;
    }

    public function record(int $version): void
    {
        if (function_exists('update_network_option')) {
            update_network_option(null, SchemaOwner::OPTION_VERSION, $version);
            return;
        }
        if (function_exists('update_site_option')) {
            update_site_option(SchemaOwner::OPTION_VERSION, $version);
            return;
        }
        if (function_exists('update_option')) {
            update_option(SchemaOwner::OPTION_VERSION, $version);
        }
    }
}
