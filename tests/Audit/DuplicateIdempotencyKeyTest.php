<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Audit;

use Rasuvaeff\Yii3Workflow\Audit\DuplicateIdempotencyKey;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DuplicateIdempotencyKey::class)]
final class DuplicateIdempotencyKeyTest
{
    public function carriesTheOffendingIdentifiersAndAMessage(): void
    {
        $exception = new DuplicateIdempotencyKey('order', 'o-1', 'req-1');

        Assert::same($exception->workflow, 'order');
        Assert::same($exception->subjectId, 'o-1');
        Assert::same($exception->idempotencyKey, 'req-1');
        Assert::same(
            $exception->getMessage(),
            'Transition with idempotency key "req-1" is already recorded for subject "o-1" of workflow "order"',
        );
    }
}
