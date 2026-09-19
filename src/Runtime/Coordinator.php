<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Runtime;

use Fuzeo\Queue\Config\Config;
use Fuzeo\Queue\Config\ConfigRepository;
use Fuzeo\Queue\Contracts\Clock;
use Fuzeo\Queue\Contracts\ExecutionContextResolver;
use Fuzeo\Queue\Core\QueueManager;
use Fuzeo\Queue\Concurrency\AdmissionPolicy;
use Fuzeo\Queue\Drivers\Memory\MemoryDriver;
use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\Redis\RedisDriver;
use Fuzeo\Queue\Redis\PhpRedisConnection;
use Fuzeo\Queue\Redis\RedisSettings;
use Fuzeo\Queue\Drivers\QueueDriver;
use Fuzeo\Queue\Drivers\UnavailableDriver;
use Fuzeo\Queue\Exceptions\ConfigurationException;
use Fuzeo\Queue\Exceptions\IncompatibleRuntimeException;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\StaticContextResolver;
use Fuzeo\Queue\Jobs\ExecutionContext;
use Fuzeo\Queue\Persistence\BaselineMigration;
use Fuzeo\Queue\Persistence\Connection;
use Fuzeo\Queue\Persistence\DatabaseMigrationRepository;
use Fuzeo\Queue\Persistence\MemoryMigrationLock;
use Fuzeo\Queue\Persistence\MemoryMigrationRepository;
use Fuzeo\Queue\Persistence\MysqlAdvisoryLock;
use Fuzeo\Queue\Persistence\MigrationRunner;
use Fuzeo\Queue\Persistence\AttemptsMigration;
use Fuzeo\Queue\Persistence\Phase5TablesMigration;
use Fuzeo\Queue\Persistence\QueueTablesMigration;
use Fuzeo\Queue\Persistence\WpdbConnection;
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

    private static ?Connection $connection = null;

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
        self::noteDriverSwitch($resolved->driver);
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
        self::$connection = null;
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
            'active_driver' => $GLOBALS['fuzeo_queue_kernel']['active_driver'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function bootForTesting(
        array $config = [],
        ?QueueDriver $driver = null,
        ?ExecutionContextResolver $context = null,
        ?Clock $clock = null,
        ?Connection $connection = null,
    ): QueueManager {
        self::reset();
        $resolved = (new ConfigRepository())->resolve($config);
        if (!isset($config['driver']) && $driver === null && $connection === null) {
            $resolved = $resolved->merge(['driver' => Config::DRIVER_MEMORY]);
        }
        self::noteDriverSwitch($resolved->driver);
        $manager = self::createManager($resolved, $driver, $context, $clock, $connection);
        self::$manager = $manager;
        self::$bootCount = 1;
        self::kernelSet('booted', true);
        self::kernelSet('boot_count', 1);
        if (self::$connection !== null) {
            self::runOwnedMigrations($manager);
        }

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
        ?Connection $connection = null,
    ): QueueManager {
        $clock ??= new SystemClock();
        $context ??= self::defaultContextResolver();
        $connection ??= self::detectConnection($config);
        self::$connection = $connection;
        $driver ??= self::makeDriver($config, $clock, $connection);
        $serializer = new JsonPayloadSerializer(new PayloadLimits($config->maxPayloadBytes, $config->maxPayloadDepth));

        return new QueueManager(
            config: $config,
            registry: new JobRegistry(),
            driver: $driver,
            serializer: $serializer,
            contextResolver: $context,
            clock: $clock,
            migrations: self::makeMigrations($connection),
        );
    }

    private static function detectConnection(Config $config): ?Connection
    {
        if ($config->driver !== Config::DRIVER_MYSQL && $config->driver !== Config::DRIVER_UNAVAILABLE) {
            return null;
        }
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            return WpdbConnection::fromGlobals();
        }

        return null;
    }

    private static function noteDriverSwitch(string $driver): void
    {
        $previous = self::kernel()['active_driver'] ?? null;
        if (is_string($previous) && $previous !== '' && $previous !== $driver) {
            self::$diagnostics[] = new Diagnostic(
                'warning',
                'driver_switch',
                'Queue driver changed from ' . $previous . ' to ' . $driver
                . '. Outstanding jobs, schedules, uniqueness claims, and idempotency state are not migrated.'
                . ' Drain or inspect the previous backend before switching. Automatic migration is unsupported in 0.5.'
            );
        }
        self::kernelSet('active_driver', $driver);
    }

    private static function makeDriver(Config $config, Clock $clock, ?Connection $connection): QueueDriver
    {
        $name = $config->driver;
        if ($name === Config::DRIVER_UNAVAILABLE && $connection !== null) {
            $name = Config::DRIVER_MYSQL;
        }

        return match ($name) {
            Config::DRIVER_MEMORY => new MemoryDriver($clock, AdmissionPolicy::fromConfig($config)),
            Config::DRIVER_UNAVAILABLE => new UnavailableDriver(),
            Config::DRIVER_MYSQL => self::makeMysqlDriver($connection, $clock, $config),
            Config::DRIVER_REDIS => self::makeRedisDriver($config, $clock),
            default => throw new ConfigurationException(
                'Unknown queue driver "' . $config->driver . '". Supported: mysql, redis, memory, unavailable.'
            ),
        };
    }

    private static function makeRedisDriver(Config $config, Clock $clock): RedisDriver
    {
        $namespace = $config->redisNamespace;
        if ($namespace === 'local' && function_exists('home_url')) {
            $namespace = substr(sha1((string) home_url()), 0, 16);
        }
        $settings = $config->redisDsn !== ''
            ? RedisSettings::fromDsn($config->redisDsn, $namespace)
            : new RedisSettings(namespace: $namespace);

        return new RedisDriver(new PhpRedisConnection($settings), $settings, $clock, $config);
    }

    private static function makeMysqlDriver(?Connection $connection, Clock $clock, Config $config): MySqlDriver
    {
        if ($connection === null) {
            throw new ConfigurationException(
                'The mysql driver requires WordPress $wpdb or an injected database connection.'
            );
        }

        return new MySqlDriver($connection, $clock, $config);
    }

    private static function makeMigrations(?Connection $connection): MigrationRunner
    {
        if ($connection === null) {
            return new MigrationRunner(new MemoryMigrationRepository(), new MemoryMigrationLock());
        }

        return new MigrationRunner(
            new DatabaseMigrationRepository($connection),
            new MysqlAdvisoryLock($connection),
        );
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
        $migrations = [new BaselineMigration(self::$connection)];
        if (self::$connection !== null) {
            $migrations[] = new QueueTablesMigration(self::$connection);
            $migrations[] = new AttemptsMigration(self::$connection);
            $migrations[] = new Phase5TablesMigration(self::$connection);
        }
        $result = $manager->migrations()->run($migrations);
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
                'active_driver' => null,
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
