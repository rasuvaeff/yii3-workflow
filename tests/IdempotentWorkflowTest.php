<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\Yii3Workflow\Audit\DuplicateIdempotencyKey;
use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3Workflow\IdempotentWorkflow;
use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\Tests\Support\Order;
use Rasuvaeff\Yii3Workflow\Tests\Support\OrderStatus;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(IdempotentWorkflow::class)]
final class IdempotentWorkflowTest
{
    private InMemoryTransitionLog $log;

    private IdempotentWorkflow $workflow;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->log = new InMemoryTransitionLog();
        $this->workflow = (new WorkflowFactory(
            clock: Clocks::frozen(),
            log: $this->log,
        ))->create('order', Definitions::order());
    }

    public function appliesATransitionAndRecordsIt(): void
    {
        $order = new Order();

        Assert::true($this->workflow->applyOnce($order, 'pay', 'req-1'));
        Assert::same($order->status(), OrderStatus::Paid);
        Assert::same($this->log->all()[0]->idempotencyKey, 'req-1');
    }

    public function skipsAReplayOfTheSameKey(): void
    {
        $order = new Order();
        $this->workflow->applyOnce($order, 'pay', 'req-1');

        Assert::false($this->workflow->applyOnce($order, 'ship', 'req-1'));
        Assert::same($order->status(), OrderStatus::Paid);
        Assert::same(\count($this->log->all()), 1);
    }

    public function differentKeysStillApply(): void
    {
        $order = new Order();
        $this->workflow->applyOnce($order, 'pay', 'req-1');

        Assert::true($this->workflow->applyOnce($order, 'ship', 'req-2'));
        Assert::same($order->status(), OrderStatus::Shipped);
    }

    public function aKeyIsScopedToItsSubject(): void
    {
        $this->workflow->applyOnce(new Order('o-1'), 'pay', 'req-1');

        Assert::true($this->workflow->applyOnce(new Order('o-2'), 'pay', 'req-1'));
    }

    public function withoutAKeyEveryCallApplies(): void
    {
        $order = new Order();

        Assert::true($this->workflow->applyOnce($order, 'pay'));
        Assert::true($this->workflow->applyOnce($order, 'ship'));
        Assert::same($order->status(), OrderStatus::Shipped);
    }

    public function aKeyRequiresASubjectIdentity(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('requires the subject to implement');

        $this->workflow->applyOnce(new \stdClass(), 'pay', 'req-1');
    }

    public function aKeyWithoutALogIsRefusedInsteadOfSilentlyDoingNothing(): void
    {
        $bare = new IdempotentWorkflow($this->workflow->workflow());

        // Without a log there is no replay protection at all; accepting the key
        // would look safe and be anything but.
        Expect::exception(\LogicException::class)
            ->withMessageContaining('no TransitionLog is bound, so replay protection would silently do nothing');

        $bare->applyOnce(new Order(), 'pay', 'req-1');
    }

    public function withoutAKeyALoglessWorkflowStillApplies(): void
    {
        $bare = new IdempotentWorkflow($this->workflow->workflow());
        $order = new Order();

        Assert::true($bare->applyOnce($order, 'pay'));
        Assert::same($order->status(), OrderStatus::Paid);
    }

    public function aRaceLostAtTheStorageLayerIsReportedAsAReplay(): void
    {
        // Two requests pass the pre-flight lookup together; the log's uniqueness
        // constraint decides, and the loser must not report success.
        $racy = (new WorkflowFactory(
            clock: Clocks::frozen(),
            log: new RacyTransitionLog(),
        ))->create('order', Definitions::order());

        Assert::false($racy->applyOnce(new Order(), 'pay', 'req-1'));
    }

    public function aKeyedTransitionAppliesEvenWithoutAnIdempotencyContext(): void
    {
        $log = new InMemoryTransitionLog();
        $workflow = new IdempotentWorkflow($this->workflow->workflow(), $log);
        $order = new Order();

        Assert::true($workflow->applyOnce($order, 'pay', 'req-1'));
        Assert::same($order->status(), OrderStatus::Paid);

        // The key is scoped to its subject, so another order may reuse it.
        Assert::true($workflow->applyOnce(new Order('o-2'), 'pay', 'req-1'));
    }

    public function delegatesTheReadOnlyApi(): void
    {
        $order = new Order();

        Assert::same($this->workflow->name(), 'order');
        Assert::true($this->workflow->can($order, 'pay'));
        Assert::false($this->workflow->can($order, 'ship'));
        Assert::same($this->workflow->blockers($order, 'ship')->count(), 1);
        Assert::same(
            \array_map(
                static fn(Transition $t): string => $t->getName(),
                \iterator_to_array($this->asIterator($this->workflow->enabledTransitions($order))),
            ),
            ['pay', 'cancel'],
        );
        Assert::same($this->workflow->definition()->getInitialPlaces(), ['pending']);
        Assert::instanceOf($this->workflow->workflow(), WorkflowInterface::class);
    }

    public function applyPassesThroughToTheWrappedWorkflow(): void
    {
        $order = new Order();

        $marking = $this->workflow->apply($order, 'pay');

        Assert::same(\array_keys($marking->getPlaces()), ['paid']);
        Assert::same($order->status(), OrderStatus::Paid);
    }

    /** @param iterable<Transition> $transitions */
    private function asIterator(iterable $transitions): \Iterator
    {
        return \is_array($transitions) ? new \ArrayIterator($transitions) : $transitions;
    }
}

/** A log whose uniqueness constraint always fires, as a lost race would. */
final class RacyTransitionLog implements TransitionLog
{
    #[\Override]
    public function append(TransitionRecord $record): void
    {
        throw new DuplicateIdempotencyKey(
            $record->workflow,
            $record->subjectId,
            (string) $record->idempotencyKey,
        );
    }

    /** @return list<TransitionRecord> */
    #[\Override]
    public function forSubject(string $workflow, string $subjectId): array
    {
        return [];
    }

    #[\Override]
    public function hasIdempotencyKey(string $workflow, string $subjectId, string $key): bool
    {
        return false;
    }
}
