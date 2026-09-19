<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Persistence;

use Fuzeo\Queue\Exceptions\SchemaException;

/**
 * Network-level lock so only one bundled copy migrates.
 */
final class WordPressMigrationLock implements MigrationLock
{
    public function acquire(int $timeoutSeconds = 30): bool
    {
        $now = time();
        $lock = $this->read();
        if (is_array($lock) && isset($lock['until']) && is_int($lock['until']) && $lock['until'] > $now) {
            return false;
        }

        $this->write(['until' => $now + $timeoutSeconds, 'owner' => SchemaOwner::PACKAGE]);

        return true;
    }

    public function release(): void
    {
        $lock = $this->read();
        if ($lock === null) {
            throw new SchemaException('Migration lock is not held.');
        }
        $this->write(null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(): ?array
    {
        $value = null;
        if (function_exists('get_network_option')) {
            $value = get_network_option(null, SchemaOwner::OPTION_LOCK);
        } elseif (function_exists('get_site_option')) {
            $value = get_site_option(SchemaOwner::OPTION_LOCK);
        } elseif (function_exists('get_option')) {
            $value = get_option(SchemaOwner::OPTION_LOCK);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private function write(?array $value): void
    {
        if ($value === null) {
            if (function_exists('delete_network_option')) {
                delete_network_option(null, SchemaOwner::OPTION_LOCK);
                return;
            }
            if (function_exists('delete_site_option')) {
                delete_site_option(SchemaOwner::OPTION_LOCK);
                return;
            }
            if (function_exists('delete_option')) {
                delete_option(SchemaOwner::OPTION_LOCK);
            }
            return;
        }

        if (function_exists('update_network_option')) {
            update_network_option(null, SchemaOwner::OPTION_LOCK, $value);
            return;
        }
        if (function_exists('update_site_option')) {
            update_site_option(SchemaOwner::OPTION_LOCK, $value);
            return;
        }
        if (function_exists('update_option')) {
            update_option(SchemaOwner::OPTION_LOCK, $value);
        }
    }
}
