<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Base class for guard listeners that spares each guard the filtering ritual.
 *
 * The bridge delivers every guard check of every workflow to the same PSR-14
 * event class, so a bare listener must open with "is this my workflow, is this
 * my transition" boilerplate — and silently runs for every other workflow in
 * the application when that filter is forgotten. Extend this class instead:
 * name the workflow and the transitions, implement the check.
 *
 * ```php
 * final class ShipOnlyWhenPaid extends TransitionGuard
 * {
 *     protected function workflow(): string
 *     {
 *         return 'order';
 *     }
 *
 *     protected function transitions(): array
 *     {
 *         return ['ship'];
 *     }
 *
 *     protected function guard(GuardEvent $event): void
 *     {
 *         if (!$event->getSubject()->isPaid()) {
 *             $event->addTransitionBlocker(new TransitionBlocker('Payment is not captured', 'order.unpaid'));
 *         }
 *     }
 * }
 * ```
 *
 * @api
 */
abstract class TransitionGuard
{
    final public function __invoke(object $event): void
    {
        if (!$event instanceof GuardEvent || $event->getWorkflowName() !== $this->workflow()) {
            return;
        }

        $transitions = $this->transitions();

        if ($transitions !== [] && !\in_array($event->getTransition()->getName(), $transitions, true)) {
            return;
        }

        $this->guard($event);
    }

    /** The workflow this guard belongs to. */
    abstract protected function workflow(): string;

    /** @return list<string> Transition names to guard; empty = every transition of the workflow. */
    protected function transitions(): array
    {
        return [];
    }

    /** The actual check: inspect the event, add blockers or set it blocked. */
    abstract protected function guard(GuardEvent $event): void;
}
