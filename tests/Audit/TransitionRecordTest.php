<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Audit;

use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TransitionRecord::class)]
final class TransitionRecordTest
{
    public function exposesEveryField(): void
    {
        $at = new \DateTimeImmutable('2026-07-22T12:00:00+00:00');
        $record = new TransitionRecord('order', 'o-1', 'pay', 'pending', 'paid', $at, 'req-1');

        Assert::same($record->workflow, 'order');
        Assert::same($record->subjectId, 'o-1');
        Assert::same($record->transition, 'pay');
        Assert::same($record->from, 'pending');
        Assert::same($record->to, 'paid');
        Assert::same($record->at, $at);
        Assert::same($record->idempotencyKey, 'req-1');
    }

    public function serializesToAStorableRow(): void
    {
        $record = new TransitionRecord(
            'order',
            'o-1',
            'pay',
            'pending',
            'paid',
            new \DateTimeImmutable('2026-07-22T12:00:00+00:00'),
        );

        Assert::same($record->toArray(), [
            'workflow' => 'order',
            'subjectId' => 'o-1',
            'transition' => 'pay',
            'from' => 'pending',
            'to' => 'paid',
            'at' => '2026-07-22T12:00:00+00:00',
            'idempotencyKey' => null,
        ]);
    }
}
