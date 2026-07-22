<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

/**
 * Workflows by name — what Symfony's FrameworkBundle provides and the standalone
 * component does not.
 *
 * Workflows are built lazily: a definition with a broken shape fails when that
 * workflow is first requested, not when the container boots.
 *
 * @api
 */
final class WorkflowRegistry
{
    /** @var array<string, IdempotentWorkflow> */
    private array $built = [];

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    public function __construct(
        private readonly array $definitions,
        private readonly WorkflowFactory $factory,
    ) {}

    public function get(string $name): IdempotentWorkflow
    {
        if (isset($this->built[$name])) {
            return $this->built[$name];
        }

        $definition = $this->definitions[$name] ?? throw new \InvalidArgumentException(
            \sprintf('Workflow "%s" is not configured', $name),
        );

        return $this->built[$name] = $this->factory->create($name, $definition);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_keys($this->definitions);
    }
}
