<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

enum IdentifierStrategy: string
{
    case AUTO = 'auto';
    case UUID = 'uuid';
    case ULID = 'ulid';

    public static function choices(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public static function fromString(?string $value, self $default = self::UUID): self
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        $normalized = strtolower($value);

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unsupported identifier strategy "%s".', $value));
    }
}
