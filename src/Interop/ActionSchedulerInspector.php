<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Interop;

final class ActionSchedulerInspector
{
    public const PAGE_MAX = 100;

    public function __construct(
        private readonly ActionSchedulerGateway $gateway,
        private readonly int $siteId = 1,
        private readonly int $networkId = 1,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if (!$this->gateway->detected()) {
            return [
                'detected' => false,
                'healthy' => false,
                'version' => null,
                'datastore' => false,
                'pending' => 0,
                'running' => 0,
                'failed' => 0,
                'recurring' => 0,
            ];
        }

        return [
            'detected' => true,
            'healthy' => $this->gateway->datastoreAvailable(),
            'version' => $this->gateway->version(),
            'datastore' => $this->gateway->datastoreAvailable(),
            'pending' => $this->gateway->count(['status' => 'pending']),
            'running' => $this->gateway->count(['status' => 'in-progress']),
            'failed' => $this->gateway->count(['status' => 'failed']),
            'recurring' => 0,
        ];
    }

    /**
     * @return list<ActionRecord>
     */
    public function page(?string $hook = null, ?string $group = null, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        if (!$this->gateway->detected()) {
            return [];
        }
        $query = [];
        if ($hook !== null && $hook !== '') {
            $query['hook'] = $hook;
        }
        if ($group !== null && $group !== '') {
            $query['group'] = $group;
        }
        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }
        $rows = $this->gateway->query($query, min(self::PAGE_MAX, max(1, $limit)), max(0, $offset));
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function ownedPending(string $group): int
    {
        if (!$this->gateway->detected()) {
            return 0;
        }

        return $this->gateway->count(['group' => $group, 'status' => 'pending']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ActionRecord
    {
        $id = (string) ($row['action_id'] ?? $row['ID'] ?? $row['id'] ?? '');
        $hook = (string) ($row['hook'] ?? '');
        $args = $row['args'] ?? [];
        if (is_string($args)) {
            $decoded = json_decode($args, true);
            $args = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($args)) {
            $args = [];
        }
        $scheduled = $row['scheduled_date_gmt'] ?? $row['scheduled_date'] ?? null;
        $at = null;
        if (is_string($scheduled) && $scheduled !== '') {
            try {
                $at = new \DateTimeImmutable($scheduled, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $at = null;
            }
        }
        $interval = $row['schedule'] ?? $row['interval'] ?? null;
        $seconds = is_numeric($interval) ? (int) $interval : null;
        if ($seconds !== null && $seconds <= 0) {
            $seconds = null;
        }

        return new ActionRecord(
            id: $id,
            hook: $hook,
            args: $args,
            group: (string) ($row['group'] ?? ''),
            status: (string) ($row['status'] ?? 'pending'),
            scheduledAt: $at,
            recurrenceSeconds: $seconds,
            siteId: $this->siteId,
            networkId: $this->networkId,
        );
    }
}
