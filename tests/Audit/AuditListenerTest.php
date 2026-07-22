<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Audit;

use Rasuvaeff\Yii3Workflow\Audit\AuditListener;
use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\Tests\Support\Order;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(AuditListener::class)]
final class AuditListenerTest
{
    private InMemoryTransitionLog $log;

    private IdempotencyContext $idempotency;

    private AuditListener $listener;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->log = new InMemoryTransitionLog();
        $this->idempotency = new IdempotencyContext();
        $this->listener = new AuditListener($this->log, Clocks::frozen(), $this->idempotency);
    }

    public function recordsACommittedTransition(): void
    {
        ($this->listener)($this->event());

        $record = $this->log->all()[0];

        Assert::same($record->workflow, 'order');
        Assert::same($record->subjectId, 'o-1');
        Assert::same($record->transition, 'pay');
        Assert::same($record->from, 'pending');
        Assert::same($record->to, 'paid');
        Assert::same($record->at->format(\DateTimeInterface::ATOM), Clocks::INSTANT);
        Assert::null($record->idempotencyKey);
    }

    public function joinsMultipleEndpointsOfAWorkflowTransition(): void
    {
        ($this->listener)($this->event(new Transition('cancel', ['pending', 'paid'], ['cancelled'])));

        Assert::same($this->log->all()[0]->from, 'pending,paid');
    }

    public function picksUpTheCurrentIdempotencyKey(): void
    {
        $this->idempotency->during('req-1', function (): void {
            ($this->listener)($this->event());
        });

        Assert::same($this->log->all()[0]->idempotencyKey, 'req-1');
    }

    public function ignoresEventsOfOtherTypes(): void
    {
        ($this->listener)(new \stdClass());

        Assert::same($this->log->all(), []);
    }

    public function ignoresSubjectsWithoutAnIdentity(): void
    {
        ($this->listener)(new CompletedEvent(
            new \stdClass(),
            new Marking(['paid' => 1]),
            new Transition('pay', 'pending', 'paid'),
            $this->workflow(),
        ));

        Assert::same($this->log->all(), []);
    }

    private function event(?Transition $transition = null): CompletedEvent
    {
        return new CompletedEvent(
            new Order(),
            new Marking(['paid' => 1]),
            $transition ?? new Transition('pay', 'pending', 'paid'),
            $this->workflow(),
        );
    }

    private function workflow(): WorkflowInterface
    {
        return (new WorkflowFactory(Clocks::frozen()))->create('order', Definitions::order())->workflow();
    }
}
