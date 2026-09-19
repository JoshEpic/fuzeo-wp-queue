<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

/**
 * Narrow WordPress surface used by the long-running worker runtime.
 */
interface WordPressRuntime
{
    public function currentBlogId(): int;

    public function currentUserId(): int;

    public function setCurrentUser(int $userId): void;

    public function currentLocale(): string;

    public function restoreLocale(): void;

    public function switchedStackDepth(): int;

    public function restorePreviousBlog(): void;

    public function restoreBlog(): void;

    public function switchToBlog(int $siteId): void;

    public function siteExists(int $siteId): bool;

    /**
     * @return array{deleted?: bool, archived?: bool, spam?: bool, mature?: bool, domain?: string, path?: string}|null
     */
    public function siteStatus(int $siteId): ?array;

    public function resetQuery(): void;

    public function flushRuntimeCache(): void;

    public function objectCacheDropInPresent(): bool;

    public function isPluginActive(string $pluginFile): bool;

    public function isPluginActiveForNetwork(string $pluginFile): bool;

    /**
     * @return list<string>
     */
    public function activePlugins(): array;

    /**
     * @return list<string>
     */
    public function networkActivePlugins(): array;

    public function activeTheme(): string;

    public function isMultisite(): bool;

    public function rollbackOpenTransaction(): bool;

    public function inTransaction(): bool;
}
