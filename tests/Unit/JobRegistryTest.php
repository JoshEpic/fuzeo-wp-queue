<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Exceptions\DuplicateJobTypeException;
use Fuzeo\Queue\Exceptions\UnknownJobException;
use Fuzeo\Queue\Jobs\JobRegistry;
use Fuzeo\Queue\Jobs\Origin;
use Fuzeo\Queue\Tests\Support\ImportRecordJob;
use Fuzeo\Queue\Tests\Support\ProcessOrderHandler;
use Fuzeo\Queue\Tests\Support\ProcessOrderJob;
use PHPUnit\Framework\TestCase;

final class JobRegistryTest extends TestCase
{
    public function testRegistersAndResolvesJobs(): void
    {
        $registry = new JobRegistry();
        $origin = new Origin('acme/shop', '1.0.0');
        $registry->registerJob(ProcessOrderJob::class, $origin, ProcessOrderHandler::class);

        $registered = $registry->get('acme.process_order');
        self::assertSame(ProcessOrderHandler::class, $registered->handler);
        self::assertSame(1, $registered->schemaVersion);
        self::assertSame('acme/shop', $registered->origin->package);
    }

    public function testAllowsIdenticalReregistration(): void
    {
        $registry = new JobRegistry();
        $origin = new Origin('acme/shop', '1.0.0');
        $first = $registry->registerJob(ProcessOrderJob::class, $origin, ProcessOrderHandler::class);
        $second = $registry->registerJob(ProcessOrderJob::class, $origin, ProcessOrderHandler::class);
        self::assertSame($first->type, $second->type);
        self::assertCount(1, $registry->all());
    }

    public function testRejectsConflictingHandler(): void
    {
        $registry = new JobRegistry();
        $registry->registerJob(ProcessOrderJob::class, new Origin('acme/shop', '1.0.0'), ProcessOrderHandler::class);

        $this->expectException(DuplicateJobTypeException::class);
        $registry->registerJob(ProcessOrderJob::class, new Origin('other/plugin', '2.0.0'), ImportRecordJob::class);
    }

    public function testUnknownJobFailsCleanly(): void
    {
        $this->expectException(UnknownJobException::class);
        (new JobRegistry())->get('acme.missing_job');
    }

    public function testListIsDeterministic(): void
    {
        $registry = new JobRegistry();
        $origin = new Origin('acme/shop', '1.0.0');
        $registry->registerJob(ImportRecordJob::class, $origin);
        $registry->registerJob(ProcessOrderJob::class, $origin, ProcessOrderHandler::class);
        self::assertSame(['acme.import_record', 'acme.process_order'], array_keys($registry->all()));
    }
}
