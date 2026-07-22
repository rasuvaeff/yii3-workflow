<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\Yii3Workflow\WorkflowEventDispatcher;
use Symfony\Component\Workflow\WorkflowEvents;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;

#[Test]
#[Covers(WorkflowEventDispatcher::class)]
final class WorkflowEventDispatcherTest
{
    #[DataProvider('genericEventProvider')]
    public function forwardsGenericEventNamesToPsr(string $eventName): void
    {
        $psr = new SimpleEventDispatcher();
        $event = new \stdClass();

        (new WorkflowEventDispatcher($psr))->dispatch($event, $eventName);

        Assert::same($psr->getEvents(), [$event]);
    }

    public static function genericEventProvider(): iterable
    {
        yield 'guard' => [WorkflowEvents::GUARD];
        yield 'leave' => [WorkflowEvents::LEAVE];
        yield 'transition' => [WorkflowEvents::TRANSITION];
        yield 'enter' => [WorkflowEvents::ENTER];
        yield 'entered' => [WorkflowEvents::ENTERED];
        yield 'completed' => [WorkflowEvents::COMPLETED];
        yield 'announce' => [WorkflowEvents::ANNOUNCE];
    }

    #[DataProvider('specificEventProvider')]
    public function swallowsTheWorkflowAndTransitionScopedNames(?string $eventName): void
    {
        // Workflow dispatches each event three times under increasingly specific
        // names; a PSR-14 listener must see it exactly once.
        $psr = new SimpleEventDispatcher();

        (new WorkflowEventDispatcher($psr))->dispatch(new \stdClass(), $eventName);

        Assert::same($psr->getEvents(), []);
    }

    public static function specificEventProvider(): iterable
    {
        yield 'workflow scope' => ['workflow.order.guard'];
        yield 'transition scope' => ['workflow.order.guard.pay'];
        yield 'no name' => [null];
    }

    public function returnsTheEventUntouchedWithoutAPsrDispatcher(): void
    {
        $event = new \stdClass();

        Assert::same((new WorkflowEventDispatcher())->dispatch($event, WorkflowEvents::COMPLETED), $event);
    }

    public function runsInternalListenersBeforeTheApplicationDispatcher(): void
    {
        $order = [];
        $psr = new SimpleEventDispatcher(static function () use (&$order): void {
            $order[] = 'app';
        });

        $dispatcher = new WorkflowEventDispatcher($psr, [static function () use (&$order): void {
            $order[] = 'internal';
        }]);

        $dispatcher->dispatch(new \stdClass(), WorkflowEvents::COMPLETED);

        Assert::same($order, ['internal', 'app']);
    }

    public function internalListenersRunWithoutAnApplicationDispatcher(): void
    {
        $calls = 0;
        $dispatcher = new WorkflowEventDispatcher(null, [static function () use (&$calls): void {
            $calls++;
        }]);

        $dispatcher->dispatch(new \stdClass(), WorkflowEvents::COMPLETED);
        $dispatcher->dispatch(new \stdClass(), 'workflow.order.completed');

        Assert::same($calls, 1);
    }

    public function acceptsAnyIterableOfListeners(): void
    {
        $calls = 0;
        $listeners = (static function () use (&$calls): iterable {
            yield static function () use (&$calls): void {
                $calls++;
            };
        })();

        (new WorkflowEventDispatcher(null, $listeners))->dispatch(new \stdClass(), WorkflowEvents::GUARD);

        Assert::same($calls, 1);
    }
}
