<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\MultipleBundle;

use Fuzeo\Queue\Exceptions\IncompatibleRuntimeException;
use Fuzeo\Queue\Runtime\Candidate;
use Fuzeo\Queue\Runtime\Coordinator;
use Fuzeo\Queue\Runtime\PackageInfo;
use PHPUnit\Framework\TestCase;

final class IncompatibleBundlesTest extends TestCase
{
    protected function tearDown(): void
    {
        Coordinator::reset();
    }

    public function testIncompatibleSeriesFailsVisibly(): void
    {
        $ok = new Candidate('0.1.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/a', 'a.php');
        $bad = new Candidate('2.0.0', PackageInfo::COMPATIBILITY_SERIES + 1, '/plugins/b', 'b.php');

        $this->expectException(IncompatibleRuntimeException::class);
        $this->expectExceptionMessage('Incompatible Fuzeo Queue copies');
        Coordinator::boot([$ok, $bad], ['driver' => 'memory']);
    }

    public function testSelectPrefersHighestCompatibleVersion(): void
    {
        $old = new Candidate('0.1.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/old', 'old.php');
        $new = new Candidate('0.2.0', PackageInfo::COMPATIBILITY_SERIES, '/plugins/new', 'new.php');
        $selection = Coordinator::select([$old, $new]);
        self::assertNotNull($selection['winner']);
        self::assertSame('0.2.0', $selection['winner']->version);
        self::assertSame([], $selection['incompatible']);
    }
}
