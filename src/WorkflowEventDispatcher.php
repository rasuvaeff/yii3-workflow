<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcher;
use Symfony\Component\Workflow\WorkflowEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Adapter between `symfony/workflow` and the application's PSR-14 dispatcher.
 *
 * Workflow dispatches every event three times under increasingly specific names
 * (`workflow.guard`, `workflow.duel.guard`, `workflow.duel.guard.activate`) and
 * types its dispatcher with Symfony's name-aware contract. PSR-14 has no event
 * names, so a naive bridge would deliver the same object three times to a
 * class-based listener. This adapter forwards ONCE per event — on the generic
 * name — and lets listeners narrow by `$event->getWorkflowName()` and
 * `$event->getTransition()`.
 *
 * The package therefore depends on the event-dispatcher CONTRACTS only; the
 * implementation is whatever the application already uses (in Yii3,
 * `yiisoft/event-dispatcher`).
 *
 * Internal listeners passed to the constructor run before the application's,
 * in registration order; that is how the audit trail is recorded even when no
 * PSR-14 dispatcher is bound.
 *
 * @api
 */
final readonly class WorkflowEventDispatcher implements EventDispatcherInterface
{
    private const array FORWARDED = [
        WorkflowEvents::GUARD,
        WorkflowEvents::LEAVE,
        WorkflowEvents::TRANSITION,
        WorkflowEvents::ENTER,
        WorkflowEvents::ENTERED,
        WorkflowEvents::COMPLETED,
        WorkflowEvents::ANNOUNCE,
    ];

    /** @var list<callable(object): void> */
    private array $listeners;

    /**
     * @param iterable<callable(object): void> $listeners
     */
    public function __construct(
        private ?PsrEventDispatcher $psrDispatcher = null,
        iterable $listeners = [],
    ) {
        $collected = [];

        foreach ($listeners as $listener) {
            $collected[] = $listener;
        }

        $this->listeners = $collected;
    }

    #[\Override]
    public function dispatch(object $event, ?string $eventName = null): object
    {
        if (!\in_array($eventName, self::FORWARDED, strict: true)) {
            return $event;
        }

        foreach ($this->listeners as $listener) {
            $listener($event);
        }

        // PSR-14 hands back the same (possibly mutated) event object, and the
        // Symfony contract is templated on the argument — so return the object
        // we were given rather than whatever came back.
        $this->psrDispatcher?->dispatch($event);

        return $event;
    }
}
