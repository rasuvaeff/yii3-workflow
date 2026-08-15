<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\EnumMarkingStore;
use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\Tests\Support\Order;
use Rasuvaeff\Yii3Workflow\Tests\Support\OrderStatus;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Exception\InvalidDefinitionException;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\TransitionBlocker;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use Yiisoft\Test\Support\EventDispatcher\SimpleEventDispatcher;

#[Test]
#[Covers(WorkflowFactory::class)]
final class WorkflowFactoryTest
{
    public function buildsAStateMachineFromTheParamsShape(): void
    {
        $workflow = $this->factory()->create('order', Definitions::order());
        $order = new Order();

        Assert::same($workflow->name(), 'order');
        $workflow->apply($order, 'pay');
        Assert::same($order->status(), OrderStatus::Paid);
    }

    public function expandsAMultiSourceTransitionIntoOnePerSource(): void
    {
        $workflow = $this->factory()->create('order', Definitions::order());

        $names = \array_map(
            static fn(Transition $t): string => $t->getName() . ':' . \implode('|', $t->getFroms()),
            $workflow->definition()->getTransitions(),
        );

        Assert::same($names, ['pay:pending', 'ship:paid', 'cancel:pending', 'cancel:paid']);
        Assert::true($workflow->can(new Order(), 'cancel'));
    }

    public function guardsAreOrdinaryPsrListeners(): void
    {
        $dispatcher = new SimpleEventDispatcher(static function (object $event): void {
            if ($event instanceof GuardEvent && $event->getTransition()->getName() === 'ship') {
                $event->addTransitionBlocker(new TransitionBlocker('Not paid yet', 'order.unpaid'));
            }
        });

        $workflow = (new WorkflowFactory(Clocks::frozen(), $dispatcher))->create('order', Definitions::order());
        $order = new Order();
        $workflow->apply($order, 'pay');
        $dispatcher->clearEvents();

        $blockers = $workflow->blockers($order, 'ship');

        Assert::false($workflow->can($order, 'ship'));
        Assert::same(\iterator_to_array($blockers)[0]->getMessage(), 'Not paid yet');

        // Workflow dispatches a guard event three times (generic, per-workflow,
        // per-transition); the bridge must deliver it once per check — twice
        // here, for blockers() and can().
        Assert::true($dispatcher->isInstanceOfTriggered(GuardEvent::class, 2));
    }

    public function usesTheInjectedIdempotencyContext(): void
    {
        $log = new InMemoryTransitionLog();
        $idempotency = new IdempotencyContext();
        $workflow = (new WorkflowFactory(
            clock: Clocks::frozen(),
            log: $log,
            idempotency: $idempotency,
        ))->create('order', Definitions::order());

        // The audit row must pick the key up from the context we passed in, not
        // from one the factory made for itself.
        $idempotency->during('outer-key', static function () use ($workflow): void {
            $workflow->apply(new Order(), 'pay');
        });

        Assert::same($log->all()[0]->idempotencyKey, 'outer-key');
    }

    public function validatesAPetriNetDefinitionToo(): void
    {
        $definition = Definitions::order();
        $definition['type'] = 'workflow';
        $definition['markingStore'] = new MethodMarkingStore(singleState: true, property: 'marking');
        $definition['transitions'] = [
            ['name' => 'pay', 'from' => 'pending', 'to' => 'paid'],
            ['name' => 'pay', 'from' => 'pending', 'to' => 'shipped'],
        ];

        Expect::exception(InvalidDefinitionException::class)->withMessageContaining('unique name');

        $this->factory()->create('order', $definition);
    }

    public function keepsAnInitialPlaceThatIsNotTheFirstOne(): void
    {
        $definition = Definitions::order();
        $definition['initial'] = OrderStatus::Paid;

        Assert::same(
            $this->factory()->create('order', $definition)->definition()->getInitialPlaces(),
            ['paid'],
        );
    }

    public function keepsEveryTransitionAfterAReadyMadeOne(): void
    {
        $definition = Definitions::order();
        $definition['transitions'] = [
            new Transition('pay', 'pending', 'paid'),
            ['name' => 'ship', 'from' => 'paid', 'to' => 'shipped'],
        ];

        Assert::same(
            \array_map(
                static fn(Transition $t): string => $t->getName(),
                $this->factory()->create('order', $definition)->definition()->getTransitions(),
            ),
            ['pay', 'ship'],
        );
    }

    public function theMethodMarkingStoreIsSingleState(): void
    {
        $definition = Definitions::order();
        $definition['markingStore'] = ['type' => 'method', 'property' => 'marking'];
        $subject = new class {
            public string $marking = 'pending';
        };

        $this->factory()->create('order', $definition)->apply($subject, 'pay');

        // singleState=true keeps a plain place name; the multi-place store would
        // have written ['paid' => 1] instead.
        Assert::same($subject->marking, 'paid');
    }

    public function rejectsAMarkingStoreEnumThatIsNotAnEnum(): void
    {
        $definition = Definitions::order();
        $definition['markingStore'] = ['type' => 'enum', 'enum' => \stdClass::class];

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('must be a backed enum class name');

        $this->factory()->create('order', $definition);
    }

    public function recordsHistoryWhenALogIsBound(): void
    {
        $log = new InMemoryTransitionLog();
        $workflow = (new WorkflowFactory(Clocks::frozen(), log: $log))->create('order', Definitions::order());

        $workflow->apply(new Order(), 'pay');

        Assert::same(\count($log->all()), 1);
        Assert::same($log->all()[0]->workflow, 'order');
    }

    public function worksWithoutALogAndWithoutADispatcher(): void
    {
        $order = new Order();

        $this->factory()->create('order', Definitions::order())->apply($order, 'pay');

        Assert::same($order->status(), OrderStatus::Paid);
    }

    public function buildsAPetriNetWorkflowWhenAsked(): void
    {
        $definition = Definitions::order();
        $definition['type'] = 'workflow';
        $definition['markingStore'] = new MethodMarkingStore(singleState: true, property: 'marking');

        $workflow = $this->factory()->create('order', $definition);

        Assert::same(\count($workflow->definition()->getTransitions()), 3);
    }

    public function acceptsAReadyMadeMarkingStore(): void
    {
        $definition = Definitions::order();
        $definition['markingStore'] = new EnumMarkingStore(OrderStatus::class, 'status');

        Assert::same($this->factory()->create('order', $definition)->name(), 'order');
    }

    public function acceptsAMethodMarkingStoreByType(): void
    {
        $definition = Definitions::order();
        $definition['markingStore'] = ['type' => 'method', 'property' => 'marking'];

        Assert::same($this->factory()->create('order', $definition)->name(), 'order');
    }

    public function exposesWorkflowAndPlaceMetadata(): void
    {
        $definition = Definitions::order();
        $definition['metadata'] = ['title' => 'Order flow', 'owner' => 'sales'];
        $definition['placesMetadata'] = [
            'paid' => ['bg_color' => 'green'],
            'shipped' => ['bg_color' => 'blue'],
        ];

        $store = $this->factory()->create('order', $definition)->definition()->getMetadataStore();

        Assert::same($store->getWorkflowMetadata(), ['title' => 'Order flow', 'owner' => 'sales']);
        Assert::same($store->getPlaceMetadata('paid'), ['bg_color' => 'green']);
        Assert::same($store->getPlaceMetadata('shipped'), ['bg_color' => 'blue']);
        Assert::same($store->getPlaceMetadata('pending'), []);
    }

    public function attachesTransitionMetadataToEveryExpandedCopy(): void
    {
        $definition = Definitions::order();
        $definition['transitions'] = [
            ['name' => 'pay', 'from' => 'pending', 'to' => 'paid'],
            [
                'name' => 'cancel',
                'from' => ['pending', 'paid'],
                'to' => 'cancelled',
                'metadata' => ['label' => 'Cancel the order'],
            ],
        ];

        $graph = $this->factory()->create('order', $definition)->definition();
        $store = $graph->getMetadataStore();
        $byName = [];

        foreach ($graph->getTransitions() as $transition) {
            $byName[$transition->getName() . ':' . \implode('|', $transition->getFroms())]
                = $store->getTransitionMetadata($transition);
        }

        Assert::same($byName, [
            'pay:pending' => [],
            'cancel:pending' => ['label' => 'Cancel the order'],
            'cancel:paid' => ['label' => 'Cancel the order'],
        ]);
    }

    public function exposesTransitionMetadataInAPetriNetToo(): void
    {
        $definition = Definitions::order();
        $definition['type'] = 'workflow';
        $definition['markingStore'] = new MethodMarkingStore(singleState: true, property: 'marking');
        $definition['transitions'] = [
            ['name' => 'pay', 'from' => 'pending', 'to' => 'paid', 'metadata' => ['label' => 'Pay']],
        ];

        $graph = $this->factory()->create('order', $definition)->definition();

        Assert::same(
            $graph->getMetadataStore()->getTransitionMetadata($graph->getTransitions()[0]),
            ['label' => 'Pay'],
        );
    }

    public function acceptsReadyMadeTransitions(): void
    {
        $definition = Definitions::order();
        $definition['transitions'] = [new Transition('pay', 'pending', 'paid')];

        Assert::same(\count($this->factory()->create('order', $definition)->definition()->getTransitions()), 1);
    }

    public function rejectsAStateMachineTransitionWithTwoTargets(): void
    {
        $definition = Definitions::order();
        $definition['transitions'] = [
            ['name' => 'pay', 'from' => 'pending', 'to' => ['paid', 'shipped']],
        ];

        Expect::exception(InvalidDefinitionException::class)->withMessageContaining('can only have one output');

        $this->factory()->create('order', $definition);
    }

    #[DataProvider('malformedDefinitionProvider')]
    public function rejectsAMalformedDefinition(array $definition, string $message): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining($message);

        $this->factory()->create('order', $definition);
    }

    public static function malformedDefinitionProvider(): iterable
    {
        $base = Definitions::order();

        yield 'unknown type' => [['type' => 'petri'] + $base, '"type" must be'];
        yield 'no places' => [['places' => []] + $base, '"places" must be a non-empty array'];
        yield 'no initial' => [['initial' => null] + $base, '"initial" must be a place name'];
        yield 'no transitions' => [['transitions' => []] + $base, '"transitions" must be a non-empty array'];
        yield 'transition not an array' => [['transitions' => ['pay']] + $base, 'must be an array or a Transition'];
        yield 'transition without a name' => [
            ['transitions' => [['from' => 'pending', 'to' => 'paid']]] + $base,
            'needs a non-empty "name"',
        ];
        yield 'transition without a source' => [
            ['transitions' => [['name' => 'pay', 'to' => 'paid']]] + $base,
            'needs "from"',
        ];
        yield 'bad place type' => [
            ['transitions' => [['name' => 'pay', 'from' => 1, 'to' => 'paid']]] + $base,
            'a place must be a non-empty string or a backed enum',
        ];
        yield 'marking store not an array' => [['markingStore' => 'enum'] + $base, '"markingStore" must be an array'];
        yield 'marking store without an enum' => [
            ['markingStore' => ['type' => 'enum']] + $base,
            'must be a backed enum class name',
        ];
        yield 'marking store with an empty property' => [
            ['markingStore' => ['type' => 'method', 'property' => '']] + $base,
            '"property" must be a non-empty string',
        ];
        yield 'metadata not an array' => [['metadata' => 'title'] + $base, '"metadata" must be an array'];
        yield 'metadata with non-string keys' => [
            ['metadata' => ['a', 'b']] + $base,
            '"metadata" keys must be strings',
        ];
        yield 'placesMetadata not an array' => [
            ['placesMetadata' => 'paid'] + $base,
            '"placesMetadata" must be an array keyed by place name',
        ];
        yield 'placesMetadata for an unknown place' => [
            ['placesMetadata' => ['refunded' => ['bg_color' => 'red']]] + $base,
            'placesMetadata refers to unknown place "refunded"',
        ];
        yield 'transition metadata not an array' => [
            ['transitions' => [['name' => 'pay', 'from' => 'pending', 'to' => 'paid', 'metadata' => 'x']]] + $base,
            'metadata of transition "pay" must be an array',
        ];
    }

    private function factory(): WorkflowFactory
    {
        return new WorkflowFactory(Clocks::frozen());
    }
}
