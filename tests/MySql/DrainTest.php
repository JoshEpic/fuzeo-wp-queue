<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MySql;

use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Queue;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Tests\Support\FileRecordingHandler;
use Fuzeo\Queue\Tests\Support\RecordJob;

final class DrainTest extends MysqlTestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
        parent::tearDown();
    }

    public function testTwoWorkersDrainTheQueue(): void
    {
        Coordinator::bootForTesting(['driver' => 'mysql'], connection: $this->connection);
        Queue::register(RecordJob::class, new Origin('acme/shop', '1.0.0'), FileRecordingHandler::class);
        $path = sys_get_temp_dir() . '/fuzeo-queue-drain-' . getmypid() . '.log';
        @unlink($path);
        $count = getenv('FUZEO_QUEUE_STRESS') === '1' ? 200 : 20;
        for ($i = 0; $i < $count; $i++) {
            Queue::dispatch(new RecordJob($path, (string) $i));
        }

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/bin/work-jobs.php';
        $env = $this->childEnv();
        $half = (string) (int) ceil($count / 2);
        $one = proc_open([$php, $script, $half], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1, dirname(__DIR__, 2), $env);
        $two = proc_open([$php, $script, $half], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, dirname(__DIR__, 2), $env);
        self::assertIsResource($one);
        self::assertIsResource($two);
        stream_get_contents($pipes1[1]);
        stream_get_contents($pipes2[1]);
        proc_close($one);
        proc_close($two);

        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        self::assertIsArray($lines);
        self::assertCount($count, $lines);
        @unlink($path);
    }

    /**
     * @return array<string, string>
     */
    private function childEnv(): array
    {
        return [
            'FUZEO_QUEUE_TEST_DSN' => $this->dsn,
            'FUZEO_QUEUE_TEST_DB_USER' => $this->user,
            'FUZEO_QUEUE_TEST_DB_PASS' => $this->password,
        ];
    }
}
