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

/**
 * Prints a configured workflow as a diagram — the Yii3 counterpart of
 * Symfony's `workflow:dump`, so a graph can be pasted into a README or a pull
 * request instead of being read as a list of transitions.
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

        $output->writeln($this->dumper($input->getOption('format'))->dump(
            $this->registry->get($name)->definition(),
            new Marking(),
        ));

        return Command::SUCCESS;
    }

    private function dumper(mixed $format): DumperInterface
    {
        $name = \is_string($format) && $format !== '' ? $format : 'mermaid';

        return match ($name) {
            'mermaid' => new MermaidDumper(MermaidDumper::TRANSITION_TYPE_STATEMACHINE),
            'puml' => new PlantUmlDumper(PlantUmlDumper::STATEMACHINE_TRANSITION),
            'dot' => new StateMachineGraphvizDumper(),
            'workflow-dot' => new GraphvizDumper(),
            default => throw new \InvalidArgumentException(\sprintf('Unknown format "%s"', $name)),
        };
    }
}
