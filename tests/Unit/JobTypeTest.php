<?php

declare(strict_types=1);

namespace Fuzeo\Queue\Tests\Unit;

use Fuzeo\Queue\Jobs\JobType;
use PHPUnit\Framework\TestCase;

final class JobTypeTest extends TestCase
{
    public function testAcceptsDottedLowercaseIdentifiers(): void
    {
        self::assertSame('acme.process_order', JobType::normalize('Acme.process_order'));
        self::assertSame('fuzeo.bridge.import_record', JobType::normalize('fuzeo.bridge.import_record'));
    }

    /**
     * @dataProvider invalidTypes
     */
    public function testRejectsInvalidTypes(string $type): void
    {
        $this->expectException(\Fuzeo\Queue\Exceptions\InvalidJobTypeException::class);
        JobType::normalize($type);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTypes(): array
    {
        return [
            'empty' => [''],
            'no-dot' => ['processorder'],
            'spaces' => ['acme process'],
            'class' => ['Acme\\ProcessOrder'],
            'leading-dot' => ['.acme.job'],
        ];
    }
}
