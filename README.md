# rasuvaeff/yii3-workflow

![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-workflow?label=stable)
![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-workflow?label=downloads)
![Build](https://github.com/rasuvaeff/yii3-workflow/actions/workflows/build.yml/badge.svg)
![Static analysis](https://github.com/rasuvaeff/yii3-workflow/actions/workflows/static-analysis.yml/badge.svg)
![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-workflow?label=license)

Yii3 integration for [`symfony/workflow`](https://symfony.com/doc/current/workflow.html):
the wiring Symfony's FrameworkBundle provides, plus the two things the component
deliberately leaves out — a transition history and replay protection.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact API reference
> designed for LLMs.

[Русская версия](README.ru.md)

## What it does

- **Workflows by name.** Definitions live in `params.php`; `WorkflowRegistry`
  builds them lazily and hands them out from the container.
- **Enum marking store.** Reads and writes a backed-enum status property of any
  visibility, so an aggregate does not need a public setter to be workflow-aware.
- **PSR-14, not Symfony's dispatcher.** Guards and reactions are ordinary
  listeners on the workflow event classes, dispatched through the application's
  own PSR-14 dispatcher (`yiisoft/event-dispatcher`). The package depends on the
  event-dispatcher *contracts*, not on an implementation.
- **Audit trail.** `symfony/workflow` stores only the current marking; this
  package records one row per committed transition through a `TransitionLog`
  seam you bind to your storage.
- **Idempotency.** `applyOnce($subject, $transition, $key)` skips a transition
  whose key is already in the log — the answer to a double-submitted form. Two
  lines of defence: a pre-flight lookup, and the log's own uniqueness
  constraint, which decides a race the lookup cannot.
- **Eager validation.** Definitions are checked with Symfony's own validators
  when a workflow is built, so a transition that could never fire is an error,
  not a silent no-op.
- **`workflow:dump`.** A console command rendering any configured workflow as
  Mermaid, PlantUML or Graphviz.

## Requirements

- PHP 8.3 / 8.4 / 8.5
- `symfony/workflow` ^6.4 / ^7.0 / ^8.0
- `psr/clock`, `psr/event-dispatcher`, `symfony/event-dispatcher-contracts`
- `symfony/console` (for the dump command), `yiisoft/definitions`

## Installation

```bash
composer require rasuvaeff/yii3-workflow
```

`yiisoft/config` picks the package up automatically. Nothing else is bound by
default — see [Audit trail](#audit-trail) for the one binding you may want to add.

## Configuration

```php
// config/common/params.php
use App\Domain\Order\OrderStatus;

return [
    'rasuvaeff/yii3-workflow' => [
        'workflows' => [
            'order' => [
                'type' => 'state_machine',            // or 'workflow' for a Petri net
                'initial' => OrderStatus::Pending,
                'places' => OrderStatus::cases(),
                'markingStore' => [
                    'type' => 'enum',                 // or 'method' for getX()/setX()
                    'enum' => OrderStatus::class,
                    'property' => 'status',
                ],
                'transitions' => [
                    ['name' => 'pay', 'from' => OrderStatus::Pending, 'to' => OrderStatus::Paid],
                    ['name' => 'ship', 'from' => OrderStatus::Paid, 'to' => OrderStatus::Shipped],
                    ['name' => 'cancel', 'from' => [OrderStatus::Pending, OrderStatus::Paid], 'to' => OrderStatus::Cancelled],
                ],
            ],
        ],
    ],
];
```

Places and transition endpoints accept backed enums or plain strings. In a state
machine a transition with several sources is expanded into one transition per
source — the same normalisation Symfony's YAML config performs.

Metadata is declarative too — it lands in the definition's metadata store,
readable by guards (`getMetadataStore()`) and rendered by the dumpers:

```php
'order' => [
    // ...
    'metadata' => ['title' => 'Order flow'],
    'placesMetadata' => ['paid' => ['bg_color' => 'LightBlue']],   // keyed by place NAME
    'transitions' => [
        ['name' => 'ship', 'from' => OrderStatus::Paid, 'to' => OrderStatus::Shipped,
         'metadata' => ['label' => 'Ship the order']],
    ],
],
```

A `placesMetadata` key that names an unknown place is an error, not silently
dead configuration.

## Usage

```php
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;

final readonly class ShipOrderHandler
{
    public function __construct(private WorkflowRegistry $registry) {}

    public function handle(Order $order, string $requestId): void
    {
        $workflow = $this->registry->get('order');

        if (!$workflow->applyOnce($order, 'ship', idempotencyKey: $requestId)) {
            return;   // already applied for this request — nothing to do
        }

        $this->orders->save($order);
    }
}
```

| Method | Returns | Notes |
|---|---|---|
| `applyOnce($subject, $transition, $key = null, $context = [])` | `bool` | `false` = replay skipped |
| `apply($subject, $transition, $context = [])` | `Marking` | No idempotency check |
| `can($subject, $transition)` | `bool` | Guards are evaluated |
| `blockers($subject, $transition)` | `TransitionBlockerList` | Why it is unavailable |
| `enabledTransitions($subject)` | `iterable<Transition>` | Currently possible transitions |
| `name()` / `definition()` / `workflow()` | — | Name, graph, the wrapped `WorkflowInterface` |

A key is scoped to *(workflow, subject)*, not to a transition: reusing a key on
the same subject with a different transition is reported as a replay (`false`),
so keys must be unique per operation. `applyOnce()` refuses a key its wiring
cannot honour — no `TransitionLog`, no `IdempotencyContext`, or an
`AuditListener` that never recorded the key — with a `LogicException` instead of
silently applying without protection.

With a persistent log the audit row is written inside `apply()`: run
`applyOnce()` and your `save()` in **one database transaction**. Otherwise a
failed save burns the key — the log says the transition happened, the entity
never changed, and every retry is skipped as a replay. See the
[yii3-workflow-db README](https://github.com/rasuvaeff/yii3-workflow-db#transactions)
for the recipe — that package also ships a `WorkflowTransaction` helper that
makes it a single call.

Every skipped replay is also dispatched to the application's PSR-14 dispatcher
as an `Audit\TransitionReplayed` event (workflow, subject id, transition, key,
and whether the pre-flight lookup or the storage constraint decided), so
replays can be counted and logged instead of disappearing into a `false`.

### Guards and reactions

Every workflow event reaches the application's PSR-14 dispatcher exactly once —
under the generic name, not once per `workflow.<name>.<event>` variant — so a
class-based listener is not called three times. Guard listeners therefore run
for every workflow of the application; extend `TransitionGuard` and the
"is this my workflow, is this my transition" filtering is done for you:

```php
use Rasuvaeff\Yii3Workflow\TransitionGuard;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

final class ShipOnlyWhenPaid extends TransitionGuard
{
    protected function workflow(): string
    {
        return 'order';
    }

    protected function transitions(): array
    {
        return ['ship'];   // empty array = every transition of the workflow
    }

    protected function guard(GuardEvent $event): void
    {
        if (!$event->getSubject()->isPaid()) {
            $event->addTransitionBlocker(new TransitionBlocker('Payment is not captured', 'order.unpaid'));
        }
    }
}
```

A plain PSR-14 listener on the event class still works — the base class only
removes the filtering ritual.

Register it like any other Yii3 listener (`config/common/events-web.php`).
The blocker's message and code come back through `blockers()`, so an API can
tell the user why an action is unavailable.

### Audit trail

`TransitionLog` is deliberately **not** bound by this package — exactly like
`yiisoft/cache` leaves `Psr\SimpleCache\CacheInterface` to a backend. Without a
binding the workflows still run and record nothing; passing an idempotency key
in that state is refused with a `LogicException` rather than silently doing
nothing.

Install [`rasuvaeff/yii3-workflow-db`](https://github.com/rasuvaeff/yii3-workflow-db)
for a `yiisoft/db` implementation with the migration, or bind your own:

```php
// config/common/di/workflow.php
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;

return [
    TransitionLog::class => App\Infrastructure\DbTransitionLog::class,
];
```

An implementation stores `TransitionRecord::toArray()` rows and answers
`hasIdempotencyKey()`. `InMemoryTransitionLog` ships for tests and one-off
scripts.

### Dumping a diagram

`WorkflowDumpCommand` is registered as `workflow:dump` through `params.php`:

```bash
./yii workflow:dump                     # list configured workflows
./yii workflow:dump order               # Mermaid (default)
./yii workflow:dump order --format=puml # PlantUML
./yii workflow:dump order --format=dot  # Graphviz
```

The diagram flavour follows the configured `type`: a `state_machine` is drawn
with direct edges between places, a Petri-net `workflow` with explicit
transition nodes.

## Security

The package performs no I/O of its own: it moves a marking on an object you
pass in and hands audit rows to your `TransitionLog`. Transition and place names
come from your configuration, never from user input — keep it that way, since a
name reaching `apply()` selects behaviour. Guard listeners are the place for
authorisation checks; a transition without a guard is allowed for anyone who can
reach the handler.

## Examples

See [`examples/`](examples/) for a runnable script covering the whole loop.

## Development

```bash
make install
make build       # validate + normalize + require-checker + cs + psalm + test
make cs-fix
make psalm
make test
make mutation    # requires pcov; the Makefile bootstraps it
```

No PHP or Composer on the host — every target runs inside the `composer:2`
Docker image.

## License

BSD-3-Clause. See [`LICENSE.md`](LICENSE.md).
