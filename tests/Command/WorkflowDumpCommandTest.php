<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Tests\Command;

use Rasuvaeff\Yii3Workflow\Command\WorkflowDumpCommand;
use Rasuvaeff\Yii3Workflow\Tests\Support\Clocks;
use Rasuvaeff\Yii3Workflow\Tests\Support\Definitions;
use Rasuvaeff\Yii3Workflow\WorkflowFactory;
use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WorkflowDumpCommand::class)]
final class WorkflowDumpCommandTest
{
    #[DataProvider('formatProvider')]
    public function dumpsTheRequestedFormat(string $format, string $needle): void
    {
        $tester = $this->tester();

        Assert::same($tester->execute(['workflow' => 'order', '--format' => $format]), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains($needle);
    }

    public static function formatProvider(): iterable
    {
        yield 'mermaid' => ['mermaid', 'graph LR'];
        yield 'plantuml' => ['puml', '@startuml'];
        yield 'graphviz' => ['dot', 'digraph'];
    }

    #[DataProvider('petriNetFormatProvider')]
    public function drawsAPetriNetWithExplicitTransitionNodes(string $format, string $needle): void
    {
        $tester = $this->tester();

        Assert::same($tester->execute(['workflow' => 'document', '--format' => $format]), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains($needle);
    }

    public static function petriNetFormatProvider(): iterable
    {
        yield 'mermaid transition nodes' => ['mermaid', 'transition0['];
        yield 'plantuml agents' => ['puml', 'agent '];
        yield 'graphviz transition nodes' => ['dot', 'transition_'];
    }

    public function defaultsToMermaid(): void
    {
        $tester = $this->tester();
        $tester->execute(['workflow' => 'order']);

        Assert::string($tester->getDisplay())->contains('graph LR');
    }

    public function drawsAStateMachineWithDirectLabelledEdges(): void
    {
        // The state-machine flavour puts the transition name ON the edge; the
        // Petri-net flavour would render a separate transition node instead.
        $tester = $this->tester();
        $tester->execute(['workflow' => 'order', '--format' => 'mermaid']);

        Assert::string($tester->getDisplay())->contains('-->|"pay"|');
    }

    public function listsTheConfiguredWorkflowsWithoutAnArgument(): void
    {
        $tester = $this->tester();

        Assert::same($tester->execute([]), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Configured workflows:');
        Assert::string($tester->getDisplay())->contains('- order');
    }

    public function anEmptyFormatFallsBackToMermaid(): void
    {
        $tester = $this->tester();
        $tester->execute(['workflow' => 'order', '--format' => '']);

        Assert::string($tester->getDisplay())->contains('graph LR');
    }

    public function failsOnAnUnknownWorkflow(): void
    {
        $tester = $this->tester();

        Assert::same($tester->execute(['workflow' => 'invoice']), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('is not configured');
    }

    public function rejectsAnUnknownFormat(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Unknown format "svg"');

        $this->tester()->execute(['workflow' => 'order', '--format' => 'svg']);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new WorkflowDumpCommand(new WorkflowRegistry(
            ['order' => Definitions::order(), 'document' => Definitions::document()],
            new WorkflowFactory(Clocks::frozen()),
        )));
    }
}
