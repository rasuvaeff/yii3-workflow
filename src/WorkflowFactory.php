<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Yii3Workflow\Audit\AuditListener;
use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Validator\StateMachineValidator;
use Symfony\Component\Workflow\Validator\WorkflowValidator;
use Symfony\Component\Workflow\Workflow;

/**
 * Builds a workflow from the array shape used in `params.php`:
 *
 * ```php
 * 'duel' => [
 *     'type' => 'state_machine',                 // or 'workflow' (Petri net)
 *     'initial' => 'pending',
 *     'places' => ['pending', 'active', 'completed'],
 *     'markingStore' => ['type' => 'enum', 'enum' => DuelStatus::class, 'property' => 'status'],
 *     'transitions' => [
 *         ['name' => 'activate', 'from' => 'pending', 'to' => 'active'],
 *     ],
 * ]
 * ```
 *
 * Guards and other reactions are NOT part of the definition: they are plain
 * PSR-14 listeners on the workflow event classes, registered wherever the
 * application registers its listeners.
 *
 * @api
 */
final readonly class WorkflowFactory
{
    private const string TYPE_STATE_MACHINE = 'state_machine';
    private const string TYPE_WORKFLOW = 'workflow';

    public function __construct(
        private ClockInterface $clock,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?TransitionLog $log = null,
        private ?IdempotencyContext $idempotency = null,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public function create(string $name, array $definition): IdempotentWorkflow
    {
        $idempotency = $this->idempotency ?? new IdempotencyContext();
        $listeners = [];

        if ($this->log !== null) {
            $listeners[] = new AuditListener($this->log, $this->clock, $idempotency);
        }

        $dispatcher = new WorkflowEventDispatcher($this->dispatcher, $listeners);
        $type = $this->type($name, $definition);
        $graph = $this->definition($name, $definition, $type);
        $store = $this->markingStore($name, $definition);

        // Validate eagerly: an unreachable transition is a configuration bug,
        // and Symfony only checks this when the FrameworkBundle builds the
        // definition — nothing validates a hand-built one.
        if ($type === self::TYPE_WORKFLOW) {
            (new WorkflowValidator())->validate($graph, $name);

            $workflow = new Workflow($graph, $store, $dispatcher, $name);
        } else {
            (new StateMachineValidator())->validate($graph, $name);

            $workflow = new StateMachine($graph, $store, $dispatcher, $name);
        }

        return new IdempotentWorkflow($workflow, $this->log, $idempotency);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function type(string $name, array $definition): string
    {
        $type = $definition['type'] ?? self::TYPE_STATE_MACHINE;

        if ($type !== self::TYPE_STATE_MACHINE && $type !== self::TYPE_WORKFLOW) {
            throw new \InvalidArgumentException(\sprintf(
                'Workflow "%s": "type" must be "%s" or "%s"',
                $name,
                self::TYPE_STATE_MACHINE,
                self::TYPE_WORKFLOW,
            ));
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function definition(string $name, array $definition, string $type): Definition
    {
        $places = $this->places($name, $definition);
        $initial = $definition['initial'] ?? null;

        if ($initial instanceof \BackedEnum) {
            $initial = (string) $initial->value;
        }

        if (!\is_string($initial) || $initial === '') {
            throw new \InvalidArgumentException(\sprintf('Workflow "%s": "initial" must be a place name', $name));
        }

        return new Definition($places, $this->transitions($name, $definition, $type), [$initial]);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private function places(string $name, array $definition): array
    {
        $places = $definition['places'] ?? null;

        if (!\is_array($places) || $places === []) {
            throw new \InvalidArgumentException(\sprintf('Workflow "%s": "places" must be a non-empty array', $name));
        }

        return \array_values(\array_map(
            fn(mixed $place): string => $this->placeName($name, $place),
            $places,
        ));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<Transition>
     */
    private function transitions(string $name, array $definition, string $type): array
    {
        $transitions = $definition['transitions'] ?? null;

        if (!\is_array($transitions) || $transitions === []) {
            throw new \InvalidArgumentException(
                \sprintf('Workflow "%s": "transitions" must be a non-empty array', $name),
            );
        }

        $list = [];

        foreach ($transitions as $transition) {
            if ($transition instanceof Transition) {
                $list[] = $transition;

                continue;
            }

            if (!\is_array($transition)) {
                throw new \InvalidArgumentException(
                    \sprintf('Workflow "%s": each transition must be an array or a Transition', $name),
                );
            }

            $transitionName = $transition['name'] ?? null;

            if (!\is_string($transitionName) || $transitionName === '') {
                throw new \InvalidArgumentException(
                    \sprintf('Workflow "%s": each transition needs a non-empty "name"', $name),
                );
            }

            $from = $this->placeList($name, $transition['from'] ?? null, 'from');
            $to = $this->placeList($name, $transition['to'] ?? null, 'to');

            if ($type === self::TYPE_WORKFLOW) {
                $list[] = new Transition($transitionName, $from, $to);

                continue;
            }

            // A state machine transition carries exactly one source, so
            // `from: [pending, paid]` expands into one transition per source —
            // the same normalisation Symfony's YAML config performs.
            foreach ($from as $source) {
                $list[] = new Transition($transitionName, $source, $to);
            }
        }

        return $list;
    }

    /**
     * @return list<string>
     */
    private function placeList(string $workflow, mixed $places, string $key): array
    {
        if ($places === null) {
            throw new \InvalidArgumentException(
                \sprintf('Workflow "%s": each transition needs "%s"', $workflow, $key),
            );
        }

        $list = \is_array($places) ? $places : [$places];

        return \array_values(\array_map(
            fn(mixed $place): string => $this->placeName($workflow, $place),
            $list,
        ));
    }

    private function placeName(string $workflow, mixed $place): string
    {
        if ($place instanceof \BackedEnum) {
            return (string) $place->value;
        }

        if (\is_string($place) && $place !== '') {
            return $place;
        }

        throw new \InvalidArgumentException(
            \sprintf('Workflow "%s": a place must be a non-empty string or a backed enum', $workflow),
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function markingStore(string $name, array $definition): MarkingStoreInterface
    {
        $store = $definition['markingStore'] ?? null;

        if ($store instanceof MarkingStoreInterface) {
            return $store;
        }

        if (!\is_array($store)) {
            throw new \InvalidArgumentException(
                \sprintf('Workflow "%s": "markingStore" must be an array or a MarkingStoreInterface', $name),
            );
        }

        $property = $store['property'] ?? 'status';

        if (!\is_string($property) || $property === '') {
            throw new \InvalidArgumentException(
                \sprintf('Workflow "%s": marking store "property" must be a non-empty string', $name),
            );
        }

        if (($store['type'] ?? 'enum') === 'method') {
            return new MethodMarkingStore(true, $property);
        }

        $enum = $store['enum'] ?? null;

        if (!\is_string($enum) || !\is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(
                \sprintf('Workflow "%s": marking store "enum" must be a backed enum class name', $name),
            );
        }

        return new EnumMarkingStore($enum, $property);
    }
}
