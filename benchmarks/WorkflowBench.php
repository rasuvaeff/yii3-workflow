<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Benchmarks;

use Rasuvaeff\Yii3Workflow\IdempotentWorkflow;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\Tests\Support\Order;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Symfony\Component\Workflow\Marking;
use Testo\Bench;

/**
 * Each benchmark isolates one cost. Building a workflow parses the definition
 * and runs Symfony's validator — orders of magnitude more expensive than
 * applying a transition, so the shared workflow is built once and reused.
 * Measuring both together would hide the hot path entirely.
 */
final class WorkflowBench
{
    private static ?WorkflowFactory $factory = null;

    private static ?IdempotentWorkflow $workflow = null;

    /** Cold path: what a registry pays once per workflow, on first use. */
    #[Bench(
        callables: ['build from the params array' => [self::class, 'build']],
        calls: 2_000,
        iterations: 5,
    )]
    public static function buildWorkflowFromDefinition(): IdempotentWorkflow
    {
        return self::build();
    }

    /** Hot path: one transition on an already built workflow. */
    #[Bench(
        callables: ['apply a transition' => [self::class, 'apply']],
        calls: 20_000,
        iterations: 10,
    )]
    public static function applyTransition(): Marking
    {
        return self::apply();
    }

    /** Hot path: an eligibility check — guards run, nothing changes. */
    #[Bench(
        callables: ['can()' => [self::class, 'check']],
        calls: 20_000,
        iterations: 10,
    )]
    public static function canCheck(): bool
    {
        return self::check();
    }

    public static function build(): IdempotentWorkflow
    {
        return self::factory()->create('order', Definitions::order());
    }

    public static function apply(): Marking
    {
        return self::workflow()->apply(new Order(), 'pay');
    }

    public static function check(): bool
    {
        return self::workflow()->can(new Order(), 'pay');
    }

    private static function factory(): WorkflowFactory
    {
        return self::$factory ??= new WorkflowFactory(Clocks::frozen());
    }

    private static function workflow(): IdempotentWorkflow
    {
        return self::$workflow ??= self::build();
    }
}
