<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\Tests\Support\Order;
use Rasuvaeff\Yii3Workflow\TransitionGuard;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;

#[Test]
#[Covers(TransitionGuard::class)]
final class TransitionGuardTest
{
    public function blocksItsOwnTransition(): void
    {
        $guard = $this->guard(['ship']);
        $event = $this->guardEvent('order', 'ship');

        $guard($event);

        Assert::same($guard->calls, 1);
        Assert::true($event->isBlocked());
    }

    public function ignoresAnotherWorkflow(): void
    {
        $guard = $this->guard(['ship']);
        $event = $this->guardEvent('invoice', 'ship');

        $guard($event);

        Assert::same($guard->calls, 0);
        Assert::false($event->isBlocked());
    }

    public function ignoresAnotherTransition(): void
    {
        $guard = $this->guard(['ship']);
        $event = $this->guardEvent('order', 'pay');

        $guard($event);

        Assert::same($guard->calls, 0);
    }

    public function anEmptyTransitionListGuardsEveryTransition(): void
    {
        $guard = $this->guard([]);

        $guard($this->guardEvent('order', 'pay'));
        $guard($this->guardEvent('order', 'ship'));

        Assert::same($guard->calls, 2);
    }

    public function ignoresEventsThatAreNotGuardChecks(): void
    {
        $guard = $this->guard(['ship']);

        $guard(new \stdClass());

        Assert::same($guard->calls, 0);
    }

    public function blocksThroughTheRealDispatchChain(): void
    {
        $guard = $this->guard(['ship']);
        $workflow = (new WorkflowFactory(
            Clocks::frozen(),
            new SimpleEventDispatcher(static function (object $event) use ($guard): void {
                $guard($event);
            }),
        ))->create('order', Definitions::order());
        $order = new Order();
        $workflow->apply($order, 'pay');

        Assert::false($workflow->can($order, 'ship'));
        Assert::true($workflow->can($order, 'cancel'));
    }

    /** @param list<string> $transitions */
    private function guard(array $transitions): TransitionGuard
    {
        return new class ($transitions) extends TransitionGuard {
            public int $calls = 0;

            /** @param list<string> $transitionNames */
            public function __construct(
                private readonly array $transitionNames,
            ) {}

            #[\Override]
            protected function workflow(): string
            {
                return 'order';
            }

            #[\Override]
            protected function transitions(): array
            {
                return $this->transitionNames;
            }

            #[\Override]
            protected function guard(GuardEvent $event): void
            {
                ++$this->calls;
                $event->setBlocked(true);
            }
        };
    }

    private function guardEvent(string $workflowName, string $transitionName): GuardEvent
    {
        $workflow = (new WorkflowFactory(Clocks::frozen()))
            ->create($workflowName, Definitions::order())
            ->workflow();
        $transition = null;

        foreach ($workflow->getDefinition()->getTransitions() as $candidate) {
            if ($candidate->getName() === $transitionName) {
                $transition = $candidate;

                break;
            }
        }

        Assert::instanceOf($transition, Transition::class);

        return new GuardEvent(new Order(), new Marking(['pending' => 1]), $transition, $workflow);
    }
}
