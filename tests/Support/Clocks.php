<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Support;

use Yiisoft\Test\Support\Clock\StaticClock;

/** Pinned time for the whole suite, on top of yiisoft/test-support. */
final readonly class Clocks
{
    public const string INSTANT = '2026-07-22T12:00:00+00:00';

    public static function frozen(): StaticClock
    {
        return new StaticClock(new \DateTimeImmutable(self::INSTANT));
    }
}
