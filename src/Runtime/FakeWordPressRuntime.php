<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

final class FakeWordPressRuntime implements WordPressRuntime
{
    public int $blogId = 1;

    public int $userId = 0;

    public string $locale = 'en_US';

    /** @var list<int> */
    public array $switchStack = [];

    public int $restoreLocaleCalls = 0;

    public int $resetQueryCalls = 0;

    public int $flushCacheCalls = 0;

    public bool $transactionOpen = false;

    public bool $dropIn = false;

    public bool $multisite = false;

    /** @var array<int, array{deleted?: bool, archived?: bool, spam?: bool, mature?: bool, domain?: string, path?: string}> */
    public array $sites = [1 => ['deleted' => false, 'archived' => false, 'spam' => false, 'domain' => 'example.org', 'path' => '/']];

    /** @var list<string> */
    public array $activePlugins = [];

    /** @var list<string> */
    public array $networkPlugins = [];

    public string $theme = 'twentytwentyfour|twentytwentyfour';

    public function currentBlogId(): int
    {
        return $this->blogId;
    }

    public function currentUserId(): int
    {
        return $this->userId;
    }

    public function setCurrentUser(int $userId): void
    {
        $this->userId = $userId;
    }

    public function currentLocale(): string
    {
        return $this->locale;
    }

    public function restoreLocale(): void
    {
        $this->restoreLocaleCalls++;
        $this->locale = 'en_US';
    }

    public function switchedStackDepth(): int
    {
        return count($this->switchStack);
    }

    public function restorePreviousBlog(): void
    {
        if ($this->switchStack === []) {
            return;
        }
        $this->blogId = array_pop($this->switchStack) ?: 1;
    }

    public function restoreBlog(): void
    {
        while ($this->switchStack !== []) {
            $this->restorePreviousBlog();
        }
    }

    public function switchToBlog(int $siteId): void
    {
        $this->switchStack[] = $this->blogId;
        $this->blogId = $siteId;
    }

    public function siteExists(int $siteId): bool
    {
        return isset($this->sites[$siteId]);
    }

    public function siteStatus(int $siteId): ?array
    {
        return $this->sites[$siteId] ?? null;
    }

    public function resetQuery(): void
    {
        $this->resetQueryCalls++;
    }

    public function flushRuntimeCache(): void
    {
        $this->flushCacheCalls++;
    }

    public function objectCacheDropInPresent(): bool
    {
        return $this->dropIn;
    }

    public function isPluginActive(string $pluginFile): bool
    {
        return in_array($pluginFile, $this->activePlugins, true)
            || in_array($pluginFile, $this->networkPlugins, true);
    }

    public function isPluginActiveForNetwork(string $pluginFile): bool
    {
        return in_array($pluginFile, $this->networkPlugins, true);
    }

    public function activePlugins(): array
    {
        $plugins = $this->activePlugins;
        sort($plugins);

        return $plugins;
    }

    public function networkActivePlugins(): array
    {
        $plugins = $this->networkPlugins;
        sort($plugins);

        return $plugins;
    }

    public function activeTheme(): string
    {
        return $this->theme;
    }

    public function isMultisite(): bool
    {
        return $this->multisite;
    }

    public function rollbackOpenTransaction(): bool
    {
        if (!$this->transactionOpen) {
            return false;
        }
        $this->transactionOpen = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionOpen;
    }
}
