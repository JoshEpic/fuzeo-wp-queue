<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Exceptions\DriverException;

final class EmptyJobCatalog implements JobCatalog
{
    public function inspect(string $jobId): JobInspection
    {
        throw new DriverException('Unknown job ' . $jobId . '.');
    }

    public function list(JobQuery $query): JobPage
    {
        $query = $query->bounded();

        return new JobPage([], $query->limit, $query->offset, 0);
    }

    public function queueSnapshots(?int $siteId = null): array
    {
        unset($siteId);

        return [];
    }

    public function oldestEligibleAgeSeconds(?string $queue = null, ?int $siteId = null): ?int
    {
        unset($queue, $siteId);

        return null;
    }
}
