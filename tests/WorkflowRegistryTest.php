<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WorkflowRegistry::class)]
final class WorkflowRegistryTest
{
    public function returnsAConfiguredWorkflow(): void
    {
        Assert::same($this->registry()->get('order')->name(), 'order');
    }

    public function buildsEachWorkflowOnce(): void
    {
        $registry = $this->registry();

        Assert::same($registry->get('order'), $registry->get('order'));
    }

    public function listsAndChecksNames(): void
    {
        $registry = $this->registry();

        Assert::same($registry->names(), ['order']);
        Assert::true($registry->has('order'));
        Assert::false($registry->has('invoice'));
    }

    public function rejectsAnUnconfiguredName(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('Workflow "invoice" is not configured');

        $this->registry()->get('invoice');
    }

    public function buildsLazilySoABrokenDefinitionFailsOnlyWhenUsed(): void
    {
        $registry = new WorkflowRegistry(
            ['order' => Definitions::order(), 'broken' => ['places' => []]],
            new WorkflowFactory(Clocks::frozen()),
        );

        Assert::same($registry->get('order')->name(), 'order');

        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('"places" must be a non-empty array');

        $registry->get('broken');
    }

    private function registry(): WorkflowRegistry
    {
        return new WorkflowRegistry(
            ['order' => Definitions::order()],
            new WorkflowFactory(Clocks::frozen()),
        );
    }
}
