<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;

final class NamingHelper
{
    private Inflector $inflector;

    public function __construct(?Inflector $inflector = null)
    {
        $this->inflector = $inflector ?? InflectorFactory::create()->build();
    }

    public function asSnakeCase(string $value): string
    {
        $result = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower($result ?? $value);
    }

    public function asKebabCase(string $value): string
    {
        return str_replace('_', '-', $this->asSnakeCase($value));
    }

    public function pluralize(string $value): string
    {
        return $this->inflector->pluralize($value);
    }

    public function singularize(string $value): string
    {
        return $this->inflector->singularize($value);
    }

    public function ensureStudly(string $value): string
    {
        $spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $value) ?? $value;
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', $spaced) ?? $spaced;

        return str_replace(' ', '', ucwords(strtolower($normalized)));
    }

    public function asCamelCase(string $value): string
    {
        $studly = $this->ensureStudly($value);

        return lcfirst($studly);
    }
}
