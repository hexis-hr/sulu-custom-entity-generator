<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

enum RelationType: string
{
    case ONE_TO_ONE = 'one-to-one';
    case MANY_TO_ONE = 'many-to-one';
    case ONE_TO_MANY = 'one-to-many';
    case MANY_TO_MANY = 'many-to-many';

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported relation type "%s".', $value));
    }
}
