<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3Workflow\SubjectIdentity;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}

/** Domain object: private enum status, no setter — the library adapts, not the domain. */
final class Order implements SubjectIdentity
{
    private OrderStatus $status = OrderStatus::Pending;

    public function __construct(
        private readonly string $id,
        private bool $paid = false,
    ) {}

    #[\Override]
    public function workflowSubjectId(): string
    {
        return $this->id;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function capturePayment(): void
    {
        $this->paid = true;
    }
}

/** Stand-in for the application's PSR-14 dispatcher (yiisoft/event-dispatcher). */
final class SimpleDispatcher implements EventDispatcherInterface
{
    /** @param list<callable(object): void> $listeners */
    public function __construct(private readonly array $listeners = []) {}

    #[\Override]
    public function dispatch(object $event): object
    {
        foreach ($this->listeners as $listener) {
            $listener($event);
        }

        return $event;
    }
}

$clock = new class implements ClockInterface {
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-22T12:00:00+00:00');
    }
};

// Guards are ordinary PSR-14 listeners on the workflow event classes.
$dispatcher = new SimpleDispatcher([
    static function (object $event): void {
        if (!$event instanceof GuardEvent || $event->getTransition()?->getName() !== 'ship') {
            return;
        }

        $order = $event->getSubject();

        if (!$order->isPaid()) {
            $event->addTransitionBlocker(new TransitionBlocker('Payment is not captured', 'order.unpaid'));
        }
    },
]);

$log = new InMemoryTransitionLog();

// In a Yii3 app this array lives in `params.php` and the registry comes from DI.
$registry = new WorkflowRegistry(
    [
        'order' => [
            'type' => 'state_machine',
            'initial' => OrderStatus::Pending,
            'places' => OrderStatus::cases(),
            'markingStore' => ['type' => 'enum', 'enum' => OrderStatus::class, 'property' => 'status'],
            'transitions' => [
                ['name' => 'pay', 'from' => OrderStatus::Pending, 'to' => OrderStatus::Paid],
                ['name' => 'ship', 'from' => OrderStatus::Paid, 'to' => OrderStatus::Shipped],
                ['name' => 'cancel', 'from' => [OrderStatus::Pending, OrderStatus::Paid], 'to' => OrderStatus::Cancelled],
            ],
        ],
    ],
    new WorkflowFactory($clock, $dispatcher, $log),
);

$workflow = $registry->get('order');
$order = new Order('o-1');

$workflow->applyOnce($order, 'pay', idempotencyKey: 'req-1');
\printf("1) status: %s\n", $order->status()->value);

\printf("2) replay of req-1 applied? %s\n", $workflow->applyOnce($order, 'ship', 'req-1') ? 'yes' : 'no');

foreach ($workflow->blockers($order, 'ship') as $blocker) {
    \printf("3) ship blocked: %s [%s]\n", $blocker->getMessage(), $blocker->getCode());
}

$order->capturePayment();
$workflow->applyOnce($order, 'ship', idempotencyKey: 'req-2');
\printf("4) status: %s\n", $order->status()->value);

\printf("5) audit trail:\n");

foreach ($log->forSubject('order', 'o-1') as $record) {
    \assert($record instanceof TransitionRecord);
    \printf(
        "   %s %s: %s -> %s (key %s)\n",
        $record->at->format('H:i'),
        $record->transition,
        $record->from,
        $record->to,
        $record->idempotencyKey ?? '-',
    );
}
