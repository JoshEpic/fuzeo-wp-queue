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
        $maxJobs = (string) $count;
        $one = $this->spawnWorker($php, $script, $maxJobs, $env);
        $two = $this->spawnWorker($php, $script, $maxJobs, $env);
        $output = $this->awaitWorkers([$one, $two], 30);

        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        self::assertIsArray($lines);
        self::assertCount($count, $lines, $output);
        @unlink($path);
    }

    /**
     * @param array<string, string> $env
     * @return array{proc: resource, pipes: array<int, resource>}
     */
    private function spawnWorker(string $php, string $script, string $maxJobs, array $env): array
    {
        $proc = proc_open(
            [$php, $script, $maxJobs],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        self::assertIsResource($proc);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * @param list<array{proc: resource, pipes: array<int, resource>}> $workers
     */
    private function awaitWorkers(array $workers, int $timeoutSeconds): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $output = '';
        foreach ($workers as $worker) {
            while (true) {
                $status = proc_get_status($worker['proc']);
                $stdout = stream_get_contents($worker['pipes'][1]);
                $stderr = stream_get_contents($worker['pipes'][2]);
                if (is_string($stdout) && $stdout !== '') {
                    $output .= $stdout;
                }
                if (is_string($stderr) && $stderr !== '') {
                    $output .= $stderr;
                }
                if (is_array($status) && $status['running'] === false) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($worker['proc']);
                    self::fail('Drain workers did not finish: ' . $output);
                }
                usleep(20000);
            }
            proc_close($worker['proc']);
        }

        return $output;
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
        $out['PATH'] = (string) getenv('PATH');
        $out['FUZEO_QUEUE_TEST_DSN'] = $this->dsn;
        $out['FUZEO_QUEUE_TEST_DB_USER'] = $this->user;
        $out['FUZEO_QUEUE_DISABLE_ALARMS'] = '1';

        return \Fuzeo\Queue\Tests\Support\ChildDatabase::withPassword($out, $this->password);
    }
}
