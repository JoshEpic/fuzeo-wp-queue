<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Process;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Redis\RedisTestCase;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\UniqueProductJob;

final class RedisUniqueRaceTest extends RedisTestCase
{
    public function testTwentyProcessesUniqueDispatch(): void
    {
        Coordinator::bootForTesting([
            'driver' => 'redis',
            'redis_dsn' => sprintf(
                'redis://%s:%d/%d',
                $this->settings->host,
                $this->settings->port,
                $this->settings->database,
            ),
            'redis_namespace' => $this->settings->namespace,
        ], driver: $this->redisDriver, clock: $this->clock);
        Queue::register(UniqueProductJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/redis-unique-dispatch.php';
        $env = $_ENV + $_SERVER;
        $outEnv = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $outEnv[$key] = $value;
            }
        }
        $outEnv['FUZEO_QUEUE_TEST_REDIS_HOST'] = $this->settings->host;
        $outEnv['FUZEO_QUEUE_TEST_REDIS_PORT'] = (string) $this->settings->port;
        $outEnv['FUZEO_QUEUE_TEST_REDIS_DB'] = (string) $this->settings->database;
        $outEnv['FUZEO_QUEUE_TEST_REDIS_NS'] = $this->settings->namespace;

        $children = [];
        for ($i = 0; $i < 20; $i++) {
            $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open([$php, $script], $spec, $pipes, dirname(__DIR__, 2), $outEnv);
            self::assertIsResource($proc);
            $children[] = ['proc' => $proc, 'pipes' => $pipes];
        }
        $accepted = 0;
        $duplicate = 0;
        foreach ($children as $child) {
            fclose($child['pipes'][0]);
            stream_set_blocking($child['pipes'][1], true);
            $line = trim(stream_get_contents($child['pipes'][1]) ?: '');
            proc_close($child['proc']);
            if (str_starts_with($line, 'accepted')) {
                $accepted++;
            }
            if (str_starts_with($line, 'duplicate')) {
                $duplicate++;
            }
        }
        self::assertSame(1, $accepted);
        self::assertSame(19, $duplicate);
    }
}
