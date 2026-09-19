<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Config\ConfigRepository;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\UnavailableDriver;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\IncompatibleRuntimeException;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\StaticContextResolver;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Persistence\BaselineMigration;
use Fuzeo\Queue\Persistence\MemoryMigrationLock;
use Fuzeo\Queue\Persistence\MemoryMigrationRepository;
use Fuzeo\Queue\Persistence\MigrationLock;
use Fuzeo\Queue\Persistence\MigrationRepository;
use Fuzeo\Queue\Persistence\MigrationRunner;
use Fuzeo\Queue\Serialization\JsonPayloadSerializer;
use Fuzeo\Queue\Serialization\PayloadLimits;
use Fuzeo\Queue\Support\SystemClock;
use Fuzeo\Queue\Testing\FakeQueue;
use Fuzeo\Queue\WordPress\Admin\AdminRegistrar;
use Fuzeo\Queue\WordPress\Cli\CliRegistrar;
use Fuzeo\Queue\WordPress\WordPressBootstrap;

final class Coordinator
{
    private static ?QueueManager $manager = null;

    /** @var list<Diagnostic> */
    private static array $diagnostics = [];

    private static int $bootCount = 0;

    private static bool $integrationsRegistered = false;

    /**
     * @param array<string, mixed> $config
     */
    public static function bootFromGlobals(array $config = []): QueueManager
    {
        $kernel = self::kernel();
        $candidates = [];
        foreach ($kernel['candidates'] as $row) {
            if (is_array($row)) {
                $candidates[] = Candidate::fromArray($row);
            }
        }

        return self::boot($candidates, $config);
    }

    /**
     * @param list<Candidate> $candidates
     * @param array<string, mixed> $config
     */
    public static function boot(array $candidates, array $config = []): QueueManager
    {
        if (self::$manager !== null) {
            self::$bootCount++;
            self::kernelSet('boot_count', self::$bootCount);
            self::assertCompatibleWithLoadedSeries($candidates);

            return self::$manager;
        }

        self::$diagnostics = [];
        $selection = self::select($candidates);
        self::kernelSet('diagnostics', array_map(static fn (Diagnostic $d) => (array) $d, self::$diagnostics));

        if ($selection['incompatible'] !== []) {
            self::kernelSet('incompatible', $selection['incompatible']);
            $message = self::incompatibleMessage($selection['incompatible']);
            self::$diagnostics[] = new Diagnostic('error', 'incompatible_runtime', $message);
            throw new IncompatibleRuntimeException($message);
        }

        $resolved = (new ConfigRepository())->resolve($config);
        $manager = self::createManager($resolved);
        self::$manager = $manager;
        self::$bootCount = 1;
        self::kernelSet('booted', true);
        self::kernelSet('boot_count', 1);
        self::kernelSet('runtime', $manager);
        self::registerIntegrationsOnce();
        self::runOwnedMigrations($manager);

        return $manager;
    }

    public static function get(): QueueManager
    {
        if (self::$manager === null) {
            return self::bootFromGlobals();
        }

        return self::$manager;
    }

    public static function isBooted(): bool
    {
        return self::$manager !== null;
    }

    public static function bootCount(): int
    {
        return self::$bootCount;
    }

    /**
     * @return list<Diagnostic>
     */
    public static function diagnostics(): array
    {
        return self::$diagnostics;
    }

    public static function reset(): void
    {
        self::$manager = null;
        self::$diagnostics = [];
        self::$bootCount = 0;
        self::$integrationsRegistered = false;
        $GLOBALS['fuzeo_queue_kernel'] = [
            'candidates' => $GLOBALS['fuzeo_queue_kernel']['candidates'] ?? [],
            'booted' => false,
            'boot_count' => 0,
            'hooks_registered' => 0,
            'cli_registered' => 0,
            'admin_registered' => 0,
            'migrations_run' => 0,
            'incompatible' => [],
            'diagnostics' => [],
            'runtime' => null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function bootForTesting(array $config = [], ?QueueDriver $driver = null, ?ExecutionContextResolver $context = null, ?Clock $clock = null): QueueManager
    {
        self::reset();
        $resolved = (new ConfigRepository())->resolve($config);
        if (!isset($config['driver'])) {
            $resolved = $resolved->merge(['driver' => Config::DRIVER_MEMORY]);
        }
        $manager = self::createManager($resolved, $driver, $context, $clock);
        self::$manager = $manager;
        self::$bootCount = 1;
        self::kernelSet('booted', true);
        self::kernelSet('boot_count', 1);

        return $manager;
    }

    public static function swapFake(FakeQueue $fake): void
    {
        self::get()->useFake($fake);
    }

    /**
     * @param list<Candidate> $candidates
     * @return array{winner: Candidate|null, incompatible: list<array<string, mixed>>}
     */
    public static function select(array $candidates): array
    {
        $incompatible = [];
        $compatible = [];
        foreach ($candidates as $candidate) {
            if ($candidate->compatibilitySeries !== PackageInfo::COMPATIBILITY_SERIES) {
                $incompatible[] = [
                    'version' => $candidate->version,
                    'compatibility_series' => $candidate->compatibilitySeries,
                    'path' => $candidate->path,
                ];
                continue;
            }
            $compatible[] = $candidate;
        }

        if ($compatible === []) {
            return ['winner' => null, 'incompatible' => $incompatible];
        }

        usort($compatible, static function (Candidate $a, Candidate $b): int {
            $cmp = version_compare($b->version, $a->version);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a->path, $b->path);
        });

        $winner = $compatible[0];
        if (version_compare($winner->version, PackageInfo::VERSION, '>')) {
            self::$diagnostics[] = new Diagnostic(
                'warning',
                'newer_copy_unused',
                'A newer compatible Fuzeo Queue ' . $winner->version
                . ' is present, but PHP already loaded ' . PackageInfo::VERSION
                . '. Align plugin dependencies so the newest copy loads first, or use a single shared Composer install.'
            );
        }

        return ['winner' => $winner, 'incompatible' => $incompatible];
    }

    private static function createManager(
        Config $config,
        ?QueueDriver $driver = null,
        ?ExecutionContextResolver $context = null,
        ?Clock $clock = null,
    ): QueueManager {
        $clock ??= new SystemClock();
        $context ??= self::defaultContextResolver();
        $driver ??= self::makeDriver($config, $clock);
        $serializer = new JsonPayloadSerializer(new PayloadLimits($config->maxPayloadBytes, $config->maxPayloadDepth));

        return new QueueManager(
            config: $config,
            registry: new JobRegistry(),
            driver: $driver,
            serializer: $serializer,
            contextResolver: $context,
            clock: $clock,
            migrations: new MigrationRunner(new MemoryMigrationRepository(), new MemoryMigrationLock()),
        );
    }

    private static function makeDriver(Config $config, Clock $clock): QueueDriver
    {
        return match ($config->driver) {
            Config::DRIVER_MEMORY => new MemoryDriver($clock),
            Config::DRIVER_UNAVAILABLE => new UnavailableDriver(),
            default => throw new ConfigurationException(
                'Unknown queue driver "' . $config->driver . '". Phase 1 supports memory (tests) and unavailable (default).'
            ),
        };
    }

    private static function defaultContextResolver(): ExecutionContextResolver
    {
        if (function_exists('get_current_blog_id')) {
            return new \Fuzeo\Queue\WordPress\WordPressContextResolver();
        }

        return new StaticContextResolver(ExecutionContext::singleSite());
    }

    /**
     * @param list<Candidate> $candidates
     */
    private static function assertCompatibleWithLoadedSeries(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            if ($candidate->compatibilitySeries !== PackageInfo::COMPATIBILITY_SERIES) {
                throw new IncompatibleRuntimeException(self::incompatibleMessage([[
                    'version' => $candidate->version,
                    'compatibility_series' => $candidate->compatibilitySeries,
                    'path' => $candidate->path,
                ]]));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $incompatible
     */
    private static function incompatibleMessage(array $incompatible): string
    {
        $details = [];
        foreach ($incompatible as $row) {
            $details[] = ($row['version'] ?? '?') . ' (series ' . ($row['compatibility_series'] ?? '?') . ')';
        }

        return 'Incompatible Fuzeo Queue copies are loaded. Loaded runtime is '
            . PackageInfo::VERSION . ' (series ' . PackageInfo::COMPATIBILITY_SERIES
            . '). Incompatible: ' . implode(', ', $details)
            . '. Update plugins so every copy shares compatibility series ' . PackageInfo::COMPATIBILITY_SERIES
            . '. Queue dispatch is refused to avoid corrupting jobs.';
    }

    private static function registerIntegrationsOnce(): void
    {
        if (self::$integrationsRegistered) {
            return;
        }
        self::$integrationsRegistered = true;
        WordPressBootstrap::register();
        CliRegistrar::register();
        AdminRegistrar::register();
    }

    private static function runOwnedMigrations(QueueManager $manager): void
    {
        $result = $manager->migrations()->run([new BaselineMigration()]);
        $current = (int) (self::kernel()['migrations_run'] ?? 0);
        self::kernelSet('migrations_run', $current + ($result->lockedOut ? 0 : 1));
    }

    /**
     * @return array<string, mixed>
     */
    private static function kernel(): array
    {
        $kernel = $GLOBALS['fuzeo_queue_kernel'] ?? null;
        if (!is_array($kernel)) {
            $kernel = [
                'candidates' => [],
                'booted' => false,
                'boot_count' => 0,
                'hooks_registered' => 0,
                'cli_registered' => 0,
                'admin_registered' => 0,
                'migrations_run' => 0,
                'incompatible' => [],
                'diagnostics' => [],
                'runtime' => null,
            ];
            $GLOBALS['fuzeo_queue_kernel'] = $kernel;
        }

        return $kernel;
    }

    private static function kernelSet(string $key, mixed $value): void
    {
        $GLOBALS['fuzeo_queue_kernel'][$key] = $value;
    }
}
