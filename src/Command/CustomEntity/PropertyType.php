<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

enum PropertyType: string
{
    case STRING = 'string';
    case INT = 'int';
    case BOOL = 'bool';
    case TEXT = 'text';
    case DATETIME = 'datetime';
    case DATE = 'date';
    case DECIMAL = 'decimal';
    case FLOAT = 'float';
    case UUID = 'uuid';
    case ULID = 'ulid';
    case ENUM = 'enum';
    case RELATION = 'relation';

    public static function choices(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported property type "%s".', $value));
    }

    public function isScalar(): bool
    {
        return $this !== self::RELATION;
    }
}
