<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Audit;

use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(InMemoryTransitionLog::class)]
final class InMemoryTransitionLogTest
{
    public function isATransitionLog(): void
    {
        Assert::instanceOf(new InMemoryTransitionLog(), TransitionLog::class);
    }

    public function keepsRecordsInAppendOrderPerSubject(): void
    {
        $log = new InMemoryTransitionLog();
        $log->append($this->record('order', 'o-1', 'pay'));
        $log->append($this->record('order', 'o-2', 'pay'));
        $log->append($this->record('order', 'o-1', 'ship'));

        Assert::same(
            \array_map(static fn(TransitionRecord $r): string => $r->transition, $log->forSubject('order', 'o-1')),
            ['pay', 'ship'],
        );
        Assert::same(\count($log->all()), 3);
    }

    public function separatesSubjectsOfDifferentWorkflows(): void
    {
        $log = new InMemoryTransitionLog();
        $log->append($this->record('order', 'o-1', 'pay'));
        $log->append($this->record('invoice', 'o-1', 'pay'));

        Assert::same(\count($log->forSubject('order', 'o-1')), 1);
    }

    public function findsAnIdempotencyKeyOnlyForItsOwnSubject(): void
    {
        $log = new InMemoryTransitionLog();
        $log->append($this->record('order', 'o-1', 'pay', 'req-1'));

        Assert::true($log->hasIdempotencyKey('order', 'o-1', 'req-1'));
        Assert::false($log->hasIdempotencyKey('order', 'o-2', 'req-1'));
        Assert::false($log->hasIdempotencyKey('invoice', 'o-1', 'req-1'));
        Assert::false($log->hasIdempotencyKey('order', 'o-1', 'req-2'));
    }

    public function ignoresRecordsWithoutAKey(): void
    {
        $log = new InMemoryTransitionLog();
        $log->append($this->record('order', 'o-1', 'pay'));

        Assert::false($log->hasIdempotencyKey('order', 'o-1', 'req-1'));
    }

    private function record(string $workflow, string $subjectId, string $transition, ?string $key = null): TransitionRecord
    {
        return new TransitionRecord(
            workflow: $workflow,
            subjectId: $subjectId,
            transition: $transition,
            from: 'pending',
            to: 'paid',
            at: new \DateTimeImmutable('2026-07-22T12:00:00+00:00'),
            idempotencyKey: $key,
        );
    }
}
