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
        yield 'petri net graphviz' => ['workflow-dot', 'digraph'];
    }

    public function defaultsToMermaid(): void
    {
        $tester = $this->tester();
        $tester->execute(['workflow' => 'order']);

        Assert::string($tester->getDisplay())->contains('graph LR');
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
            ['order' => Definitions::order()],
            new WorkflowFactory(Clocks::frozen()),
        )));
    }
}
