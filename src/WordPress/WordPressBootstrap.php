<?php

declare(strict_types=1);

namespace Fuzeo\Queue\WordPress;

use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\Hooks;
use Fuzeo\Queue\Runtime\NativeWordPressRuntime;
use Fuzeo\Queue\Runtime\RuntimeGeneration;

/**
 * WordPress hooks attach only after the winning runtime boots.
 * Autoloading the package does not register admin, CLI, or schema hooks.
 */
final class WordPressBootstrap
{
    public static function register(): void
    {
        $kernel = &$GLOBALS['fuzeo_queue_kernel'];
        if (!is_array($kernel)) {
            return;
        }
        if (($kernel['hooks_registered'] ?? 0) > 0) {
            return;
        }

        $kernel['hooks_registered'] = 1;

        if (function_exists('do_action')) {
            do_action(Hooks::READY, Coordinator::get());
        }

        if (function_exists('add_filter')) {
            add_filter('site_status_tests', [self::class, 'siteStatusTests']);
            add_filter('debug_information', [self::class, 'debugInformation']);
        }
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public static function siteStatusTests(array $tests): array
    {
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        foreach (self::health()->tests() as $id => $test) {
            $direct[$id] = $test;
        }
        $tests['direct'] = $direct;

        return $tests;
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    public static function debugInformation(array $info): array
    {
        $fields = [];
        foreach (self::health()->debug() as $key => $value) {
            $fields[$key] = [
                'label' => $key,
                'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                'debug' => $value,
            ];
        }
        $info['fuzeo-queue'] = [
            'label' => 'Fuzeo Queue',
            'fields' => $fields,
        ];

        return $info;
    }

    public static function hookCount(): int
    {
        $kernel = $GLOBALS['fuzeo_queue_kernel'] ?? [];

        return (int) ($kernel['hooks_registered'] ?? 0);
    }

    private static function health(): SiteHealth
    {
        $wp = new NativeWordPressRuntime();
        $config = Coordinator::isBooted() ? Coordinator::get()->config() : new \Fuzeo\Queue\Config\Config();

        return new SiteHealth(
            $wp,
            new RuntimeGeneration($wp),
            $config->staleWorkerSeconds,
            $config->siteHealthBacklogCritical,
        );
    }
}
