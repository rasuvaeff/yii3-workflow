<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Workflow;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Marking store for aggregates whose state is a backed enum in a property that
 * has no public setter.
 *
 * Symfony's `MethodMarkingStore` needs `getX()`/`setX()` and a string place,
 * which forces a domain object to open up its state for the sake of the
 * library. This store reads and writes the property directly — any visibility —
 * and converts between the place name and the enum case.
 *
 * @api
 */
final readonly class EnumMarkingStore implements MarkingStoreInterface
{
    /** @param class-string<\BackedEnum> $enum */
    public function __construct(
        private string $enum,
        private string $property = 'status',
    ) {
        if (!\is_subclass_of($enum, \BackedEnum::class)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a backed enum', $enum));
        }
    }

    #[\Override]
    public function getMarking(object $subject): Marking
    {
        $reflection = $this->property($subject);

        if (!$reflection->isInitialized($subject)) {
            throw new \LogicException(\sprintf(
                'Property "%s" of %s is not initialized',
                $this->property,
                $subject::class,
            ));
        }

        $value = $reflection->getValue($subject);

        // Any other type — including a different enum whose value happens to
        // match a place name — is a wiring bug, not a marking.
        if (!$value instanceof $this->enum) {
            throw new \LogicException(\sprintf(
                'Property "%s" of %s must hold a backed enum %s, %s given',
                $this->property,
                $subject::class,
                $this->enum,
                \get_debug_type($value),
            ));
        }

        return new Marking([(string) $value->value => 1]);
    }

    /** @param array<array-key, mixed> $context */
    #[\Override]
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $places = \array_keys($marking->getPlaces());

        if (\count($places) !== 1) {
            throw new \LogicException(
                'EnumMarkingStore supports single-state markings only; use a state_machine, not a workflow',
            );
        }

        $this->property($subject)->setValue($subject, $this->enum::from((string) $places[0]));
    }

    private function property(object $subject): \ReflectionProperty
    {
        $class = new \ReflectionClass($subject);

        while (!$class->hasProperty($this->property)) {
            $parent = $class->getParentClass();

            if ($parent === false) {
                throw new \InvalidArgumentException(\sprintf(
                    'Subject %s has no property "%s"',
                    $subject::class,
                    $this->property,
                ));
            }

            $class = $parent;
        }

        return $class->getProperty($this->property);
    }
}
