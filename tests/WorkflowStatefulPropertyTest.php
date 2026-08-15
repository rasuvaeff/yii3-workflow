<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Yii3Workflow\Audit\InMemoryTransitionLog;
use Rasuvaeff\Yii3Workflow\Audit\TransitionRecord;
use Rasuvaeff\Yii3Workflow\EnumMarkingStore;
use Rasuvaeff\Yii3Workflow\Tests\Support\ApplyTransitionCommand;
use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\Tests\Support\Task;
use Rasuvaeff\Yii3Workflow\Tests\Support\TaskStatus;
use Rasuvaeff\Yii3Workflow\Tests\Support\TaskWorkflowHarness;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Model-based test: the model is the expected {@see TaskStatus} after a
 * sequence of transitions on the "task" graph ({@see Definitions::task()}, a
 * cyclic Doing<->Blocked state machine), driven against a real
 * WorkflowFactory-built workflow. This targets the package's own code — the
 * config-to-Definition factory, EnumMarkingStore, and the transition audit
 * trail — not symfony/workflow's Petri-net internals.
 */
#[Test]
#[Covers(WorkflowFactory::class)]
#[Covers(EnumMarkingStore::class)]
#[Covers(InMemoryTransitionLog::class)]
final class WorkflowStatefulPropertyTest
{
    /** @return list<ApplyTransitionCommand> */
    private static function edges(): array
    {
        return [
            new ApplyTransitionCommand(TaskStatus::Todo, 'start', TaskStatus::Doing),
            new ApplyTransitionCommand(TaskStatus::Doing, 'block', TaskStatus::Blocked),
            new ApplyTransitionCommand(TaskStatus::Blocked, 'unblock', TaskStatus::Doing),
            new ApplyTransitionCommand(TaskStatus::Doing, 'finish', TaskStatus::Done),
        ];
    }

    /** @return list<ArbitraryInterface> */
    private static function commandGenerators(): array
    {
        return \array_map(
            Gen::constant(...),
            self::edges(),
        );
    }

    #[Property(runs: 300, timeoutMs: 2000)]
    public function markingAndAuditLogTrackTheModelThroughAnySequence(CommandSequence $sequence): void
    {
        $harness = null;

        $names = [];

        foreach ($sequence->commands as $command) {
            \assert($command instanceof ApplyTransitionCommand);
            $names[$command->transitionName()] = true;
        }

        // Swarming is what puts these within reach. Measured over 400
        // sequences: 35.2% reach Done, 23.8% move the task without ever
        // finishing it, 35.5% never block. Drawing all four edges uniformly,
        // a sequence that never finishes needs every pick to miss one edge.
        // The price is that 41% of subsets contain no edge out of Todo and
        // yield an empty sequence, which is why runs went from 200 to 300.
        Classify::cover(isset($names['finish']), 'reached Done', 15.0);
        Classify::cover($names !== [] && !isset($names['finish']), 'moved but never finished', 10.0);
        Classify::when($names === [], 'subset with no edge out of Todo');

        StateMachine::check($sequence, static function () use (&$harness): TaskWorkflowHarness {
            $log = new InMemoryTransitionLog();
            $task = new Task();
            $workflow = (new WorkflowFactory(Clocks::frozen(), log: $log))->create('task', Definitions::task());

            return $harness = new TaskWorkflowHarness($task, $workflow, $log);
        });

        \assert($harness instanceof TaskWorkflowHarness);

        // Every command's postCondition already checked the marking against
        // the model at each step; this checks the final state is one of the
        // graph's own configured places, not just the enum's PHP type.
        $places = \array_map(static fn(TaskStatus $status): string => $status->value, TaskStatus::cases());
        Assert::true(\in_array($harness->task->status()->value, $places, strict: true));

        // The audit trail holds exactly the applied sequence, in order.
        $recorded = \array_map(
            static fn(TransitionRecord $record): string => $record->transition,
            $harness->log->forSubject('task', $harness->task->workflowSubjectId()),
        );
        Assert::same($recorded, $harness->applied);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function markingAndAuditLogTrackTheModelThroughAnySequenceGenerators(): array
    {
        // Swarmed: a sequence may use only a subset of the four edges, so the
        // runs that never finish a task, or never block one, stop being
        // astronomically rare. A subset without `start` leaves the task in
        // Todo and yields an empty sequence — minLength stays 0 so that is a
        // legal outcome rather than GenerationExhausted.
        return ['sequence' => Gen::swarm(
            Gen::commands(TaskStatus::Todo, self::commandGenerators(), minLength: 0, maxLength: 40),
        )];
    }

    #[Property(runs: 100)]
    public function reapplyingTheSameIdempotencyKeyIsANoOp(CommandSequence $sequence): void
    {
        $log = new InMemoryTransitionLog();
        $task = new Task();
        $workflow = (new WorkflowFactory(Clocks::frozen(), log: $log))->create('task', Definitions::task());

        $commands = $sequence->commands;
        // A minLength:1 generator can rarely exhaust its pick budget when only
        // one of several commands applies from the initial model (see
        // Gen::commands()'s MAX_PICK_ATTEMPTS) — fall back to a known-valid
        // single command instead of relying on generation-time luck.
        $last = \array_pop($commands) ?? new ApplyTransitionCommand(TaskStatus::Todo, 'start', TaskStatus::Doing);
        \assert($last instanceof ApplyTransitionCommand);

        foreach ($commands as $command) {
            \assert($command instanceof ApplyTransitionCommand);
            $workflow->apply($task, $command->transitionName());
        }

        Assert::true($workflow->applyOnce($task, $last->transitionName(), 'fixed-key'));

        $statusAfterFirst = $task->status();
        $countAfterFirst = \count($log->forSubject('task', $task->workflowSubjectId()));

        Assert::false($workflow->applyOnce($task, $last->transitionName(), 'fixed-key'));
        Assert::same($task->status(), $statusAfterFirst);
        Assert::same(\count($log->forSubject('task', $task->workflowSubjectId())), $countAfterFirst);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function reapplyingTheSameIdempotencyKeyIsANoOpGenerators(): array
    {
        return ['sequence' => Gen::commands(TaskStatus::Todo, self::commandGenerators(), minLength: 0, maxLength: 20)];
    }
}
