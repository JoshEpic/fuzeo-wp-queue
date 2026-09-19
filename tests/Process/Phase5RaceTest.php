<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Process;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\MySql\MysqlTestCase;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use Fuzeo\Queue\Tests\Support\UniqueProductJob;

final class Phase5RaceTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testTwentyProcessesUniqueDispatch(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(UniqueProductJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/unique-dispatch.php';
        $env = $this->childEnv();
        $children = [];
        for ($i = 0; $i < 20; $i++) {
            $children[] = $this->spawn($php, [$script], $env);
        }
        $accepted = 0;
        $duplicate = 0;
        foreach ($children as $child) {
            fclose($child['pipes'][0]);
            stream_set_blocking($child['pipes'][1], true);
            $out = trim(stream_get_contents($child['pipes'][1]) ?: '');
            proc_close($child['proc']);
            if (str_starts_with($out, 'accepted')) {
                $accepted++;
            }
            if (str_starts_with($out, 'duplicate')) {
                $duplicate++;
            }
        }
        self::assertSame(1, $accepted, 'accepted=' . $accepted . ' duplicate=' . $duplicate);
        self::assertGreaterThanOrEqual(18, $duplicate);
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
    }

    public function testTwentyProcessesIdempotencyBegin(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/idempotency-begin.php';
        $env = $this->childEnv();
        $children = [];
        for ($i = 0; $i < 20; $i++) {
            $children[] = $this->spawn($php, [$script], $env);
        }
        $owned = 0;
        $denied = 0;
        foreach ($children as $child) {
            fclose($child['pipes'][0]);
            stream_set_blocking($child['pipes'][1], true);
            $out = trim(stream_get_contents($child['pipes'][1]) ?: '');
            proc_close($child['proc']);
            if ($out === 'owned') {
                $owned++;
            }
            if ($out === 'denied') {
                $denied++;
            }
        }
        self::assertSame(1, $owned);
        self::assertSame(19, $denied);
    }

    public function testTwoSchedulersDispatchOneOccurrence(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);
        $saved = Queue::schedule()->job('race', new ProcessOrderJob(1))->everySeconds(3600)->save();
        $due = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->sub(new \DateInterval('PT5S'));
        Coordinator::get()->schedules()->store()->save(
            $saved->withNextRun($due, $saved->lastRunAt, $saved->lastOccurrenceId, $saved->lastResult)
        );

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/schedule-run.php';
        $env = $this->childEnv();
        $one = $this->spawn($php, [$script], $env);
        $two = $this->spawn($php, [$script], $env);
        fclose($one['pipes'][0]);
        fclose($two['pipes'][0]);
        stream_set_blocking($one['pipes'][1], true);
        stream_set_blocking($two['pipes'][1], true);
        $outA = trim(stream_get_contents($one['pipes'][1]) ?: '');
        $outB = trim(stream_get_contents($two['pipes'][1]) ?: '');
        proc_close($one['proc']);
        proc_close($two['proc']);
        preg_match('/dispatched=(\d+)/', $outA, $mA);
        preg_match('/dispatched=(\d+)/', $outB, $mB);
        $total = (int) ($mA[1] ?? 0) + (int) ($mB[1] ?? 0);
        self::assertSame(1, $total, $outA . ' | ' . $outB);
        self::assertSame(1, Coordinator::get()->driver()->size('default'));
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

        return ['proc' => $proc, 'pipes' => $pipes];
    }
}
