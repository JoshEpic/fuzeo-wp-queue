<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Inspection;

use Fuzeo\Queue\Jobs\Envelope;

interface JobCatalog
{
    public function inspect(string $jobId): JobInspection;

    public function list(JobQuery $query): JobPage;

    /**
     * @return list<QueueSnapshot>
     */
    public function queueSnapshots(?int $siteId = null): array;

    public function oldestEligibleAgeSeconds(?string $queue = null, ?int $siteId = null): ?int;
}
