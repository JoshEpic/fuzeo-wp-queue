<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Process;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\MySql\MysqlTestCase;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

final class ConcurrentReserveTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testTwoProcessesCannotOwnTheSameJob(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        Queue::dispatch(new ProcessOrderJob(1));

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/reserve-one.php';
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

    public function testKilledWorkerIsRecoveredAfterLeaseExpiry(): void
    {
        if (!function_exists('posix_kill') || !defined('SIGKILL')) {
            self::markTestSkipped('posix_kill is required for crash-recovery tests.');
        }

        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $envelope = Queue::dispatch(new ProcessOrderJob(2));

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/reserve-one.php';
        $child = $this->spawn($php, [$script, 'dying', '30'], $this->childEnv());
        $line = '';
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $chunk = stream_get_contents($child['pipes'][1]);
            if (is_string($chunk) && $chunk !== '') {
                $line .= $chunk;
                if (str_contains($line, $envelope->jobId)) {
                    break;
                }
            }
            usleep(20000);
        }
        self::assertStringContainsString($envelope->jobId, $line);
        $status = proc_get_status($child['proc']);
        self::assertIsArray($status);
        posix_kill($status['pid'], SIGKILL);
        proc_close($child['proc']);

        sleep(16);
        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $recovered = $driver->reserve(new \Fuzeo\Queue\Drivers\ReserveRequest('default', 'rescuer', 15));
        self::assertNotNull($recovered);
        self::assertSame($envelope->jobId, $recovered->envelope->jobId);
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
        $dsn = getenv('FUZEO_QUEUE_TEST_DSN');
        if (is_string($dsn) && $dsn !== '') {
            $out['FUZEO_QUEUE_TEST_DSN'] = $dsn;
        } else {
            $host = getenv('FUZEO_QUEUE_TEST_DB_HOST') ?: '127.0.0.1';
            $port = getenv('FUZEO_QUEUE_TEST_DB_PORT') ?: '3306';
            $name = getenv('FUZEO_QUEUE_TEST_DB_NAME') ?: 'fuzeo_queue_test';
            $out['FUZEO_QUEUE_TEST_DSN'] = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        }
        $out['FUZEO_QUEUE_TEST_DB_USER'] = getenv('FUZEO_QUEUE_TEST_DB_USER') ?: 'root';
        $pass = getenv('FUZEO_QUEUE_TEST_DB_PASS');

        return \Fuzeo\Queue\Tests\Support\ChildDatabase::withPassword($out, $pass === false ? 'root' : $pass);
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
