<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

final class TranslationConfiguration
{
    /**
     * @param list<PropertyDefinition> $properties
     */
    public function __construct(
        public readonly string $className,
        public readonly array $properties,
        public readonly int $localeLength = 10,
        public readonly bool $isFullyQualified = false,
    ) {
    }

    public function getShortClassName(): string
    {
        if (!$this->isFullyQualified) {
            return $this->className;
        }

        $short = strrchr($this->className, '\\');

        return false === $short ? $this->className : ltrim($short, '\\');
    }

    public function getClassName(): string
    {
        return $this->className;
    }
}
