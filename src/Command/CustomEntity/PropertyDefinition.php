<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

final class PropertyDefinition
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly PropertyType $type,
        public readonly array $options = [],
    ) {
    }

    public function isRelation(): bool
    {
        return PropertyType::RELATION === $this->type;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * @return list<string>
     */
    public function getStringListOption(string $name): array
    {
        $value = $this->options[$name] ?? [];

        if (\is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    public function isNullable(): bool
    {
        return (bool) $this->getOption('nullable', false);
    }
}
