<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Process;

use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Redis\RedisTestCase;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

final class RedisConcurrentReserveTest extends RedisTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testTwoProcessesCannotOwnTheSameJob(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/redis-reserve-one.php';
        $env = $this->childEnv();
        $one = $this->spawn($php, [$script, 'a'], $env);
        $two = $this->spawn($php, [$script, 'b'], $env);
        fclose($one['pipes'][0]);
        fclose($two['pipes'][0]);
        stream_set_blocking($one['pipes'][1], true);
        stream_set_blocking($two['pipes'][1], true);
        $outA = trim(stream_get_contents($one['pipes'][1]) ?: '');
        $outB = trim(stream_get_contents($two['pipes'][1]) ?: '');
        proc_close($one['proc']);
        proc_close($two['proc']);

        $lines = array_values(array_filter([$outA, $outB], static fn (string $line): bool => $line !== '' && $line !== 'none'));
        self::assertCount(1, $lines, $outA . ' | ' . $outB);
    }

    public function testLeaseExpiryAfterKillAllowsReserve(): void
    {
        Coordinator::bootForTesting(['driver' => 'redis'], $this->redisDriver, clock: $this->clock);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(2));
        $held = $this->redisDriver->reserve(new ReserveRequest('default', 'dying', 1));
        self::assertNotNull($held);
        $this->clock->set($this->clock->now()->add(new \DateInterval('PT3S')));
        $recovered = $this->redisDriver->reserve(new ReserveRequest('default', 'rescuer', 15));
        self::assertNotNull($recovered);
        self::assertSame($envelope->jobId, $recovered->envelope->jobId);
        self::assertSame(2, $recovered->envelope->attempt);
    }

    /**
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        $env = $_ENV + $_SERVER;
        $out = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }
        $out['FUZEO_QUEUE_TEST_REDIS_HOST'] = $this->settings->host;
        $out['FUZEO_QUEUE_TEST_REDIS_PORT'] = (string) $this->settings->port;
        $out['FUZEO_QUEUE_TEST_REDIS_DB'] = (string) $this->settings->database;
        $out['FUZEO_QUEUE_TEST_REDIS_NAMESPACE'] = $this->settings->namespace;

        return $out;
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{proc: resource, pipes: array<int, resource>}
     */
    private function spawn(string $php, array $args, array $env): array
    {
        $command = array_merge([$php], $args);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $spec, $pipes, dirname(__DIR__, 2), $env);
        self::assertIsResource($proc);
        stream_set_blocking($pipes[1], false);

        return ['proc' => $proc, 'pipes' => $pipes];
    }
}
