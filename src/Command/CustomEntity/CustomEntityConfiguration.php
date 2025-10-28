<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

final class CustomEntityConfiguration
{
    /**
     * @param list<PropertyDefinition> $properties
     */
    public function __construct(
        public readonly string $entityName,
        public readonly string $baseNamespace,
        public readonly IdentifierStrategy $identifierStrategy,
        public readonly array $properties,
        public readonly bool $generateController,
        public readonly bool $generateAdmin,
        public readonly ?string $routeBase = null,
        public readonly ?string $routeNamePrefix = null,
        public readonly ?TranslationConfiguration $translation = null,
    ) {
    }

    public function getEntityFqcn(): string
    {
        return 'App\\Entity\\' . $this->entityName;
    }

    public function getRepositoryFqcn(): string
    {
        return 'App\\Repository\\' . $this->entityName . 'Repository';
    }

    public function getControllerFqcn(): string
    {
        if (!$this->generateController) {
            throw new \LogicException('Controller generation disabled.');
        }

        return 'App\\Controller\\Admin\\' . $this->entityName . 'Controller';
    }

    public function getAdminFqcn(): string
    {
        if (!$this->generateAdmin) {
            throw new \LogicException('Admin generation disabled.');
        }

        return 'App\\Admin\\' . $this->entityName . 'Admin';
    }

    public function getTranslationFqcn(): string
    {
        if (null === $this->translation) {
            throw new \LogicException('No translation configuration available.');
        }

        if ($this->translation->isFullyQualified) {
            return ltrim($this->translation->className, '\\');
        }

        return 'App\\Entity\\' . $this->translation->className;
    }

    public function getTranslationShortClass(): string
    {
        if (null === $this->translation) {
            throw new \LogicException('No translation configuration available.');
        }

        return $this->translation->getShortClassName();
    }

    public function hasTranslations(): bool
    {
        return null !== $this->translation;
    }
}
