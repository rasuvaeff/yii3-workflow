<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Rasuvaeff\Yii3Workflow\Audit\IdempotencyContext;
use Rasuvaeff\Yii3Workflow\Audit\TransitionLog;
use Rasuvaeff\Yii3Workflow\Command\WorkflowDumpCommand;
use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

/**
 * `config/di.php` is covered by neither psalm (src-only), nor php-cs-fixer, nor
 * the type checker — so the build gate exercises it here instead.
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function diDefinesTheDocumentedServices(): void
    {
        $definitions = $this->di();

        Assert::same(\array_keys($definitions), [
            IdempotencyContext::class,
            WorkflowFactory::class,
            WorkflowRegistry::class,
        ]);
    }

    public function theSwappableAuditBackendIsNotBoundByThisPackage(): void
    {
        // yiisoft/config refuses two vendor packages defining one key in a
        // group; the audit backend must be bound by exactly one source — an
        // application or a backend package, never the core.
        Assert::false(\array_key_exists(TransitionLog::class, $this->di()));
        Assert::false(\array_key_exists(ClockInterface::class, $this->di()));
        Assert::false(\array_key_exists(EventDispatcherInterface::class, $this->di()));
    }

    public function theRegistryFactoryBuildsFromParams(): void
    {
        $definitions = $this->di(['workflows' => ['order' => Definitions::order()]]);
        $factory = $definitions[WorkflowRegistry::class];

        Assert::true(\is_callable($factory));

        /** @var WorkflowRegistry $registry */
        $registry = $factory(new WorkflowFactory(Clocks::frozen()));

        Assert::same($registry->names(), ['order']);
        Assert::same($registry->get('order')->name(), 'order');
    }

    public function theRegistryToleratesMissingOrMalformedParams(): void
    {
        Assert::same($this->registryFrom($this->di())->names(), []);
        Assert::same($this->registryFrom($this->diRaw([]))->names(), []);
        Assert::same($this->registryFrom($this->diRaw(['rasuvaeff/yii3-workflow' => 'nonsense']))->names(), []);
        Assert::same(
            $this->registryFrom($this->diRaw(['rasuvaeff/yii3-workflow' => ['workflows' => 'nonsense']]))->names(),
            [],
        );
    }

    public function paramsRegisterTheDumpCommand(): void
    {
        $params = require \dirname(__DIR__) . '/config/params.php';

        Assert::same($params['yiisoft/yii-console']['commands']['workflow:dump'], WorkflowDumpCommand::class);
        Assert::same($params['rasuvaeff/yii3-workflow']['workflows'], []);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function di(array $config = []): array
    {
        return $this->diRaw(['rasuvaeff/yii3-workflow' => $config]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function diRaw(array $params): array
    {
        return (static fn(array $params): array => require \dirname(__DIR__) . '/config/di.php')($params);
    }

    /** @param array<string, mixed> $definitions */
    private function registryFrom(array $definitions): WorkflowRegistry
    {
        $factory = $definitions[WorkflowRegistry::class];

        /** @var WorkflowRegistry */
        return $factory(new WorkflowFactory(Clocks::frozen()));
    }
}
