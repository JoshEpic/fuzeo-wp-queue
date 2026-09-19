<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Process;

use Fuzeo\Queue\Drivers\MySql\MySqlDriver;
use Fuzeo\Queue\Drivers\ReserveRequest;
use Fuzeo\Queue\Jobs\JobState;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Retry\FixedBackoff;
use Fuzeo\Queue\Retry\RetryPolicy;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\MySql\MysqlTestCase;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;

final class PoisonJobTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testCrashRecoveryExhaustsAttempts(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('posix_kill is required for poison-job tests.');
        }

        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderJob::class);
        $envelope = Queue::on('default')
            ->withMaxAttempts(2)
            ->withRetryPolicy(new RetryPolicy(2, new FixedBackoff(0)))
            ->dispatch(new ProcessOrderJob(1));

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/crash-job.php';
        $this->runCrashWorker($php, $script);
        sleep(25);
        $this->runCrashWorker($php, $script);
        sleep(25);

        $driver = Coordinator::get()->driver();
        self::assertInstanceOf(MySqlDriver::class, $driver);
        $again = $driver->reserve(new ReserveRequest('default', 'parent', 10));
        self::assertNull($again);
        self::assertSame(JobState::Dead, $driver->get($envelope->jobId)->state);
        self::assertGreaterThanOrEqual(2, $driver->get($envelope->jobId)->attempt);
    }

    private function runCrashWorker(string $php, string $script): void
    {
        $env = $this->childEnv();
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([$php, $script], $spec, $pipes, dirname(__DIR__, 2), $env);
        self::assertIsResource($proc);
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (is_array($status) && $status['running'] === false) {
                break;
            }
            usleep(50000);
        }
        proc_close($proc);
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
}
