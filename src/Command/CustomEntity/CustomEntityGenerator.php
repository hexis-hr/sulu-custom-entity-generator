<?php

declare(strict_types=1);

namespace Hexis\SuluCustomEntityGeneratorBundle\Command\CustomEntity;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final class CustomEntityGenerator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly NamingHelper $namingHelper,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    private function updateSuluAdminConfiguration(CustomEntityConfiguration $configuration, SymfonyStyle $io): void
    {
        $configPath = sprintf('%s/config/packages/sulu_admin.yaml', $this->projectDir);
        if (!$this->filesystem->exists($configPath)) {
            return;
        }

        $contents = file_get_contents($configPath);
        if (false === $contents) {
            return;
        }

        $resourceKey = $this->resolveResourceKey($configuration);
        if (str_contains($contents, sprintf('\n        %s:\n', $resourceKey))) {
            return;
        }

        $listRoute = sprintf('sulu_admin.%s_list', $resourceKey);
        $detailRoute = sprintf('sulu_admin.%s_get', $resourceKey);
        $securityContext = sprintf("App\\\\Admin\\\\%sAdmin::SECURITY_CONTEXT", $configuration->entityName);

        $block = sprintf(
            "        %s:\n            routes:\n                list: %s\n                detail: %s\n            security_context: '%s'\n\n",
            $resourceKey,
            $listRoute,
            $detailRoute,
            $securityContext,
        );

        $marker = "\n    templates:";
        $position = strpos($contents, $marker);

        if (false === $position) {
            $contents = rtrim($contents) . "\n\n" . $block;
        } else {
            $contents = substr($contents, 0, $position) . "\n" . $block . substr($contents, $position);
        }

        $this->filesystem->dumpFile($configPath, rtrim($contents) . "\n");
        $io->writeln(sprintf('<info>updated</info> %s (resource "%s")', $this->relativePath($configPath), $resourceKey));
    }

    private function updateAdminTranslations(CustomEntityConfiguration $configuration, SymfonyStyle $io): void
    {
        $translationPaths = glob(sprintf('%s/translations/admin.*.json', $this->projectDir)) ?: [];
        if (!$translationPaths) {
            return;
        }

        $resourceKey = $this->resolveResourceKey($configuration);
        $entityLabel = $this->humanize($this->namingHelper->pluralize($configuration->entityName));
        $propertyNames = $this->collectTranslatablePropertyNames($configuration);

        foreach ($translationPaths as $path) {
            $contents = file_get_contents($path);
            if (false === $contents) {
                continue;
            }

            $data = json_decode($contents, true);
            if (!\is_array($data)) {
                continue;
            }

            $resourceData = $data[$resourceKey] ?? [];
            $resourceData['main_navigation'] = $resourceData['main_navigation'] ?? $entityLabel;
            $resourceData['tab_details'] = $resourceData['tab_details'] ?? 'Details';

            $fieldTranslations = \is_array($resourceData['field'] ?? null) ? $resourceData['field'] : [];
            foreach ($propertyNames as $name) {
                $fieldTranslations[$name] = $fieldTranslations[$name] ?? $this->humanize($name);
            }
            if ($fieldTranslations) {
                ksort($fieldTranslations);
                $resourceData['field'] = $fieldTranslations;
            }

            $data[$resourceKey] = $resourceData;

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (false === $encoded) {
                continue;
            }

            $this->filesystem->dumpFile($path, $encoded . "\n");
            $io->writeln(sprintf('<info>updated</info> %s (translations for "%s")', $this->relativePath($path), $resourceKey));
        }
    }

    /**
     * @return list<string>
     */
    private function collectTranslatablePropertyNames(CustomEntityConfiguration $configuration): array
    {
        $names = [];

        foreach ($configuration->properties as $property) {
            if ($property->isRelation()) {
                continue;
            }

            $names[] = $property->name;
        }

        if ($configuration->hasTranslations()) {
            foreach ($configuration->translation?->properties ?? [] as $property) {
                if ($property->isRelation()) {
                    continue;
                }

                $names[] = $property->name;
            }
        }

        return array_values(array_unique($names));
    }

    private function humanize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value;
        $value = str_replace(['_', '-'], ' ', $value);

        return ucwords(strtolower($value));
    }

    public function generate(CustomEntityConfiguration $configuration, SymfonyStyle $io): void
    {
        $entityPath = $this->classToPath($configuration->getEntityFqcn());
        $this->dumpPhpFile($entityPath, $this->renderEntity($configuration), $io);

        if ($configuration->hasTranslations()) {
            $translationPath = $this->classToPath($configuration->getTranslationFqcn());
            $this->dumpPhpFile($translationPath, $this->renderTranslation($configuration), $io);
        }

        $repositoryPath = $this->classToPath($configuration->getRepositoryFqcn());
        $this->dumpPhpFile($repositoryPath, $this->renderRepository($configuration), $io);

        if ($configuration->generateController) {
            $controllerPath = $this->classToPath($configuration->getControllerFqcn());
            $this->dumpPhpFile($controllerPath, $this->renderController($configuration), $io);
        }

        if ($configuration->generateAdmin) {
            $formPath = sprintf('%s/config/forms/%s/details.xml', $this->projectDir, $this->namingHelper->asSnakeCase($configuration->entityName));
            $this->dumpXmlFile($formPath, $this->renderFormXml($configuration), $io);

            $listPath = sprintf('%s/config/lists/%s.xml', $this->projectDir, $this->resolveResourceKey($configuration));
            $this->dumpXmlFile($listPath, $this->renderListXml($configuration), $io);

            $adminPath = $this->classToPath($configuration->getAdminFqcn());
            $this->dumpPhpFile($adminPath, $this->renderAdmin($configuration), $io);

            $this->updateSuluAdminConfiguration($configuration, $io);
            $this->updateAdminTranslations($configuration, $io);
        }
    }

    private function renderEntity(CustomEntityConfiguration $configuration): string
    {
        $namespace = 'App\\Entity';
        $className = $configuration->entityName;
        $tableName = $this->namingHelper->asSnakeCase($this->namingHelper->pluralize($className));
        $resourceKey = $this->namingHelper->asKebabCase($this->namingHelper->pluralize($className));

        $imports = ['Doctrine\\ORM\\Mapping as ORM'];
        $repositoryFqcn = $configuration->getRepositoryFqcn();
        $repositoryShort = $this->shortClass($repositoryFqcn);
        $imports[] = $repositoryFqcn;
        $body = [];
        $methods = [];
        $constructorLines = [];
        $needsTypes = false;
        $needsDateTime = false;
        $needsCollections = false;

        [$idImports, $idLines, $idMethods, $idConstructor] = $this->renderIdentifier($configuration->identifierStrategy);
        $imports = array_merge($imports, $idImports);
        $body = array_merge($body, $idLines, ['']);
        $methods = array_merge($methods, $idMethods, ['']);
        if (null !== $idConstructor) {
            $constructorLines[] = $idConstructor;
        }

        foreach ($configuration->properties as $definition) {
            if ($definition->isRelation()) {
                $rendered = $this->renderRelationProperty($definition);
                $needsCollections = $needsCollections || ($rendered['requires_collection'] ?? false);
            } else {
                $rendered = $this->renderScalarProperty($definition);
            }

            $imports = array_merge($imports, $rendered['imports']);
            $body = array_merge($body, $rendered['lines'], ['']);
            $methods = array_merge($methods, $rendered['methods'], ['']);
            if (isset($rendered['constructor'])) {
                $constructorLines[] = $rendered['constructor'];
            }
            $needsTypes = $needsTypes || ($rendered['uses_types'] ?? false);
            $needsDateTime = $needsDateTime || ($rendered['uses_datetime'] ?? false);
        }

        if ($configuration->hasTranslations()) {
            $translationFqcn = $configuration->getTranslationFqcn();
            $imports[] = 'Doctrine\\Common\\Collections\\ArrayCollection';
            $imports[] = 'Doctrine\\Common\\Collections\\Collection';
            $imports[] = $translationFqcn;
            $needsCollections = true;

            $localeColumnLength = $configuration->translation?->localeLength ?? 10;
            $body[] = sprintf('    #[ORM\\Column(length: %d)]', $localeColumnLength);
            $body[] = "    private string \$defaultLocale = 'en';";
            $body[] = '';
            $body[] = sprintf("    #[ORM\\OneToMany(mappedBy: 'translatable', targetEntity: %s::class, cascade: ['persist', 'remove'], orphanRemoval: true, indexBy: 'locale')]", $configuration->getTranslationShortClass());
            $body[] = '    private Collection $translations;';
            $body[] = '';

            $constructorLines[] = '$this->translations = new ArrayCollection();';
            $methods = array_merge($methods, $this->renderTranslationMethods($configuration), ['']);
        }

        if ($needsCollections) {
            $imports[] = 'Doctrine\\Common\\Collections\\ArrayCollection';
            $imports[] = 'Doctrine\\Common\\Collections\\Collection';
        }

        if ($needsTypes) {
            $imports[] = 'Doctrine\\DBAL\\Types\\Types';
        }

        if ($needsDateTime) {
            $imports[] = '\\DateTimeImmutable';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $code = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', $namespace),
            '',
        ];

        foreach ($imports as $import) {
            $code[] = sprintf('use %s;', $import);
        }

        if ($imports) {
            $code[] = '';
        }

        $code[] = sprintf('#[ORM\\Entity(repositoryClass: %s::class)]', $repositoryShort);
        $code[] = sprintf('#[ORM\\Table(name: \'%s\')]', $tableName);
        $code[] = sprintf('class %s', $className);
        $code[] = '{';
        $code[] = sprintf("    public const RESOURCE_KEY = '%s';", $resourceKey);
        $code[] = '';

        if ($constructorLines) {
            $body[] = '    public function __construct()';
            $body[] = '    {';
            foreach ($constructorLines as $line) {
                $body[] = sprintf('        %s', $line);
            }
            $body[] = '    }';
            $body[] = '';
        }

        $code = array_merge($code, array_filter($body, static fn (string $line): bool => '' !== trim($line) || $line === ''));
        $code = array_merge($code, array_filter($methods, static fn (string $line): bool => '' !== trim($line) || $line === ''));
        $code[] = '}';

        return rtrim(implode("\n", $code)) . "\n";
    }

    private function renderTranslation(CustomEntityConfiguration $configuration): string
    {
        $translation = $configuration->translation;
        if (null === $translation) {
            return '';
        }

        $fqcn = $configuration->getTranslationFqcn();
        $namespace = substr($fqcn, 0, strrpos($fqcn, '\\'));
        $className = $configuration->getTranslationShortClass();
        $entityFqcn = $configuration->getEntityFqcn();
        $entityShort = $this->shortClass($entityFqcn);
        $tableName = $this->namingHelper->asSnakeCase($this->namingHelper->pluralize($configuration->entityName)) . '_translations';
        $uniqueName = sprintf('uniq_%s_locale', $this->namingHelper->asSnakeCase($configuration->entityName));

        $imports = [
            'Doctrine\\ORM\\Mapping as ORM',
            'Symfony\\Component\\Uid\\Uuid',
            $entityFqcn,
        ];
        $body = [];
        $methods = [];
        $constructorLines = [];
        $needsTypes = false;
        $needsDateTime = false;

        $body[] = '    #[ORM\\Id]';
        $body[] = "    #[ORM\\Column(type: 'uuid', unique: true)]";
        $body[] = '    private string $id;';
        $body[] = '';

        $methods[] = '    public function getId(): string';
        $methods[] = '    {';
        $methods[] = '        return $this->id;';
        $methods[] = '    }';
        $methods[] = '';

        $body[] = sprintf('    #[ORM\\ManyToOne(targetEntity: %s::class, inversedBy: \'translations\')]', $entityShort);
        $body[] = "    #[ORM\\JoinColumn(nullable: false, onDelete: 'CASCADE')]";
        $body[] = sprintf('    private %s $translatable;', $entityShort);
        $body[] = '';

        $methods[] = sprintf('    public function getTranslatable(): %s', $entityShort);
        $methods[] = '    {';
        $methods[] = '        return $this->translatable;';
        $methods[] = '    }';
        $methods[] = '';
        $methods[] = sprintf('    public function setTranslatable(%s $translatable): void', $entityShort);
        $methods[] = '    {';
        $methods[] = '        $this->translatable = $translatable;';
        $methods[] = '    }';
        $methods[] = '';

        $body[] = sprintf("    #[ORM\\Column(length: %d)]", $translation->localeLength);
        $body[] = '    private string $locale;';
        $body[] = '';

        $methods[] = '    public function getLocale(): string';
        $methods[] = '    {';
        $methods[] = '        return $this->locale;';
        $methods[] = '    }';
        $methods[] = '';
        $methods[] = '    public function setLocale(string $locale): void';
        $methods[] = '    {';
        $methods[] = '        $this->locale = $locale;';
        $methods[] = '    }';
        $methods[] = '';

        $constructorLines[] = '$this->id = Uuid::v4()->toRfc4122();';
        $constructorLines[] = '$this->translatable = $translatable;';
        $constructorLines[] = '$this->locale = $locale;';

        foreach ($translation->properties as $definition) {
            if ($definition->isRelation()) {
                throw new \InvalidArgumentException('Translation properties must be scalar.');
            }

            $rendered = $this->renderScalarProperty($definition, true);
            $imports = array_merge($imports, $rendered['imports']);
            $body = array_merge($body, $rendered['lines'], ['']);
            $methods = array_merge($methods, $rendered['methods'], ['']);
            $needsTypes = $needsTypes || ($rendered['uses_types'] ?? false);
            $needsDateTime = $needsDateTime || ($rendered['uses_datetime'] ?? false);
        }

        if ($needsTypes) {
            $imports[] = 'Doctrine\\DBAL\\Types\\Types';
        }

        if ($needsDateTime) {
            $imports[] = '\\DateTimeImmutable';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $code = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', $namespace),
            '',
        ];

        foreach ($imports as $import) {
            $code[] = sprintf('use %s;', $import);
        }

        if ($imports) {
            $code[] = '';
        }

        $code[] = sprintf('#[ORM\\Entity]');
        $code[] = sprintf('#[ORM\\Table(name: \'%s\', uniqueConstraints: [new ORM\\UniqueConstraint(name: \'%s\', columns: [\'translatable_id\', \'locale\'])])]', $tableName, $uniqueName);
        $code[] = sprintf('class %s', $className);
        $code[] = '{';
        $code[] = sprintf('    public function __construct(%s $translatable, string $locale)', $entityShort);
        $code[] = '    {';
        foreach ($constructorLines as $line) {
            $code[] = sprintf('        %s', $line);
        }
        $code[] = '    }';
        $code[] = '';
        $code = array_merge($code, array_filter($body, static fn (string $line): bool => '' !== trim($line) || $line === ''));
        $code = array_merge($code, array_filter($methods, static fn (string $line): bool => '' !== trim($line) || $line === ''));
        $code[] = '}';

        return rtrim(implode("\n", $code)) . "\n";
    }

    private function renderRepository(CustomEntityConfiguration $configuration): string
    {
        $fqcn = $configuration->getRepositoryFqcn();
        $namespace = 'App\\Repository';
        $className = $this->shortClass($fqcn);
        $entityShort = $this->shortClass($configuration->getEntityFqcn());
        $entityFqcn = $configuration->getEntityFqcn();

        $code = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', $namespace),
            '',
            'use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;',
            'use Doctrine\\Persistence\\ManagerRegistry;',
            sprintf('use %s;', $entityFqcn),
            '',
            '/**',
            sprintf(' * @extends ServiceEntityRepository<%s>', $entityShort),
            ' */',
            sprintf('final class %s extends ServiceEntityRepository', $className),
            '{',
            '    public function __construct(ManagerRegistry $registry)',
            '    {',
            sprintf('        parent::__construct($registry, %s::class);', $entityShort),
            '    }',
            '',
            sprintf('    public function save(%s $entity, bool $flush = true): void', $entityShort),
            '    {',
            '        $this->_em->persist($entity);',
            '',
            '        if ($flush) {',
            '            $this->_em->flush();',
            '        }',
            '    }',
            '',
            sprintf('    public function remove(%s $entity, bool $flush = true): void', $entityShort),
            '    {',
            '        $this->_em->remove($entity);',
            '',
            '        if ($flush) {',
            '            $this->_em->flush();',
            '        }',
            '    }',
            '}',
            '',
        ];

        return implode("\n", $code);
    }

    private function renderController(CustomEntityConfiguration $configuration): string
    {
        $routeBase = $configuration->routeBase ?? sprintf('/admin/api/%s', $this->namingHelper->asKebabCase($this->namingHelper->pluralize($configuration->entityName)));
        $routePrefix = rtrim($configuration->routeNamePrefix ?? sprintf('sulu_admin.%s', $this->namingHelper->asKebabCase($this->namingHelper->pluralize($configuration->entityName))), '.');
        $resourceKey = $this->resolveResourceKey($configuration);

        $fqcn = $configuration->getControllerFqcn();
        $namespace = substr($fqcn, 0, strrpos($fqcn, '\\'));
        $className = $this->shortClass($fqcn);
        $entityFqcn = $configuration->getEntityFqcn();
        $entityShort = $this->shortClass($entityFqcn);
        $hasTranslations = $configuration->hasTranslations();
        $translationProperties = $hasTranslations ? $configuration->translation?->properties ?? [] : [];

        $imports = [
            'Doctrine\\DBAL\\Exception\\NotNullConstraintViolationException',
            'Doctrine\\ORM\\EntityManagerInterface',
            'FOS\\RestBundle\\View\\ViewHandlerInterface',
            'Sulu\\Component\\Rest\\AbstractRestController',
            'Sulu\\Component\\Rest\\Exception\\RestException',
            'Sulu\\Component\\Rest\\ListBuilder\\Doctrine\\DoctrineListBuilder',
            'Sulu\\Component\\Rest\\ListBuilder\\Doctrine\\DoctrineListBuilderFactoryInterface',
            'Sulu\\Component\\Rest\\ListBuilder\\Doctrine\\FieldDescriptor\\DoctrineFieldDescriptorInterface',
            'Sulu\\Component\\Rest\\ListBuilder\\Metadata\\FieldDescriptorFactoryInterface',
            'Sulu\\Component\\Rest\\ListBuilder\\PaginatedRepresentation',
            'Sulu\\Component\\Rest\\RestHelperInterface',
            'Symfony\\Component\\HttpFoundation\\Request',
            'Symfony\\Component\\HttpFoundation\\Response',
            'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
            'Symfony\\Component\\Routing\\Attribute\\Route',
            'Symfony\\Component\\Security\\Core\\Authentication\\Token\\Storage\\TokenStorageInterface',
            $entityFqcn,
        ];

        $scalarProperties = [];
        $toOneRelations = [];
        $toManyRelations = [];
        $needsDateTime = false;

        foreach ($configuration->properties as $definition) {
            if ($definition->isRelation()) {
                $relationType = $definition->getOption('relationType');
                if (!$relationType instanceof RelationType) {
                    $relationType = RelationType::fromString((string) $relationType);
                }

                $target = (string) $definition->getOption('target');
                $imports[] = $target;

                if (\in_array($relationType, [RelationType::MANY_TO_ONE, RelationType::ONE_TO_ONE], true)) {
                    $toOneRelations[] = [
                        'name' => $definition->name,
                        'targetShort' => $this->shortClass($target),
                        'nullable' => $definition->isNullable(),
                    ];

                    continue;
                }

                $toManyRelations[] = [
                    'name' => $definition->name,
                    'targetShort' => $this->shortClass($target),
                    'adder' => 'add' . $this->namingHelper->ensureStudly($this->namingHelper->singularize($definition->name)),
                    'remover' => 'remove' . $this->namingHelper->ensureStudly($this->namingHelper->singularize($definition->name)),
                    'getter' => 'get' . $this->namingHelper->ensureStudly($definition->name),
                ];

                continue;
            }

            $scalarProperties[] = [
                'definition' => $definition,
                'name' => $definition->name,
                'type' => $definition->type,
                'nullable' => $definition->isNullable(),
            ];

            if (\in_array($definition->type, [PropertyType::DATETIME, PropertyType::DATE], true)) {
                $needsDateTime = true;
            }
        }

        if ($hasTranslations) {
            foreach ($translationProperties as $definition) {
                if (\in_array($definition->type, [PropertyType::DATETIME, PropertyType::DATE], true)) {
                    $needsDateTime = true;
                }
            }
        }

        $requiredFields = [];
        foreach ($scalarProperties as $property) {
            if (!$property['nullable']) {
                $requiredFields[] = $property['name'];
            }
        }

        foreach ($toOneRelations as $relation) {
            if (!$relation['nullable']) {
                $requiredFields[] = $relation['name'];
            }
        }

        if ($hasTranslations) {
            foreach ($translationProperties as $definition) {
                if (!$definition->isNullable()) {
                    $requiredFields[] = $definition->name;
                }
            }
        }

        $requiredFields = array_values(array_unique($requiredFields));

        if ($needsDateTime) {
            $imports[] = '\\DateTimeImmutable';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $applyLines = [];
        foreach ($scalarProperties as $property) {
            $setter = 'set' . $this->namingHelper->ensureStudly($property['name']);
            $applyLines = array_merge($applyLines, $this->renderScalarAssignment($property['definition'], $setter));
        }

        foreach ($toOneRelations as $relation) {
            $setter = 'set' . $this->namingHelper->ensureStudly($relation['name']);
            $applyLines[] = sprintf("        if (\array_key_exists('%s', \$data)) {", $relation['name']);
            $applyLines[] = sprintf("            \$value = \$data['%s'];", $relation['name']);
            $applyLines[] = '            $reference = null;';
            $applyLines[] = '            if (null !== $value && \'\' !== $value) {';
            $applyLines[] = sprintf('                $reference = $this->entityManager->getReference(%s::class, $value);', $relation['targetShort']);
            $applyLines[] = '            }';
            if (!$relation['nullable']) {
                $applyLines[] = '            if (null === $reference) {';
                $applyLines[] = sprintf("                throw new RestException('The field \"%s\" cannot be null.');", $relation['name']);
                $applyLines[] = '            }';
            }
            $applyLines[] = sprintf('            $entity->%s($reference);', $setter);
            $applyLines[] = '        }';
            $applyLines[] = '';
        }

        foreach ($toManyRelations as $relation) {
            $applyLines[] = sprintf("        if (\array_key_exists('%s', \$data)) {", $relation['name']);
            $applyLines[] = sprintf("            \$value = \$data['%s'];", $relation['name']);
            $applyLines[] = '            $items = \is_array($value) ? $value : [];';
            $applyLines[] = sprintf('            foreach ($entity->%s()->toArray() as $existing) {', $relation['getter']);
            $applyLines[] = sprintf('                $entity->%s($existing);', $relation['remover']);
            $applyLines[] = '            }';
            $applyLines[] = '            foreach ($items as $itemId) {';
            $applyLines[] = '                if (null === $itemId || \'\' === $itemId) {';
            $applyLines[] = '                    continue;';
            $applyLines[] = '                }';
            $applyLines[] = sprintf('                $reference = $this->entityManager->getReference(%s::class, $itemId);', $relation['targetShort']);
            $applyLines[] = sprintf('                $entity->%s($reference);', $relation['adder']);
            $applyLines[] = '            }';
            $applyLines[] = '        }';
            $applyLines[] = '';
        }

        if ($hasTranslations) {
            $applyLines[] = '        $effectiveLocale = $locale ?? $entity->getDefaultLocale();';
            foreach ($translationProperties as $definition) {
                $setter = 'set' . $this->namingHelper->ensureStudly($definition->name);
                $applyLines = array_merge($applyLines, $this->renderTranslationAssignment($definition, $setter, 'effectiveLocale'));
            }
        }

        if ($applyLines && '' === end($applyLines)) {
            array_pop($applyLines);
        }

        $serializeLines = [];
        $serializeLines[] = '        $payload = [';
        $serializeLines[] = "            'id' => \$entity->getId(),";

        foreach ($scalarProperties as $property) {
            $getter = 'get' . $this->namingHelper->ensureStudly($property['name']);
            $expression = match ($property['type']) {
                PropertyType::DATE, PropertyType::DATETIME => sprintf('$entity->%s()?->format(\'c\')', $getter),
                default => sprintf('$entity->%s()', $getter),
            };
            $serializeLines[] = sprintf("            '%s' => %s,", $property['name'], $expression);
        }

        foreach ($toOneRelations as $relation) {
            $getter = 'get' . $this->namingHelper->ensureStudly($relation['name']);
            $serializeLines[] = sprintf("            '%s' => \$entity->%s()?->getId(),", $relation['name'], $getter);
        }

        foreach ($toManyRelations as $relation) {
            $serializeLines[] = sprintf("            '%s' => array_map(static fn ($item) => $item->getId(), \$entity->%s()->toArray()),", $relation['name'], $relation['getter']);
        }

        if ($hasTranslations) {
            $serializeLines[] = '        ];';
            $serializeLines[] = '        $effectiveLocale = $locale ?? $entity->getDefaultLocale();';
            $serializeLines[] = '        $payload[\'locale\'] = $effectiveLocale;';
            foreach ($translationProperties as $definition) {
                $getter = 'get' . $this->namingHelper->ensureStudly($definition->name);
                $expression = match ($definition->type) {
                    PropertyType::DATE, PropertyType::DATETIME => sprintf('$entity->%s($effectiveLocale)?->format(\'c\')', $getter),
                    default => sprintf('$entity->%s($effectiveLocale)', $getter),
                };
                $serializeLines[] = sprintf('        $payload[\'%s\'] = %s;', $definition->name, $expression);
            }
            $serializeLines[] = '';
            $serializeLines[] = '        return $payload;';
        } else {
            $serializeLines[] = '        ];';
            $serializeLines[] = '';
            $serializeLines[] = '        return $payload;';
        }

        $code = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', $namespace),
            '',
        ];

        foreach ($imports as $import) {
            $code[] = sprintf('use %s;', $import);
        }

        $code[] = '';
        $code[] = $this->formatRouteAttribute([
            'path' => sprintf("'%s'", $routeBase),
        ]);
        $code[] = sprintf('final class %s extends AbstractRestController', $className);
        $code[] = '{';
        $code[] = '    public function __construct(';
        $code[] = '        ViewHandlerInterface $viewHandler,';
        $code[] = '        TokenStorageInterface $tokenStorage,';
        $code[] = '        private DoctrineListBuilderFactoryInterface $listBuilderFactory,';
        $code[] = '        private RestHelperInterface $restHelper,';
        $code[] = '        private FieldDescriptorFactoryInterface $fieldDescriptorFactory,';
        $code[] = '        private EntityManagerInterface $entityManager,';
        $code[] = '    ) {';
        $code[] = '        parent::__construct($viewHandler, $tokenStorage);';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    ' . $this->formatRouteAttribute([
            'path' => "''",
            'name' => sprintf("'%s_list'", $routePrefix),
            'defaults' => "['_format' => 'json']",
            'methods' => "['GET']",
        ]);
        $code[] = '    public function cgetAction(Request $request): Response';
        $code[] = '    {';
        if ($hasTranslations) {
            $code[] = '        $locale = $this->resolveLocale($request);';
            $code[] = '';
        }
        $code[] = sprintf('        /** @var DoctrineFieldDescriptorInterface[] $fieldDescriptors */');
        $code[] = sprintf('        $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(%s::RESOURCE_KEY);', $entityShort);
        $code[] = '';
        $code[] = sprintf('        /** @var DoctrineListBuilder $listBuilder */');
        $code[] = sprintf('        $listBuilder = $this->listBuilderFactory->create(%s::class);', $entityShort);
        $code[] = '        $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);';
        if ($hasTranslations) {
            $code[] = "        \$listBuilder->setParameter('locale', \$locale);";
        }
        $code[] = '';
        $code[] = '        $listRepresentation = new PaginatedRepresentation(';
        $code[] = '            $listBuilder->execute(),';
        $code[] = sprintf('            %s::RESOURCE_KEY,', $entityShort);
        $code[] = '            (int) $listBuilder->getCurrentPage(),';
        $code[] = '            (int) $listBuilder->getLimit(),';
        $code[] = '            $listBuilder->count()';
        $code[] = '        );';
        $code[] = '';
        $code[] = '        return $this->handleView($this->view($listRepresentation));';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    ' . $this->formatRouteAttribute([
            'path' => "'/{id}'",
            'name' => sprintf("'%s_get'", $routePrefix),
            'defaults' => "['_format' => 'json']",
            'methods' => "['GET']",
        ]);
        $code[] = '    public function getAction(Request $request, string $id): Response';
        $code[] = '    {';
        $code[] = sprintf('        $entity = $this->entityManager->getRepository(%s::class)->find($id);', $entityShort);
        $code[] = '        if (!$entity) {';
        $code[] = '            throw new NotFoundHttpException();';
        $code[] = '        }';
        $code[] = '';
        if ($hasTranslations) {
            $code[] = '        $locale = $this->resolveLocale($request, $entity->getDefaultLocale());';
        } else {
            $code[] = '        $locale = null;';
        }
        $code[] = '        return $this->handleView($this->view($this->serialize($entity, $locale)));';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    ' . $this->formatRouteAttribute([
            'path' => "''",
            'name' => sprintf("'%s_post'", $routePrefix),
            'defaults' => "['_format' => 'json']",
            'methods' => "['POST']",
        ]);
        $code[] = '    public function postAction(Request $request): Response';
        $code[] = '    {';
        if ($hasTranslations) {
            $code[] = '        $locale = $this->resolveLocale($request);';
        } else {
            $code[] = '        $locale = null;';
        }
        $code[] = '        $data = $request->toArray();';
        $code[] = sprintf('        $entity = new %s();', $entityShort);
        if ($hasTranslations) {
            $code[] = '        $entity->setDefaultLocale($locale);';
        }
        $code[] = '        $this->mapDataOntoEntity($entity, $data, $locale, true);';
        $code[] = '        $this->entityManager->persist($entity);';
        $code[] = '        $this->flush();';
        $code[] = '';
        $code[] = '        return $this->handleView($this->view($this->serialize($entity, $locale), 201));';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    ' . $this->formatRouteAttribute([
            'path' => "'/{id}'",
            'name' => sprintf("'%s_put'", $routePrefix),
            'defaults' => "['_format' => 'json']",
            'methods' => "['PUT']",
        ]);
        $code[] = '    public function putAction(Request $request, string $id): Response';
        $code[] = '    {';
        $code[] = sprintf('        $entity = $this->entityManager->getRepository(%s::class)->find($id);', $entityShort);
        $code[] = '        if (!$entity) {';
        $code[] = '            throw new NotFoundHttpException();';
        $code[] = '        }';
        $code[] = '';
        if ($hasTranslations) {
            $code[] = '        $locale = $this->resolveLocale($request, $entity->getDefaultLocale());';
        } else {
            $code[] = '        $locale = null;';
        }
        $code[] = '        $data = $request->toArray();';
        $code[] = '        $this->mapDataOntoEntity($entity, $data, $locale);';
        $code[] = '        $this->flush();';
        $code[] = '';
        $code[] = '        return $this->handleView($this->view($this->serialize($entity, $locale)));';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    ' . $this->formatRouteAttribute([
            'path' => "'/{id}'",
            'name' => sprintf("'%s_delete'", $routePrefix),
            'defaults' => "['_format' => 'json']",
            'methods' => "['DELETE']",
        ]);
        $code[] = '    public function deleteAction(string $id): Response';
        $code[] = '    {';
        $code[] = sprintf('        $entity = $this->entityManager->getRepository(%s::class)->find($id);', $entityShort);
        $code[] = '        if ($entity) {';
        $code[] = '            $this->entityManager->remove($entity);';
            $code[] = '            $this->flush();';
        $code[] = '        }';
        $code[] = '';
        $code[] = '        return $this->handleView($this->view(null, 204));';
        $code[] = '    }';
        $code[] = '';

        $code[] = sprintf('    private function mapDataOntoEntity(%s $entity, array $data, ?string $locale = null, bool $isCreate = false): void', $entityShort);
        $code[] = '    {';
        if ($requiredFields) {
            $requiredList = implode(', ', array_map(static fn (string $field): string => sprintf("'%s'", $field), $requiredFields));
            $code[] = '        if ($isCreate) {';
            $code[] = sprintf('            $this->assertRequiredFields($data, [%s]);', $requiredList);
            $code[] = '        }';
            $code[] = '';
        }
        if ($applyLines) {
            foreach ($applyLines as $line) {
                $code[] = $line;
            }
        } else {
            $code[] = '        // no writable fields defined';
        }
        $code[] = '    }';
        $code[] = '';

        $code[] = sprintf('    private function serialize(%s $entity, ?string $locale = null): array', $entityShort);
        $code[] = '    {';
        foreach ($serializeLines as $line) {
            $code[] = $line;
        }
        $code[] = '    }';
        $code[] = '';

        $code[] = '    private function resolveLocale(Request $request, ?string $fallback = null): string';
        $code[] = '    {';
        $code[] = "        \$locale = \$request->query->get('locale');";
        $code[] = "        if (is_string(\$locale) && '' !== \$locale) {";
        $code[] = '            return $locale;';
        $code[] = '        }';
        $code[] = '';
        $code[] = "        \$locale = \$request->request->get('locale');";
        $code[] = "        if (is_string(\$locale) && '' !== \$locale) {";
        $code[] = '            return $locale;';
        $code[] = '        }';
        $code[] = '';
        $code[] = "        \$attributeLocale = \$request->attributes->get('locale');";
        $code[] = "        if (is_string(\$attributeLocale) && '' !== \$attributeLocale) {";
        $code[] = '            return $attributeLocale;';
        $code[] = '        }';
        $code[] = '';
        $code[] = "        return \$fallback ?? 'en';";
        $code[] = '    }';
        $code[] = '';
        $code[] = '    private function flush(): void';
        $code[] = '    {';
        $code[] = '        try {';
        $code[] = '            $this->entityManager->flush();';
        $code[] = '        } catch (NotNullConstraintViolationException $exception) {';
        $code[] = '            throw new RestException($this->formatNotNullViolationMessage($exception), 0, $exception);';
        $code[] = '        }';
        $code[] = '    }';
        $code[] = '';
        $code[] = '    private function formatNotNullViolationMessage(NotNullConstraintViolationException $exception): string';
        $code[] = '    {';
        $code[] = '        $message = $exception->getMessage();';
        $code[] = '        if (preg_match(\'/column\\s+"([^\"]+)"\\s+of\\s+relation\\s+"([^\"]+)"/i\', $message, $matches)) {';
        $code[] = '            return sprintf(\'The field "%s" is required.\', $matches[1]);';
        $code[] = '        }';
        $code[] = '';
        $code[] = "        return 'A required field is missing.';";
        $code[] = '    }';
        $code[] = '';
        $code[] = '    /**';
        $code[] = '     * @param array<string, mixed> $data';
        $code[] = '     * @param list<string> $fields';
        $code[] = '     */';
        $code[] = '    private function assertRequiredFields(array $data, array $fields): void';
        $code[] = '    {';
        $code[] = '        foreach ($fields as $field) {';
        $code[] = '            if (!array_key_exists($field, $data)) {';
        $code[] = "                throw new RestException(sprintf('The field \"%s\" is required.', \$field));";
        $code[] = '            }';
        $code[] = '';
        $code[] = '            $value = $data[$field];';
        $code[] = '            if (null === $value) {';
        $code[] = "                throw new RestException(sprintf('The field \"%s\" is required.', \$field));";
        $code[] = '            }';
        $code[] = '';
        $code[] = '            if (is_string($value) && \'\' === trim($value)) {';
        $code[] = "                throw new RestException(sprintf('The field \"%s\" is required.', \$field));";
        $code[] = '            }';
        $code[] = '        }';
        $code[] = '    }';
        $code[] = '}';

        return implode("\n", $code) . "\n";
    }

    private function renderAdmin(CustomEntityConfiguration $configuration): string
    {
        $namespace = 'App\\Admin';
        $className = $this->shortClass($configuration->getAdminFqcn());
        $resourceKey = $this->resolveResourceKey($configuration);
        $formKey = sprintf('%s_details', $this->namingHelper->asSnakeCase($configuration->entityName));
        $translationPrefix = $resourceKey;
        $singularSnake = $this->namingHelper->asSnakeCase($configuration->entityName);
        $viewBase = sprintf('app_%s', $singularSnake);
        $listView = sprintf('%s.%s_list', $viewBase, $resourceKey);
        $addFormView = sprintf('%s.%s_add_form', $viewBase, $resourceKey);
        $editFormView = sprintf('%s.%s_edit_form', $viewBase, $resourceKey);
        $routeSegment = sprintf('/%s', $resourceKey);
        $securityContext = sprintf('app.%s', $resourceKey);

        $imports = [
            'Sulu\\Bundle\\AdminBundle\\Admin\\Admin',
            'Sulu\\Bundle\\AdminBundle\\Admin\\Navigation\\NavigationItem',
            'Sulu\\Bundle\\AdminBundle\\Admin\\Navigation\\NavigationItemCollection',
            'Sulu\\Bundle\\AdminBundle\\Admin\\View\\ToolbarAction',
            'Sulu\\Bundle\\AdminBundle\\Admin\\View\\ViewBuilderFactoryInterface',
            'Sulu\\Bundle\\AdminBundle\\Admin\\View\\ViewCollection',
            'Sulu\\Component\\Localization\\Manager\\LocalizationManagerInterface',
            'Sulu\\Component\\Security\\Authorization\\PermissionTypes',
            'Sulu\\Component\\Security\\Authorization\\SecurityCheckerInterface',
        ];

        $code = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            sprintf('namespace %s;', $namespace),
            '',
        ];

        foreach ($imports as $import) {
            $code[] = sprintf('use %s;', $import);
        }

        $code[] = '';
        $code[] = sprintf('final class %s extends Admin', $className);
        $code[] = '{';
        $code[] = sprintf("    public const SECURITY_CONTEXT = '%s';", $securityContext);
        $code[] = sprintf("    public const LIST_VIEW = '%s';", $listView);
        $code[] = sprintf("    public const ADD_FORM_VIEW = '%s';", $addFormView);
        $code[] = sprintf("    public const EDIT_FORM_VIEW = '%s';", $editFormView);
        $code[] = '';

        $code[] = '    public function __construct(';
        $code[] = '        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,';
        $code[] = '        private readonly SecurityCheckerInterface $securityChecker,';
        $code[] = '        private readonly LocalizationManagerInterface $localizationManager,';
        $code[] = '    ) {';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    public function configureNavigationItems(NavigationItemCollection $navigationItemCollection): void';
        $code[] = '    {';
        $code[] = '        if (!$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::VIEW)) {';
        $code[] = '            return;';
        $code[] = '        }';
        $code[] = '';
        $code[] = sprintf("        \$navigationItem = new NavigationItem('%s.main_navigation');", $translationPrefix);
        $code[] = '        $navigationItem->setPosition(90);';
        $code[] = "        \$navigationItem->setIcon('su-pen');";
        $code[] = '        $navigationItem->setView(self::LIST_VIEW);';
        $code[] = '';
        $code[] = '        $navigationItemCollection->add($navigationItem);';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    public function configureViews(ViewCollection $viewCollection): void';
        $code[] = '    {';
        $code[] = '        if (!$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::VIEW)) {';
        $code[] = '            return;';
        $code[] = '        }';
        $code[] = '';
        $code[] = '        $locales = $this->localizationManager->getLocales();';
        $code[] = "        if (!\$locales) {";
        $code[] = "            \$locales = ['en'];";
        $code[] = '        }';
        $code[] = '';
        $code[] = '        $toolbarActions = [];';
        $code[] = '        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::ADD)) {';
        $code[] = "            \$toolbarActions[] = new ToolbarAction('sulu_admin.add');";
        $code[] = '        }';
        $code[] = '        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::DELETE)) {';
        $code[] = "            \$toolbarActions[] = new ToolbarAction('sulu_admin.delete');";
        $code[] = '        }';
        $code[] = '';
        $code[] = '        $viewCollection->add(';
        $code[] = '            $this->viewBuilderFactory';
        $code[] = sprintf("                ->createListViewBuilder(self::LIST_VIEW, '%s/:locale')", $routeSegment);
        $code[] = sprintf("                ->setResourceKey('%s')", $resourceKey);
        $code[] = sprintf("                ->setListKey('%s')", $resourceKey);
        $code[] = "                ->addListAdapters(['table'])";
        $code[] = sprintf('                ->setAddView(self::ADD_FORM_VIEW)');
        $code[] = sprintf('                ->setEditView(self::EDIT_FORM_VIEW)');
        $code[] = sprintf("                ->setTitle('%s.main_navigation')", $translationPrefix);
        $code[] = '                ->addToolbarActions($toolbarActions)';
        $code[] = '                ->addLocales($locales)';
        $code[] = "                ->addRouterAttributesToListRequest(['locale'])";
        $code[] = "                ->addRouterAttributesToListMetadata(['locale'])";
        $code[] = '        );';
        $code[] = '';
        $code[] = '        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::ADD)) {';
        $code[] = '            $viewCollection->add(';
        $code[] = '                $this->viewBuilderFactory';
        $code[] = sprintf("                    ->createResourceTabViewBuilder(self::ADD_FORM_VIEW, '%s/:locale/add')", $routeSegment);
        $code[] = sprintf("                    ->setResourceKey('%s')", $resourceKey);
        $code[] = '                    ->setBackView(self::LIST_VIEW)';
        $code[] = '                    ->addLocales($locales)';
        $code[] = '            );';
        $code[] = '';
        $code[] = '            $viewCollection->add(';
        $code[] = '                $this->viewBuilderFactory';
        $code[] = sprintf("                    ->createFormViewBuilder(self::ADD_FORM_VIEW . '.details', '/details')");
        $code[] = sprintf("                    ->setResourceKey('%s')", $resourceKey);
        $code[] = sprintf("                    ->setFormKey('%s')", $formKey);
        $code[] = sprintf("                    ->setTabTitle('%s.tab_details')", $translationPrefix);
        $code[] = "                    ->addToolbarActions([new ToolbarAction('sulu_admin.save')])";
        $code[] = "                    ->addRouterAttributesToFormRequest(['locale'])";
        $code[] = "                    ->addRouterAttributesToFormMetadata(['locale'])";
        $code[] = '                    ->setParent(self::ADD_FORM_VIEW)';
        $code[] = '            );';
        $code[] = '        }';
        $code[] = '';
        $code[] = '        if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::EDIT)) {';
        $code[] = '            $viewCollection->add(';
        $code[] = '                $this->viewBuilderFactory';
        $code[] = sprintf("                    ->createResourceTabViewBuilder(self::EDIT_FORM_VIEW, '%s/:locale/:id')", $routeSegment);
        $code[] = sprintf("                    ->setResourceKey('%s')", $resourceKey);
        $code[] = '                    ->setBackView(self::LIST_VIEW)';
        $code[] = '                    ->addLocales($locales)';
        $code[] = '            );';
        $code[] = '';
        $code[] = '            $toolbar = [new ToolbarAction(' . "'sulu_admin.save'" . ')];';
        $code[] = '            if ($this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::DELETE)) {';
        $code[] = "                \$toolbar[] = new ToolbarAction('sulu_admin.delete');";
        $code[] = '            }';
        $code[] = '';
        $code[] = '            $viewCollection->add(';
        $code[] = '                $this->viewBuilderFactory';
        $code[] = sprintf("                    ->createFormViewBuilder(self::EDIT_FORM_VIEW . '.details', '/details')");
        $code[] = sprintf("                    ->setResourceKey('%s')", $resourceKey);
        $code[] = sprintf("                    ->setFormKey('%s')", $formKey);
        $code[] = sprintf("                    ->setTabTitle('%s.tab_details')", $translationPrefix);
        $code[] = '                    ->addToolbarActions($toolbar)';
        $code[] = "                    ->addRouterAttributesToFormRequest(['locale'])";
        $code[] = "                    ->addRouterAttributesToFormMetadata(['locale'])";
        $code[] = "                    ->addRouterAttributesToEditView(['locale'])";
        $code[] = '                    ->setParent(self::EDIT_FORM_VIEW)';
        $code[] = '            );';
        $code[] = '        }';
        $code[] = '    }';
        $code[] = '';

        $code[] = '    public function getSecurityContexts(): array';
        $code[] = '    {';
        $code[] = '        return [';
        $code[] = "            'Sulu' => [";
        $code[] = "                'Content' => [";
        $code[] = sprintf('                    self::SECURITY_CONTEXT => [');
        $code[] = '                        PermissionTypes::VIEW,';
        $code[] = '                        PermissionTypes::ADD,';
        $code[] = '                        PermissionTypes::EDIT,';
        $code[] = '                        PermissionTypes::DELETE,';
        $code[] = '                    ],';
        $code[] = '                ],';
        $code[] = '            ],';
        $code[] = '        ];';
        $code[] = '    }';
        $code[] = '}';

        return implode("\n", $code) . "\n";
    }

    /**
     * @param array<string, string> $arguments
     */
    private function formatRouteAttribute(array $arguments): string
    {
        $preferredOrder = ['path', 'name', 'defaults', 'methods'];
        $ordered = [];

        foreach ($preferredOrder as $key) {
            if (array_key_exists($key, $arguments)) {
                $ordered[$key] = $arguments[$key];
                unset($arguments[$key]);
            }
        }

        ksort($arguments);

        foreach ($arguments as $key => $value) {
            $ordered[$key] = $value;
        }

        $parts = [];
        foreach ($ordered as $key => $value) {
            $parts[] = sprintf('%s: %s', $key, $value);
        }

        return sprintf('#[Route(%s)]', implode(', ', $parts));
    }

    private function renderFormXml(CustomEntityConfiguration $configuration): string
    {
        $formKey = sprintf('%s_details', $this->namingHelper->asSnakeCase($configuration->entityName));
        $resourceKey = $this->resolveResourceKey($configuration);

        $translationNames = [];
        if ($configuration->hasTranslations()) {
            foreach ($configuration->translation?->properties ?? [] as $translationProperty) {
                if ($translationProperty->isRelation()) {
                    continue;
                }

                $translationNames[$translationProperty->name] = true;
            }
        }

        $propertyBlocks = [];
        $seen = [];
        foreach ($configuration->properties as $property) {
            if ($property->isRelation()) {
                continue;
            }

            if (isset($translationNames[$property->name])) {
                continue;
            }

            if (isset($seen[$property->name])) {
                continue;
            }

            $propertyBlocks[] = $this->renderFormProperty($property, $resourceKey);
            $seen[$property->name] = true;
        }

        if ($configuration->hasTranslations()) {
            foreach ($configuration->translation?->properties ?? [] as $property) {
                if ($property->isRelation()) {
                    continue;
                }

                if (isset($seen[$property->name])) {
                    continue;
                }

                $propertyBlocks[] = $this->renderFormProperty($property, $resourceKey);
                $seen[$property->name] = true;
            }
        }

        $propertiesSection = $propertyBlocks ? implode("\n\n", $propertyBlocks) . "\n" : '';

        $lines = [
            '<?xml version="1.0" ?>',
            '<form xmlns="http://schemas.sulu.io/template/template"',
            '      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            '      xsi:schemaLocation="http://schemas.sulu.io/template/template http://schemas.sulu.io/template/form-1.0.xsd">',
            sprintf('    <key>%s</key>', $formKey),
            '',
            '    <properties>',
        ];

        if ('' !== $propertiesSection) {
            $lines[] = $propertiesSection;
        }

        $lines[] = '    </properties>';
        $lines[] = '</form>';

        return implode("\n", $lines) . "\n";
    }

    private function renderFormProperty(PropertyDefinition $definition, string $resourceKey): string
    {
        $mandatory = $definition->isNullable() ? 'false' : 'true';
        $type = $this->mapFormType($definition);
        $title = sprintf('%s.field.%s', $resourceKey, $definition->name);

        return sprintf(
            "        <property name=\"%s\" type=\"%s\" mandatory=\"%s\">\n            <meta>\n                <title>%s</title>\n            </meta>\n        </property>",
            $definition->name,
            $type,
            $mandatory,
            $title,
        );
    }

    private function renderListXml(CustomEntityConfiguration $configuration): string
    {
        $resourceKey = $this->resolveResourceKey($configuration);
        $entityFqcn = $configuration->getEntityFqcn();
        $translationAlias = sprintf('%sTranslation', $this->namingHelper->asCamelCase($configuration->entityName));

        $propertyBlocks = [];

        $propertyBlocks[] = $this->renderListProperty(
            'id',
            'sulu_admin.uuid',
            'id',
            $entityFqcn,
            visibility: null,
            searchable: false,
        );

        $translationProperties = [];
        if ($configuration->hasTranslations()) {
            foreach ($configuration->translation?->properties ?? [] as $property) {
                if ($property->isRelation()) {
                    continue;
                }

                if (isset($translationProperties[$property->name])) {
                    continue;
                }

                $translationProperties[$property->name] = $property;
            }
        }

        $scalarProperties = [];
        foreach ($configuration->properties as $property) {
            if ($property->isRelation()) {
                continue;
            }

            if (isset($translationProperties[$property->name])) {
                continue;
            }

            if (isset($scalarProperties[$property->name])) {
                continue;
            }

            $scalarProperties[$property->name] = $property;
        }

        $hasPrimaryColumn = false;

        foreach ($scalarProperties as $property) {
            $visibility = $hasPrimaryColumn ? 'yes' : 'always';
            $hasPrimaryColumn = true;
            $searchable = $this->isSearchable($property);
            $propertyBlocks[] = $this->renderListProperty(
                $property->name,
                sprintf('%s.%s', $resourceKey, $property->name),
                $property->name,
                $entityFqcn,
                $visibility,
                $searchable,
            );
        }

        foreach ($translationProperties as $property) {
            $visibility = $hasPrimaryColumn ? 'yes' : 'always';
            $hasPrimaryColumn = true;
            $propertyBlocks[] = $this->renderListProperty(
                $property->name,
                sprintf('%s.%s', $resourceKey, $property->name),
                $property->name,
                $translationAlias,
                $visibility,
                $this->isSearchable($property),
                $translationAlias,
            );
        }

        $propertiesSection = implode("\n\n", $propertyBlocks);

        $lines = [
            '<?xml version="1.0" ?>',
            '<list xmlns="http://schemas.sulu.io/list-builder/list"',
            '      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            '      xsi:schemaLocation="http://schemas.sulu.io/list-builder/list http://schemas.sulu.io/list-builder/list-1.0.xsd">',
            sprintf('    <key>%s</key>', $resourceKey),
        ];

        if ($translationProperties) {
            $lines[] = '';
            $lines[] = sprintf('    <joins name="%s">', $translationAlias);
            $lines[] = '        <join>';
            $lines[] = sprintf('            <entity-name>%s</entity-name>', $translationAlias);
            $lines[] = sprintf('            <field-name>%s.translations</field-name>', $entityFqcn);
            $lines[] = '            <method>LEFT</method>';
            $lines[] = sprintf('            <condition>%s.locale = :locale</condition>', $translationAlias);
            $lines[] = '        </join>';
            $lines[] = '    </joins>';
        }

        $lines[] = '';
        $lines[] = '    <properties>';
        $lines[] = $propertiesSection;
        $lines[] = '    </properties>';
        $lines[] = '</list>';

        return implode("\n", $lines) . "\n";
    }

    private function renderListProperty(
        string $name,
        string $translation,
        string $fieldName,
        string $entityName,
        ?string $visibility,
        bool $searchable,
        ?string $joinRef = null
    ): string {
        $attributes = ['name="' . $name . '"', sprintf('translation="%s"', $translation)];
        if (null !== $visibility) {
            $attributes[] = sprintf('visibility="%s"', $visibility);
        }
        if ($searchable) {
            $attributes[] = 'searchability="yes"';
        }

        $propertyLines = [
            sprintf('        <property %s>', implode(' ', $attributes)),
            sprintf('            <field-name>%s</field-name>', $fieldName),
            sprintf('            <entity-name>%s</entity-name>', $entityName),
        ];

        if (null !== $joinRef) {
            $propertyLines[] = sprintf('            <joins ref="%s"/>', $joinRef);
        }

        $propertyLines[] = '        </property>';

        return implode("\n", $propertyLines);
    }

    private function mapFormType(PropertyDefinition $definition): string
    {
        return match ($definition->type) {
            PropertyType::TEXT => 'text_area',
            PropertyType::BOOL => 'checkbox',
            PropertyType::INT, PropertyType::FLOAT, PropertyType::DECIMAL => 'number',
            PropertyType::DATETIME => 'datetime',
            PropertyType::DATE => 'date',
            default => 'text_line',
        };
    }

    private function isSearchable(PropertyDefinition $definition): bool
    {
        return match ($definition->type) {
            PropertyType::STRING, PropertyType::TEXT, PropertyType::UUID, PropertyType::ULID, PropertyType::ENUM => true,
            PropertyType::INT, PropertyType::FLOAT, PropertyType::DECIMAL => true,
            default => false,
        };
    }

    private function resolveResourceKey(CustomEntityConfiguration $configuration): string
    {
        return $this->namingHelper->asKebabCase($this->namingHelper->pluralize($configuration->entityName));
    }

    /**
     * @return array{list<string>, list<string>, list<string>, null|string}
     */
    private function renderIdentifier(IdentifierStrategy $strategy): array
    {
        $imports = ['Doctrine\\ORM\\Mapping as ORM'];
        $lines = [];
        $methods = [];
        $constructor = null;

        $lines[] = '    #[ORM\\Id]';

        switch ($strategy) {
            case IdentifierStrategy::AUTO:
                $lines[] = '    #[ORM\\GeneratedValue]';
                $lines[] = "    #[ORM\\Column(type: 'integer')]";
                $lines[] = '    private ?int $id = null;';
                $lines[] = '';
                $methods[] = '    public function getId(): ?int';
                $methods[] = '    {';
                $methods[] = '        return $this->id;';
                $methods[] = '    }';
                break;
            case IdentifierStrategy::UUID:
                $imports[] = 'Symfony\\Component\\Uid\\Uuid';
                $lines[] = "    #[ORM\\Column(type: 'uuid', unique: true)]";
                $lines[] = '    private string $id;';
                $lines[] = '';
                $constructor = '$this->id = Uuid::v4()->toRfc4122();';
                $methods[] = '    public function getId(): string';
                $methods[] = '    {';
                $methods[] = '        return $this->id;';
                $methods[] = '    }';
                break;
            case IdentifierStrategy::ULID:
                $imports[] = 'Symfony\\Component\\Uid\\Ulid';
                $lines[] = "    #[ORM\\Column(type: 'ulid', unique: true)]";
                $lines[] = '    private string $id;';
                $lines[] = '';
                $constructor = '$this->id = (string) (new Ulid());';
                $methods[] = '    public function getId(): string';
                $methods[] = '    {';
                $methods[] = '        return $this->id;';
                $methods[] = '    }';
                break;
        }

        return [$imports, $lines, $methods, $constructor];
    }

    private function renderScalarProperty(PropertyDefinition $definition, bool $forTranslation = false): array
    {
        $imports = [];
        $lines = [];
        $methods = [];
        $usesTypes = false;
        $usesDateTime = false;

        $nullable = $definition->isNullable();
        $default = $definition->getOption('default');
        $unique = (bool) $definition->getOption('unique', false);
        $length = $definition->getOption('length');
        $precision = $definition->getOption('precision');
        $scale = $definition->getOption('scale');
        $enumClass = $definition->getOption('enumClass');

        $propertyBaseType = $this->resolvePhpBaseType($definition);
        $phpType = $nullable ? '?' . $propertyBaseType : $propertyBaseType;
        $setterType = $phpType;
        $defaultAssignment = '';
        $columnArgs = [];

        switch ($definition->type) {
            case PropertyType::STRING:
                if (!$nullable) {
                    $defaultAssignment = " = ''";
                }
                if (null !== $length && '' !== (string) $length) {
                    $columnArgs[] = sprintf('length: %d', (int) $length);
                }
                break;
            case PropertyType::TEXT:
                $columnArgs[] = "type: 'text'";
                if (!$nullable) {
                    $defaultAssignment = " = ''";
                }
                break;
            case PropertyType::INT:
                $columnArgs[] = "type: 'integer'";
                break;
            case PropertyType::BOOL:
                $columnArgs[] = "type: 'boolean'";
                if (!$nullable) {
                    $defaultAssignment = ' = false';
                }
                break;
            case PropertyType::DATETIME:
                $columnArgs[] = 'type: Types::DATETIME_IMMUTABLE';
                $usesTypes = true;
                $usesDateTime = true;
                break;
            case PropertyType::DATE:
                $columnArgs[] = 'type: Types::DATE_IMMUTABLE';
                $usesTypes = true;
                $usesDateTime = true;
                break;
            case PropertyType::DECIMAL:
                $columnArgs[] = "type: 'decimal'";
                $columnArgs[] = sprintf('precision: %d', (int) ($precision ?? 10));
                $columnArgs[] = sprintf('scale: %d', (int) ($scale ?? 2));
                break;
            case PropertyType::FLOAT:
                $columnArgs[] = "type: 'float'";
                break;
            case PropertyType::UUID:
                $columnArgs[] = "type: 'uuid'";
                break;
            case PropertyType::ULID:
                $columnArgs[] = "type: 'ulid'";
                break;
            case PropertyType::ENUM:
                if (!\is_string($enumClass) || '' === $enumClass) {
                    throw new \InvalidArgumentException(sprintf('Enum property "%s" requires an "enumClass" option.', $definition->name));
                }
                $imports[] = $enumClass;
                $columnArgs[] = sprintf('enumType: %s::class', $this->shortClass($enumClass));
                break;
        }

        if ($nullable) {
            $columnArgs[] = 'nullable: true';
        }

        if ($unique) {
            $columnArgs[] = 'unique: true';
        }

        if (null !== $default && '' !== (string) $default) {
            $columnArgs[] = sprintf('options: ["default" => %s]', $this->formatDefaultValue($default));
            if (!$nullable) {
                $defaultAssignment = sprintf(' = %s', $this->formatDefaultValue($default));
            }
        }

        if ($nullable && '' === $defaultAssignment) {
            $defaultAssignment = ' = null';
        }

        if ($forTranslation && !$nullable && 'string' === $propertyBaseType) {
            $setterType = '?' . $propertyBaseType;
        }

        $lines[] = sprintf('    #[ORM\\Column(%s)]', $columnArgs ? implode(', ', $columnArgs) : '');
        $lines[] = sprintf('    private %s $%s%s;', $phpType, $definition->name, $defaultAssignment);

        $methodSuffix = $this->namingHelper->ensureStudly($definition->name);
        $methods[] = sprintf('    public function get%s(): %s', $methodSuffix, $phpType);
        $methods[] = '    {';
        $methods[] = sprintf('        return $this->%s;', $definition->name);
        $methods[] = '    }';
        $methods[] = '';
        $methods[] = sprintf('    public function set%s(%s $%s): self', $methodSuffix, $setterType, $definition->name);
        $methods[] = '    {';
        if ($forTranslation && !$nullable && 'string' === $propertyBaseType) {
            $methods[] = sprintf("        \$this->%s = \$%s ?? '';", $definition->name, $definition->name);
        } else {
            $methods[] = sprintf('        $this->%s = $%s;', $definition->name, $definition->name);
        }
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';

        return [
            'imports' => $imports,
            'lines' => $lines,
            'methods' => $methods,
            'uses_types' => $usesTypes,
            'uses_datetime' => $usesDateTime,
        ];
    }

    private function renderRelationProperty(PropertyDefinition $definition): array
    {
        $relationType = $definition->getOption('relationType');
        if (!$relationType instanceof RelationType) {
            $relationType = RelationType::fromString((string) $relationType);
        }

        return match ($relationType) {
            RelationType::MANY_TO_ONE => $this->renderManyToOneRelation($definition),
            RelationType::ONE_TO_ONE => $this->renderOneToOneRelation($definition),
            RelationType::ONE_TO_MANY => $this->renderOneToManyRelation($definition),
            RelationType::MANY_TO_MANY => $this->renderManyToManyRelation($definition),
        };
    }

    private function renderManyToOneRelation(PropertyDefinition $definition): array
    {
        $imports = ['Doctrine\\ORM\\Mapping as ORM'];
        $lines = [];
        $methods = [];
        $target = (string) $definition->getOption('target');
        if ('' === $target) {
            throw new \InvalidArgumentException('Relation target class must be provided.');
        }
        $imports[] = $target;
        $nullable = $definition->isNullable();
        $onDelete = (string) $definition->getOption('onDelete', $nullable ? 'SET NULL' : 'RESTRICT');

        $targetShort = $this->shortClass($target);
        $lines[] = sprintf('    #[ORM\\ManyToOne(targetEntity: %s::class)]', $targetShort);
        $joinArgs = [];
        if (!$nullable) {
            $joinArgs[] = 'nullable: false';
        }
        if ('' !== $onDelete) {
            $joinArgs[] = sprintf("onDelete: '%s'", $onDelete);
        }
        $lines[] = sprintf('    #[ORM\\JoinColumn(%s)]', $joinArgs ? implode(', ', $joinArgs) : '');
        $phpType = $nullable ? sprintf('?%s', $targetShort) : $targetShort;
        $defaultAssignment = $nullable ? ' = null' : '';
        $lines[] = sprintf('    private %s $%s%s;', $phpType, $definition->name, $defaultAssignment);

        $methodSuffix = $this->namingHelper->ensureStudly($definition->name);
        $methods[] = sprintf('    public function get%s(): %s', $methodSuffix, $phpType);
        $methods[] = '    {';
        $methods[] = sprintf('        return $this->%s;', $definition->name);
        $methods[] = '    }';
        $methods[] = '';
        $methods[] = sprintf('    public function set%s(%s $%s): self', $methodSuffix, $phpType, $definition->name);
        $methods[] = '    {';
        $methods[] = sprintf('        $this->%s = $%s;', $definition->name, $definition->name);
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';

        return [
            'imports' => $imports,
            'lines' => $lines,
            'methods' => $methods,
        ];
    }

    private function renderOneToOneRelation(PropertyDefinition $definition): array
    {
        $imports = ['Doctrine\\ORM\\Mapping as ORM'];
        $lines = [];
        $methods = [];
        $target = (string) $definition->getOption('target');
        if ('' === $target) {
            throw new \InvalidArgumentException('Relation target class must be provided.');
        }
        $imports[] = $target;
        $nullable = $definition->isNullable();
        $onDelete = (string) $definition->getOption('onDelete', $nullable ? 'SET NULL' : 'RESTRICT');
        $mappedBy = $definition->getOption('mappedBy');
        $inversedBy = $definition->getOption('inversedBy');

        $targetShort = $this->shortClass($target);
        $args = [sprintf('targetEntity: %s::class', $targetShort)];
        if ($mappedBy) {
            $args[] = sprintf("mappedBy: '%s'", $mappedBy);
        }
        if ($inversedBy) {
            $args[] = sprintf("inversedBy: '%s'", $inversedBy);
        }

        $lines[] = sprintf('    #[ORM\\OneToOne(%s)]', implode(', ', $args));

        $joinArgs = [];
        if (!$nullable) {
            $joinArgs[] = 'nullable: false';
        }
        $joinArgs[] = 'unique: true';
        if ('' !== $onDelete) {
            $joinArgs[] = sprintf("onDelete: '%s'", $onDelete);
        }
        $lines[] = sprintf('    #[ORM\\JoinColumn(%s)]', implode(', ', $joinArgs));

        $phpType = $nullable ? sprintf('?%s', $targetShort) : $targetShort;
        $defaultAssignment = $nullable ? ' = null' : '';
        $lines[] = sprintf('    private %s $%s%s;', $phpType, $definition->name, $defaultAssignment);

        $methodSuffix = $this->namingHelper->ensureStudly($definition->name);
        $methods[] = sprintf('    public function get%s(): %s', $methodSuffix, $phpType);
        $methods[] = '    {';
        $methods[] = sprintf('        return $this->%s;', $definition->name);
        $methods[] = '    }';
        $methods[] = '';
        $methods[] = sprintf('    public function set%s(%s $%s): self', $methodSuffix, $phpType, $definition->name);
        $methods[] = '    {';
        $methods[] = sprintf('        $this->%s = $%s;', $definition->name, $definition->name);
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';

        return [
            'imports' => $imports,
            'lines' => $lines,
            'methods' => $methods,
        ];
    }

    private function renderOneToManyRelation(PropertyDefinition $definition): array
    {
        $imports = ['Doctrine\\ORM\\Mapping as ORM'];
        $imports[] = 'Doctrine\\Common\\Collections\\ArrayCollection';
        $imports[] = 'Doctrine\\Common\\Collections\\Collection';
        $lines = [];
        $methods = [];
        $target = (string) $definition->getOption('target');
        if ('' === $target) {
            throw new \InvalidArgumentException('Relation target class must be provided.');
        }
        $imports[] = $target;
        $mappedBy = (string) $definition->getOption('mappedBy', '');
        if ('' === $mappedBy) {
            throw new \InvalidArgumentException(sprintf('One-to-many relation "%s" requires a mappedBy option.', $definition->name));
        }
        $cascade = $definition->getStringListOption('cascade');
        $orphanRemoval = (bool) $definition->getOption('orphanRemoval', false);

        $targetShort = $this->shortClass($target);
        $relationArgs = [sprintf("mappedBy: '%s'", $mappedBy), sprintf('targetEntity: %s::class', $targetShort)];
        if ($cascade) {
            $relationArgs[] = sprintf("cascade: ['%s']", implode("', '", $cascade));
        }
        if ($orphanRemoval) {
            $relationArgs[] = 'orphanRemoval: true';
        }

        $lines[] = sprintf('    #[ORM\\OneToMany(%s)]', implode(', ', $relationArgs));
        $lines[] = sprintf('    private Collection $%s;', $definition->name);
        $lines[] = '';

        $getterSuffix = $this->namingHelper->ensureStudly($definition->name);
        $singularStudly = $this->namingHelper->ensureStudly($this->namingHelper->singularize($definition->name));
        $setterOnTarget = 'set' . $this->namingHelper->ensureStudly($mappedBy);
        $getterOnTarget = 'get' . $this->namingHelper->ensureStudly($mappedBy);

        $methods[] = sprintf('    /**');
        $methods[] = sprintf('     * @return Collection<int, %s>', $targetShort);
        $methods[] = '     */';
        $methods[] = sprintf('    public function get%s(): Collection', $getterSuffix);
        $methods[] = '    {';
        $methods[] = sprintf('        return $this->%s;', $definition->name);
        $methods[] = '    }';
        $methods[] = '';

        $methods[] = sprintf('    public function add%s(%s $entity): self', $singularStudly, $targetShort);
        $methods[] = '    {';
        $methods[] = sprintf('        if (!$this->%s->contains($entity)) {', $definition->name);
        $methods[] = sprintf('            $this->%s->add($entity);', $definition->name);
        $methods[] = sprintf('            $entity->%s($this);', $setterOnTarget);
        $methods[] = '        }';
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';
        $methods[] = '';

        $methods[] = sprintf('    public function remove%s(%s $entity): self', $singularStudly, $targetShort);
        $methods[] = '    {';
        $methods[] = sprintf('        if ($this->%s->removeElement($entity) && $entity->%s() === $this) {', $definition->name, $getterOnTarget);
        $methods[] = sprintf('            $entity->%s(null);', $setterOnTarget);
        $methods[] = '        }';
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';

        return [
            'imports' => $imports,
            'lines' => $lines,
            'methods' => $methods,
            'constructor' => sprintf('$this->%s = new ArrayCollection();', $definition->name),
            'requires_collection' => true,
        ];
    }

    private function renderManyToManyRelation(PropertyDefinition $definition): array
    {
        $imports = ['Doctrine\\ORM\\Mapping as ORM'];
        $imports[] = 'Doctrine\\Common\\Collections\\ArrayCollection';
        $imports[] = 'Doctrine\\Common\\Collections\\Collection';
        $lines = [];
        $methods = [];
        $target = (string) $definition->getOption('target');
        if ('' === $target) {
            throw new \InvalidArgumentException('Relation target class must be provided.');
        }
        $imports[] = $target;
        $owning = (bool) $definition->getOption('owning', true);
        $mappedBy = (string) $definition->getOption('mappedBy', '');
        $inversedBy = (string) $definition->getOption('inversedBy', '');
        $cascade = $definition->getStringListOption('cascade');

        $targetShort = $this->shortClass($target);
        $relationArgs = [sprintf('targetEntity: %s::class', $targetShort)];
        if ($owning) {
            if ('' !== $inversedBy) {
                $relationArgs[] = sprintf("inversedBy: '%s'", $inversedBy);
            }
        } else {
            if ('' === $mappedBy) {
                throw new \InvalidArgumentException(sprintf('Many-to-many relation "%s" requires mappedBy when inverse.', $definition->name));
            }
            $relationArgs[] = sprintf("mappedBy: '%s'", $mappedBy);
        }
        if ($cascade) {
            $relationArgs[] = sprintf("cascade: ['%s']", implode("', '", $cascade));
        }

        $lines[] = sprintf('    #[ORM\\ManyToMany(%s)]', implode(', ', $relationArgs));
        $lines[] = sprintf('    private Collection $%s;', $definition->name);
        $lines[] = '';

        $getterSuffix = $this->namingHelper->ensureStudly($definition->name);
        $singularStudly = $this->namingHelper->ensureStudly($this->namingHelper->singularize($definition->name));

        $methods[] = sprintf('    /**');
        $methods[] = sprintf('     * @return Collection<int, %s>', $targetShort);
        $methods[] = '     */';
        $methods[] = sprintf('    public function get%s(): Collection', $getterSuffix);
        $methods[] = '    {';
        $methods[] = sprintf('        return $this->%s;', $definition->name);
        $methods[] = '    }';
        $methods[] = '';

        $methods[] = sprintf('    public function add%s(%s $entity): self', $singularStudly, $targetShort);
        $methods[] = '    {';
        $methods[] = sprintf('        if (!$this->%s->contains($entity)) {', $definition->name);
        $methods[] = sprintf('            $this->%s->add($entity);', $definition->name);
        if (!$owning && '' !== $mappedBy) {
            $adder = 'add' . $this->namingHelper->ensureStudly($this->namingHelper->singularize($mappedBy));
            $methods[] = sprintf('            $entity->%s($this);', $adder);
        }
        $methods[] = '        }';
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';
        $methods[] = '';

        $methods[] = sprintf('    public function remove%s(%s $entity): self', $singularStudly, $targetShort);
        $methods[] = '    {';
        $methods[] = sprintf('        if ($this->%s->removeElement($entity)) {', $definition->name);
        if (!$owning && '' !== $mappedBy) {
            $remover = 'remove' . $this->namingHelper->ensureStudly($this->namingHelper->singularize($mappedBy));
            $methods[] = sprintf('            $entity->%s($this);', $remover);
        }
        $methods[] = '        }';
        $methods[] = '';
        $methods[] = '        return $this;';
        $methods[] = '    }';

        return [
            'imports' => $imports,
            'lines' => $lines,
            'methods' => $methods,
            'constructor' => sprintf('$this->%s = new ArrayCollection();', $definition->name),
            'requires_collection' => true,
        ];
    }

    private function renderScalarAssignment(PropertyDefinition $definition, string $setter): array
    {
        $lines = [];
        $nullable = $definition->isNullable();

        $lines[] = sprintf("        if (\array_key_exists('%s', \$data)) {", $definition->name);
        $lines[] = sprintf("            \$value = \$data['%s'];", $definition->name);

        if ($nullable) {
            $lines[] = "            if ('' === \$value) {";
            $lines[] = '                $value = null;';
            $lines[] = '            }';
        }

        switch ($definition->type) {
            case PropertyType::INT:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (int) $value;';
                $lines[] = '            }';
                break;
            case PropertyType::FLOAT:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (float) $value;';
                $lines[] = '            }';
                break;
            case PropertyType::BOOL:
                $lines[] = '            if (null !== $value && !is_bool($value)) {';
                $lines[] = '                $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);';
                $lines[] = '                if (null === $filtered) {';
                $lines[] = sprintf("                    throw new RestException('The field \"%s\" must be boolean.');", $definition->name);
                $lines[] = '                }';
                $lines[] = '                $value = $filtered;';
                $lines[] = '            }';
                break;
            case PropertyType::DATETIME:
            case PropertyType::DATE:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                try {';
                $lines[] = '                    $value = new \DateTimeImmutable((string) $value);';
                $lines[] = '                } catch (\Throwable $e) {';
                $lines[] = sprintf("                    throw new RestException('The field \"%s\" must be a valid date string.');", $definition->name);
                $lines[] = '                }';
                $lines[] = '            }';
                break;
            case PropertyType::DECIMAL:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (string) $value;';
                $lines[] = '            }';
                break;
            default:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (string) $value;';
                $lines[] = '            }';
                break;
        }

        if (!$nullable) {
            if (\in_array($definition->type, [PropertyType::STRING, PropertyType::TEXT], true)) {
                $lines[] = '            if (null === $value) {';
            } else {
                $lines[] = "            if (null === $value || '' === $value) {";
            }
            $lines[] = sprintf("                throw new RestException('The field \"%s\" is required.');", $definition->name);
            $lines[] = '            }';
        }

        $lines[] = sprintf('            $entity->%s($value);', $setter);
        $lines[] = '        }';
        $lines[] = '';

        return $lines;
    }

    private function renderTranslationAssignment(PropertyDefinition $definition, string $setter, string $localeVariable): array
    {
        $lines = [];
        $nullable = $definition->isNullable();

        $lines[] = sprintf("        if (\array_key_exists('%s', \$data)) {", $definition->name);
        $lines[] = sprintf("            \$value = \$data['%s'];", $definition->name);

        if ($nullable) {
            $lines[] = "            if ('' === \$value) {";
            $lines[] = '                $value = null;';
            $lines[] = '            }';
        }

        switch ($definition->type) {
            case PropertyType::INT:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (int) $value;';
                $lines[] = '            }';
                break;
            case PropertyType::FLOAT:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (float) $value;';
                $lines[] = '            }';
                break;
            case PropertyType::BOOL:
                $lines[] = '            if (null !== $value && !is_bool($value)) {';
                $lines[] = '                $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);';
                $lines[] = '                if (null === $filtered) {';
                $lines[] = sprintf("                    throw new RestException('The field \"%s\" must be boolean.');", $definition->name);
                $lines[] = '                }';
                $lines[] = '                $value = $filtered;';
                $lines[] = '            }';
                break;
            case PropertyType::DATETIME:
            case PropertyType::DATE:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                try {';
                $lines[] = '                    $value = new \DateTimeImmutable((string) $value);';
                $lines[] = '                } catch (\Throwable $e) {';
                $lines[] = sprintf("                    throw new RestException('The field \"%s\" must be a valid date string.');", $definition->name);
                $lines[] = '                }';
                $lines[] = '            }';
                break;
            case PropertyType::DECIMAL:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (string) $value;';
                $lines[] = '            }';
                break;
            default:
                $lines[] = '            if (null !== $value) {';
                $lines[] = '                $value = (string) $value;';
                $lines[] = '            }';
                break;
        }

        if (!$nullable) {
            $lines[] = '            if (null === $value || \'\' === $value) {';
            $lines[] = sprintf("                throw new RestException('The field \"%s\" is required.');", $definition->name);
            $lines[] = '            }';
        }

        $lines[] = sprintf('            $entity->%s($value, $%s);', $setter, $localeVariable);
        $lines[] = '        }';
        $lines[] = '';

        return $lines;
    }

    private function renderTranslationMethods(CustomEntityConfiguration $configuration): array
    {
        $translationShort = $configuration->getTranslationShortClass();
        $lines = [];

        $lines[] = '    public function getDefaultLocale(): string';
        $lines[] = '    {';
        $lines[] = '        return $this->defaultLocale;';
        $lines[] = '    }';
        $lines[] = '';

        $lines[] = '    public function setDefaultLocale(string $defaultLocale): self';
        $lines[] = '    {';
        $lines[] = '        $this->defaultLocale = $defaultLocale;';
        $lines[] = '';
        $lines[] = '        return $this;';
        $lines[] = '    }';
        $lines[] = '';

        $lines[] = sprintf('    /**');
        $lines[] = sprintf('     * @return Collection<string, %s>', $translationShort);
        $lines[] = '     */';
        $lines[] = '    public function getTranslations(): Collection';
        $lines[] = '    {';
        $lines[] = '        return $this->translations;';
        $lines[] = '    }';
        $lines[] = '';

        $lines[] = sprintf('    public function translate(?string $locale = null): %s', $translationShort);
        $lines[] = '    {';
        $lines[] = '        $localeKey = $this->resolveLocale($locale);';
        $lines[] = '        if ($this->translations->containsKey($localeKey)) {';
        $lines[] = sprintf('            /** @var %s $translation */', $translationShort);
        $lines[] = '            $translation = $this->translations->get($localeKey);';
        $lines[] = '            return $translation;';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = sprintf('        $translation = new %s($this, $localeKey);', $translationShort);
        $lines[] = '        $this->translations->set($localeKey, $translation);';
        $lines[] = '';
        $lines[] = '        return $translation;';
        $lines[] = '    }';
        $lines[] = '';

        $lines[] = '    private function resolveLocale(?string $locale): string';
        $lines[] = '    {';
        $lines[] = '        $localeKey = $locale ?? $this->defaultLocale;';
        $lines[] = '';
        $lines[] = '        return \'\' !== $localeKey ? $localeKey : $this->defaultLocale;';
        $lines[] = '    }';
        $lines[] = '';

        if ($configuration->translation) {
            foreach ($configuration->translation->properties as $definition) {
                $methodSuffix = $this->namingHelper->ensureStudly($definition->name);
                $nullable = $definition->isNullable();
                $phpBaseType = $this->resolvePhpBaseType($definition);
                $phpType = $nullable ? '?' . $phpBaseType : $phpBaseType;
                $setterType = $phpType;
                if (!$nullable && 'string' === $phpBaseType) {
                    $setterType = '?' . $phpBaseType;
                }

                $lines[] = sprintf('    public function get%s(?string $locale = null): %s', $methodSuffix, $phpType);
                $lines[] = '    {';
                $lines[] = sprintf('        return $this->translate($locale)->get%s();', $methodSuffix);
                $lines[] = '    }';
                $lines[] = '';
                $lines[] = sprintf('    public function set%s(%s $value, ?string $locale = null): self', $methodSuffix, $setterType);
                $lines[] = '    {';
                $lines[] = sprintf('        $this->translate($locale)->set%s($value);', $methodSuffix);
                $lines[] = '';
                $lines[] = '        return $this;';
                $lines[] = '    }';
                $lines[] = '';
            }
        }

        return $lines;
    }

    private function resolvePhpBaseType(PropertyDefinition $definition): string
    {
        return match ($definition->type) {
            PropertyType::STRING, PropertyType::TEXT, PropertyType::UUID, PropertyType::ULID, PropertyType::ENUM => 'string',
            PropertyType::INT => 'int',
            PropertyType::BOOL => 'bool',
            PropertyType::DATETIME, PropertyType::DATE => '\\DateTimeImmutable',
            PropertyType::DECIMAL => 'string',
            PropertyType::FLOAT => 'float',
            default => 'mixed',
        };
    }

    private function dumpPhpFile(string $path, string $contents, SymfonyStyle $io): void
    {
        if ('' === trim($contents)) {
            return;
        }

        $dir = \dirname($path);
        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir);
        }

        if ($this->filesystem->exists($path)) {
            $io->warning(sprintf('Skipped existing file: %s', $this->relativePath($path)));

            return;
        }

        $this->filesystem->dumpFile($path, $contents);
        $io->writeln(sprintf('<info>created</info> %s', $this->relativePath($path)));
    }

    private function dumpXmlFile(string $path, string $contents, SymfonyStyle $io): void
    {
        $this->dumpPhpFile($path, $contents, $io);
    }

    private function formatDefaultValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return sprintf('\'%s\'', addslashes((string) $value));
    }

    private function classToPath(string $fqcn): string
    {
        $relative = str_replace('App\\', '', $fqcn);

        return sprintf('%s/src/%s.php', $this->projectDir, str_replace('\\', '/', $relative));
    }

    private function relativePath(string $absolute): string
    {
        return ltrim(str_replace($this->projectDir . '/', '', $absolute), '/');
    }

    private function shortClass(string $fqcn): string
    {
        return trim(strrchr($fqcn, '\\') ?: $fqcn, '\\\\');
    }
}
