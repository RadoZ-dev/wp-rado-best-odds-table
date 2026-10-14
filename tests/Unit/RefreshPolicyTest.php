<?php

declare(strict_types=1);

namespace BestOddsTable\Tests\Unit;

use BestOddsTable\Fetch\RefreshPolicy;
use PHPUnit\Framework\TestCase;

final class RefreshPolicyTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private RefreshPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RefreshPolicy();
    }

    public function testIntervalsTightenAsKickOffApproaches(): void
    {
        self::assertSame(3600, $this->policy->intervalFor(self::NOW + 3 * 86400, self::NOW));
        self::assertSame(900, $this->policy->intervalFor(self::NOW + 10 * 3600, self::NOW));
        self::assertSame(300, $this->policy->intervalFor(self::NOW + 3600, self::NOW));
        self::assertSame(300, $this->policy->intervalFor(self::NOW - 3600, self::NOW), 'in play');
    }

    public function testFinishedEventsAreNeverDue(): void
    {
        self::assertNull($this->policy->intervalFor(self::NOW - 4 * 3600, self::NOW));
        self::assertFalse($this->policy->isDue(null, self::NOW - 4 * 3600, self::NOW));
    }

    public function testNeverFetchedIsDue(): void
    {
        self::assertTrue($this->policy->isDue(null, self::NOW + 86400 * 5, self::NOW));
        self::assertTrue($this->policy->isDue(null, null, self::NOW));
    }

    public function testDueOnlyAfterIntervalElapsed(): void
    {
        $soon = self::NOW + 1800;

        self::assertFalse($this->policy->isDue(self::NOW - 299, $soon, self::NOW));
        self::assertTrue($this->policy->isDue(self::NOW - 300, $soon, self::NOW));
    }

    public function testCacheTtlIsTwoCycles(): void
    {
        self::assertSame(600, $this->policy->cacheTtl(self::NOW + 1800, self::NOW));
        self::assertSame(7200, $this->policy->cacheTtl(self::NOW - 5 * 3600, self::NOW));
    }
}
