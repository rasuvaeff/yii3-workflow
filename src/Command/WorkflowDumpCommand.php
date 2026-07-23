<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow\Command;

use Rasuvaeff\Yii3Workflow\WorkflowRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Workflow\Dumper\DumperInterface;
use Symfony\Component\Workflow\Dumper\GraphvizDumper;
use Symfony\Component\Workflow\Dumper\MermaidDumper;
use Symfony\Component\Workflow\Dumper\PlantUmlDumper;
use Symfony\Component\Workflow\Dumper\StateMachineGraphvizDumper;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\StateMachine;

/**
 * Prints a configured workflow as a diagram — the Yii3 counterpart of
 * Symfony's `workflow:dump`, so a graph can be pasted into a README or a pull
 * request instead of being read as a list of transitions.
 *
 * The diagram flavour follows the configured `type`: a `state_machine` is drawn
 * with direct edges, a Petri-net `workflow` with explicit transition nodes.
 *
 * @api
 */
#[AsCommand(name: 'workflow:dump', description: 'Dump a configured workflow as a diagram')]
final class WorkflowDumpCommand extends Command
{
    public function __construct(private readonly WorkflowRegistry $registry)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('workflow', InputArgument::OPTIONAL, 'Workflow name; omit to list the configured ones')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'mermaid, puml, dot', 'mermaid');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('workflow');

        if (!\is_string($name) || $name === '') {
            $output->writeln('Configured workflows:');

            foreach ($this->registry->names() as $configured) {
                $output->writeln('  - ' . $configured);
            }

            return Command::SUCCESS;
        }

        if (!$this->registry->has($name)) {
            $output->writeln(\sprintf('<error>Workflow "%s" is not configured</error>', $name));

            return Command::FAILURE;
        }

        $workflow = $this->registry->get($name);

        $output->writeln($this->dumper(
            $input->getOption('format'),
            stateMachine: $workflow->workflow() instanceof StateMachine,
        )->dump($workflow->definition(), new Marking()));

        return Command::SUCCESS;
    }

    private function dumper(mixed $format, bool $stateMachine): DumperInterface
    {
        $name = \is_string($format) && $format !== '' ? $format : 'mermaid';

        return match ($name) {
            'mermaid' => new MermaidDumper(
                $stateMachine ? MermaidDumper::TRANSITION_TYPE_STATEMACHINE : MermaidDumper::TRANSITION_TYPE_WORKFLOW,
            ),
            'puml' => new PlantUmlDumper(
                $stateMachine ? PlantUmlDumper::STATEMACHINE_TRANSITION : PlantUmlDumper::WORKFLOW_TRANSITION,
            ),
            'dot' => $stateMachine ? new StateMachineGraphvizDumper() : new GraphvizDumper(),
            default => throw new \InvalidArgumentException(\sprintf('Unknown format "%s"', $name)),
        };
    }
}
