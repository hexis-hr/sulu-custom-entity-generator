<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command;

use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\CustomEntityConfiguration;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\CustomEntityGenerator;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\IdentifierStrategy;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\NamingHelper;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\PropertyDefinition;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\PropertyType;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\RelationType;
use Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity\TranslationConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: MakeCustomEntityCommand::NAME, aliases: MakeCustomEntityCommand::ALIASES, description: 'Scaffold a custom entity for Sulu Admin CRUD with optional translations.')]
final class MakeCustomEntityCommand extends Command
{
    public const NAME = 'hexis:make:sulu-custom-entity';
    public const ALIASES = ['make:sulu-custom-entity'];

    public function __construct(
        private readonly CustomEntityGenerator $generator,
        private readonly NamingHelper $namingHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Entity class name (e.g. Accommodation)')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Optional namespace for repositories (defaults to App\\Domain\\<Entity>)')
            ->addOption('identifier', null, InputOption::VALUE_REQUIRED, 'Identifier strategy (auto|uuid|ulid)')
            ->addOption('property', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Property definition "name:type[:option=value|flag]"')
            ->addOption('no-controller', null, InputOption::VALUE_NONE, 'Skip controller generation')
            ->addOption('route-base', null, InputOption::VALUE_REQUIRED, 'Controller route base (when controller is generated)')
            ->addOption('route-prefix', null, InputOption::VALUE_REQUIRED, 'Controller route name prefix')
            ->addOption('translation', null, InputOption::VALUE_NONE, 'Generate translation entity')
            ->addOption('translation-class', null, InputOption::VALUE_REQUIRED, 'Translation class name (e.g. AccommodationTranslation)')
            ->addOption('translation-locale-length', null, InputOption::VALUE_REQUIRED, 'Translation locale column length (default 10)')
            ->addOption('translation-property', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Translation property definition "name:type[:option=value|flag]"')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Generate Sulu admin integration (forms, lists, admin class)')
            ->addOption('no-admin', null, InputOption::VALUE_NONE, 'Skip Sulu admin integration (overrides --admin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sulu custom entity generator');

        try {
            [$entityName, $baseNamespace, $identifier, $properties, $generateController, $generateAdmin, $routeBase, $routePrefix, $translationConfiguration] = $this->collectConfiguration($input, $io);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $configuration = new CustomEntityConfiguration(
            $entityName,
            $baseNamespace,
            $identifier,
            $properties,
            $generateController,
            $generateAdmin,
            $routeBase,
            $routePrefix,
            $translationConfiguration,
        );

        $this->generator->generate($configuration, $io);

        $io->newLine();
        $io->success(sprintf('Scaffolding for %s generated.', $configuration->getEntityFqcn()));

        $notes = [
            'Review generated files, update Sulu admin metadata (forms/lists) and serialization/validation rules as needed.',
            'Generated controllers expect arrays of identifiers for to-many relations.',
        ];
        if ($configuration->hasTranslations()) {
            $notes[] = 'Translation-aware controllers accept a ?locale query or body parameter (defaulting to the entity default locale).';
        }
        $io->note($notes);

        return Command::SUCCESS;
    }

    /**
     * @return array{string, string, IdentifierStrategy, list<PropertyDefinition>, bool, bool, ?string, ?string, ?TranslationConfiguration}
     */
    private function collectConfiguration(InputInterface $input, SymfonyStyle $io): array
    {
        $entityName = $this->resolveEntityName($input, $io);
        $baseNamespace = $this->resolveRepositoryNamespace($input, $entityName);
        $identifier = $this->resolveIdentifier($input, $io);
        $properties = $this->resolveProperties($input, $io, $entityName);

        $translationConfiguration = $this->resolveTranslationConfiguration($input, $io, $entityName, $properties);
        $generateAdmin = $this->resolveAdminGeneration($input, $io);

        $generateController = !$input->getOption('no-controller');
        if ($generateAdmin && !$generateController) {
            $io->warning('Controller generation enabled because admin UI requires REST routes.');
            $generateController = true;
        }
        $routeBase = null;
        $routePrefix = null;

        if ($generateController) {
            $defaultRouteBase = sprintf('/admin/api/%s', $this->namingHelper->asKebabCase($this->namingHelper->pluralize($entityName)));
            $defaultRoutePrefix = sprintf('sulu_admin.%s', $this->namingHelper->asKebabCase($this->namingHelper->pluralize($entityName)));

            $routeBase = $input->getOption('route-base');
            if (!$routeBase && $input->isInteractive()) {
                $routeBase = $io->ask('Route base (for controller attribute)', $defaultRouteBase);
            }
            $routeBase = $routeBase ? trim((string) $routeBase) : $defaultRouteBase;

            $routePrefix = $input->getOption('route-prefix');
            if (!$routePrefix && $input->isInteractive()) {
                $routePrefix = $io->ask('Route name prefix', $defaultRoutePrefix);
            }
            $routePrefix = $routePrefix ? trim((string) $routePrefix) : $defaultRoutePrefix;
        }

        return [$entityName, $baseNamespace, $identifier, $properties, $generateController, $generateAdmin, $routeBase, $routePrefix, $translationConfiguration];
    }

    private function resolveEntityName(InputInterface $input, SymfonyStyle $io): string
    {
        $provided = $input->getOption('entity');
        $entityName = is_string($provided) ? trim($provided) : '';

        while ('' === $entityName) {
            if (!$input->isInteractive()) {
                throw new \RuntimeException('Entity name is required.');
            }

            $answer = $io->ask('Entity name (e.g. Accommodation)');
            $entityName = is_string($answer) ? trim($answer) : '';
        }

        $entityName = $this->namingHelper->ensureStudly($entityName);
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $entityName)) {
            throw new \RuntimeException(sprintf('Entity name "%s" is invalid.', $entityName));
        }

        return $entityName;
    }

    private function resolveRepositoryNamespace(InputInterface $input, string $entityName): string
    {
        $namespaceOption = $input->getOption('namespace');
        $namespace = is_string($namespaceOption) ? trim($namespaceOption) : '';
        $defaultNamespace = sprintf('App\\Domain\\%s', $this->namingHelper->ensureStudly($entityName));

        if ('' === $namespace) {
            return $defaultNamespace;
        }

        if (!str_starts_with($namespace, 'App\\')) {
            throw new \RuntimeException('Namespace must start with "App\\".');
        }

        return rtrim($namespace, '\\');
    }

    private function resolveIdentifier(InputInterface $input, SymfonyStyle $io): IdentifierStrategy
    {
        $provided = $input->getOption('identifier');
        if ($provided) {
            return IdentifierStrategy::fromString((string) $provided);
        }

        if (!$input->isInteractive()) {
            return IdentifierStrategy::UUID;
        }

        $choice = $io->choice('Identifier strategy', IdentifierStrategy::choices(), IdentifierStrategy::UUID->value);

        return IdentifierStrategy::fromString($choice);
    }

    /**
     * @return list<PropertyDefinition>
     */
    private function resolveProperties(InputInterface $input, SymfonyStyle $io, string $entityName): array
    {
        $properties = [];
        $seen = [];

        /** @var list<string> $optionProperties */
        $optionProperties = $input->getOption('property') ?? [];
        foreach ($optionProperties as $definition) {
            $property = $this->parsePropertySpecification($definition);
            if (isset($seen[$property->name])) {
                throw new \RuntimeException(sprintf('Duplicate property "%s".', $property->name));
            }
            $properties[] = $property;
            $seen[$property->name] = true;
        }

        if ($properties) {
            return $properties;
        }

        if (!$input->isInteractive()) {
            return $properties;
        }

        $io->section('Add entity properties (leave name empty to finish)');
        while (true) {
            $name = $io->ask('Property name');
            if (null === $name || '' === trim((string) $name)) {
                break;
            }

            $propertyName = $this->normalisePropertyName((string) $name);
            if (isset($seen[$propertyName])) {
                $io->warning(sprintf('Property "%s" already defined, skipping.', $propertyName));
                continue;
            }

            $typeValue = $io->choice('Property type', PropertyType::choices(), PropertyType::STRING->value);
            $type = PropertyType::fromString($typeValue);

            $options = $type->isScalar()
                ? $this->askScalarOptions($io, $type, $propertyName)
                : $this->askRelationOptions($io, $type, $propertyName, $entityName);

            $property = new PropertyDefinition($propertyName, $type, $options);
            $properties[] = $property;
            $seen[$propertyName] = true;

            $io->success(sprintf('Added %s (%s)', $propertyName, $type->value));
        }

        return $properties;
    }

    private function normalisePropertyName(string $input): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]+/', ' ', $input) ?? $input;
        $studly = $this->namingHelper->ensureStudly($clean);

        return lcfirst($studly);
    }

    /**
     * @return array<string, mixed>
     */
    private function askScalarOptions(SymfonyStyle $io, PropertyType $type, string $property): array
    {
        $options = [];
        $options['nullable'] = $io->confirm('Allow null?', PropertyType::TEXT === $type);
        $options['unique'] = $io->confirm('Unique?', false);

        if (PropertyType::STRING === $type) {
            $length = $io->ask('Length (press enter for default 255)');
            if ($length) {
                $options['length'] = max(1, (int) $length);
            }
        }

        if (PropertyType::DECIMAL === $type) {
            $precision = $io->ask('Precision (default 10)', '10');
            $scale = $io->ask('Scale (default 2)', '2');
            $options['precision'] = max(1, (int) $precision);
            $options['scale'] = max(0, (int) $scale);
        }

        if (PropertyType::ENUM === $type) {
            $enumClass = $io->ask('Fully-qualified enum class name');
            if (!$enumClass) {
                throw new \RuntimeException(sprintf('Enum property "%s" requires an enum class.', $property));
            }
            $options['enumClass'] = trim($enumClass);
        }

        if (!\in_array($type, [PropertyType::DATETIME, PropertyType::DATE], true)) {
            $default = $io->ask('Default value (leave empty for none)');
            if (null !== $default && '' !== $default) {
                $options['default'] = $default;
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function askRelationOptions(SymfonyStyle $io, PropertyType $type, string $propertyName, string $entityName): array
    {
        $relationType = $io->choice(
            'Relation type',
            [RelationType::MANY_TO_ONE->value, RelationType::ONE_TO_ONE->value, RelationType::ONE_TO_MANY->value, RelationType::MANY_TO_MANY->value],
            RelationType::MANY_TO_ONE->value
        );
        $relation = RelationType::fromString($relationType);

        $target = $io->ask('Target FQCN');
        if (!$target) {
            throw new \RuntimeException('Relation target class is required.');
        }

        $options = [
            'relationType' => $relation,
            'target' => trim($target),
        ];

        switch ($relation) {
            case RelationType::MANY_TO_ONE:
            case RelationType::ONE_TO_ONE:
                $nullable = $io->confirm('Allow null?', true);
                $onDeleteChoices = ['SET NULL', 'CASCADE', 'RESTRICT', 'NO ACTION'];
                $onDelete = $io->choice('On delete behaviour', $onDeleteChoices, $nullable ? 'SET NULL' : 'RESTRICT');
                $options['nullable'] = $nullable;
                $options['onDelete'] = $onDelete;

                if (RelationType::ONE_TO_ONE === $relation) {
                    $mappedBy = $io->ask('mappedBy (press enter to skip)');
                    $inversedBy = $io->ask('inversedBy (press enter to skip)');
                    if ($mappedBy) {
                        $options['mappedBy'] = trim($mappedBy);
                    }
                    if ($inversedBy) {
                        $options['inversedBy'] = trim($inversedBy);
                    }
                }
                break;

            case RelationType::ONE_TO_MANY:
                $mappedBy = $io->ask('mappedBy (property on target entity)');
                if (!$mappedBy) {
                    throw new \RuntimeException(sprintf('One-to-many relation "%s" requires a mappedBy value.', $propertyName));
                }
                $options['mappedBy'] = trim($mappedBy);
                $cascade = $io->ask('Cascade (comma separated, leave empty for none)');
                if ($cascade) {
                    $options['cascade'] = $cascade;
                }
                $options['orphanRemoval'] = $io->confirm('Enable orphan removal?', false);
                break;

            case RelationType::MANY_TO_MANY:
                $owning = $io->confirm('Is this the owning side?', true);
                $options['owning'] = $owning;
                if ($owning) {
                    $inversedBy = $io->ask('inversedBy (press enter to skip)');
                    if ($inversedBy) {
                        $options['inversedBy'] = trim($inversedBy);
                    }
                } else {
                    $mappedBy = $io->ask('mappedBy (required for inverse side)');
                    if (!$mappedBy) {
                        throw new \RuntimeException(sprintf('Many-to-many relation "%s" requires mappedBy on the inverse side.', $propertyName));
                    }
                    $options['mappedBy'] = trim($mappedBy);
                }
                $cascade = $io->ask('Cascade (comma separated, leave empty for none)');
                if ($cascade) {
                    $options['cascade'] = $cascade;
                }
                break;
        }

        return $options;
    }

    private function resolveTranslationConfiguration(InputInterface $input, SymfonyStyle $io, string $entityName, array $baseProperties): ?TranslationConfiguration
    {
        $forced = (bool) $input->getOption('translation');
        $hasOptionData = $input->getOption('translation-class') || $input->getOption('translation-property');
        $enabled = $forced || $hasOptionData;

        if (!$enabled && $input->isInteractive()) {
            $enabled = $io->confirm('Generate translation entity?', false);
        }

        if (!$enabled) {
            return null;
        }

        $classInput = $input->getOption('translation-class');
        $defaultClass = $entityName . 'Translation';
        if (!$classInput && $input->isInteractive()) {
            $classInput = $io->ask('Translation class name', $defaultClass);
        }

        $classInput = $classInput ? trim((string) $classInput) : $defaultClass;
        $classInput = '' !== $classInput ? $classInput : $defaultClass;

        $fullyQualified = false;
        if (str_contains($classInput, '\\')) {
            $normalized = ltrim($classInput, '\\');
            if (!str_starts_with($normalized, 'App\\Entity\\')) {
                throw new \RuntimeException('Translation classes must live in the App\\Entity namespace.');
            }
            $className = $normalized;
            $fullyQualified = true;
        } else {
            $className = $this->namingHelper->ensureStudly($classInput);
        }

        $localeLengthOption = $input->getOption('translation-locale-length');
        $localeLength = $localeLengthOption ? max(2, (int) $localeLengthOption) : 10;
        if (!$localeLengthOption && $input->isInteractive()) {
            $answer = $io->ask('Locale column length', (string) $localeLength);
            if ($answer) {
                $localeLength = max(2, (int) $answer);
            }
        }

        $properties = [];
        $seen = [];
        foreach ($baseProperties as $property) {
            $seen[$property->name] = true;
        }

        /** @var list<string> $optionProperties */
        $optionProperties = $input->getOption('translation-property') ?? [];
        foreach ($optionProperties as $definition) {
            $property = $this->parsePropertySpecification($definition);
            if ($property->isRelation()) {
                throw new \RuntimeException('Translation properties must be scalar.');
            }
            if (isset($seen[$property->name])) {
                throw new \RuntimeException(sprintf('Property name "%s" already used in entity/translation.', $property->name));
            }

            $properties[] = $property;
            $seen[$property->name] = true;
        }

        if (!$properties && $input->isInteractive()) {
            $io->section('Add translation properties (leave name empty to finish)');
            while (true) {
                $name = $io->ask('Translation property name');
                if (null === $name || '' === trim((string) $name)) {
                    break;
                }

                $propertyName = $this->normalisePropertyName((string) $name);
                if (isset($seen[$propertyName])) {
                    $io->warning(sprintf('Property "%s" already defined, skipping.', $propertyName));
                    continue;
                }

                $typeValue = $io->choice('Property type', array_filter(PropertyType::choices(), static fn (string $choice) => $choice !== PropertyType::RELATION->value), PropertyType::STRING->value);
                $type = PropertyType::fromString($typeValue);
                if (!$type->isScalar()) {
                    $io->warning('Only scalar types are supported for translations.');
                    continue;
                }

                $options = $this->askScalarOptions($io, $type, $propertyName);
                $property = new PropertyDefinition($propertyName, $type, $options);
                $properties[] = $property;
                $seen[$propertyName] = true;

                $io->success(sprintf('Added translation property %s (%s)', $propertyName, $type->value));
            }
        }

        return new TranslationConfiguration($className, $properties, $localeLength, $fullyQualified);
    }

    private function resolveAdminGeneration(InputInterface $input, SymfonyStyle $io): bool
    {
        if ($input->getOption('admin')) {
            return true;
        }

        if ($input->getOption('no-admin')) {
            return false;
        }

        if ($input->isInteractive()) {
            return $io->confirm('Generate admin UI (forms, lists, admin class)?', true);
        }

        return true;
    }

    private function parsePropertySpecification(string $specification): PropertyDefinition
    {
        $parts = array_values(array_filter(array_map('trim', explode(':', $specification))));
        if (count($parts) < 2) {
            throw new \RuntimeException(sprintf('Invalid property specification "%s".', $specification));
        }

        $name = $this->normalisePropertyName((string) array_shift($parts));
        $type = PropertyType::fromString((string) array_shift($parts));
        $options = [];

        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = array_map('trim', explode('=', $part, 2));
                $options[$key] = $this->normaliseOptionValue($value);

                continue;
            }

            $options[$part] = true;
        }

        if ($type->isScalar()) {
            return new PropertyDefinition($name, $type, $options);
        }

        if (!isset($options['relationType']) && !isset($options['target'])) {
            throw new \RuntimeException('Relation properties require at least relationType and target options.');
        }

        $relationType = RelationType::fromString((string) ($options['relationType'] ?? ''));
        if (!isset($options['target']) || '' === trim((string) $options['target'])) {
            throw new \RuntimeException('Relation properties require a "target" option.');
        }

        $options['relationType'] = $relationType;

        return new PropertyDefinition($name, $type, $options);
    }

    private function normaliseOptionValue(string $value): mixed
    {
        $normalized = strtolower($value);
        if (in_array($normalized, ['true', 'false'], true)) {
            return 'true' === $normalized;
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
