# Hexis Sulu Custom Entity Generator Bundle

> Build Sulu custom entities, repositories, and admin metadata in seconds with a battle-tested Symfony bundle.

The Hexis Sulu Custom Entity Generator Bundle ships a console generator so every Symfony + Sulu team can scaffold consistent custom entities. It focuses on clean Doctrine entities, translated admin forms, and production-ready CRUD controllers tailored for the Sulu back office.

## Why teams choose this Sulu custom entity generator
- Designed around Sulu best practices for custom entity CRUD and admin UI metadata.
- Generates Doctrine entities, repositories, translations, and REST controllers in one pass.
- Keeps your Sulu custom entity scaffolding repeatable for onboarding, demos, and rapid prototyping.

## Feature highlights
- Symfony bundle that registers automatically (Flex) or manually via `config/bundles.php`.
- Single command for entities, repositories, optional controllers, and Sulu admin configurations.
- Built-in translation entity scaffolding to localise back office forms and lists.
- Supports `auto`, `uuid`, and `ulid` identifier strategies for Sulu custom entity models.
- Updates Sulu admin metadata (`config/forms`, `config/lists`, `translations/admin.*.json`) when present.

## Installation

```bash
composer require hexis-hr/sulu-custom-entity-generator-bundle
```

If you do not use Symfony Flex, register the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Hexis\SuluCustomEntityGeneratorBundle\SuluCustomEntityGeneratorBundle::class => ['all' => true],
];
```

## Quick start: generate your first Sulu custom entity
1. Run the bundled custom entity generator command from your project root (list available commands with `php bin/console`).
2. Answer the interactive prompts (entity name, identifier, admin scaffolding, translations, and properties).
3. Review the generated entities, repositories, controllers, and Sulu admin configuration files.
4. Adjust the generated code to fit your domain specifics, then continue with migrations and fixtures as usual.

## Command reference for Sulu custom entity scaffolding
Run the command non-interactively with explicit options to script repeatable setups:

```bash
php bin/console <generator-command> \
    --entity=Event \
    --namespace="App\\Domain\\Event" \
    --identifier=uuid \
    --property="title:string" \
    --property="startsAt:datetime" \
    --translation \
    --admin
```

Common options:

| Option | Description |
| --- | --- |
| `--entity` | Entity class name (required in non-interactive mode). |
| `--namespace` | Custom namespace for the entity and repository (defaults to `App\Domain\<Entity>`). |
| `--identifier` | Identifier strategy (`auto`, `uuid`, or `ulid`). |
| `--property` | Repeatable property definitions like `name:type[:option=value|flag]`. |
| `--translation` | Enable generation of a dedicated translation entity. |
| Translation options | Options like `--translation-property` mirror the source entity configuration. |
| `--admin` / `--no-admin` | Generate or skip Sulu admin scaffolding. `--admin` implies controller generation unless `--no-controller` is set. |
| `--no-controller` | Skip REST controller scaffolding (used automatically with `--admin`). |

The generator updates existing Sulu admin metadata when appropriate so your config stays in sync.

## Workflow tips for Sulu admin integration
- Check generated forms under `config/forms` and lists under `config/lists` to align naming with your Sulu admin sections.
- Run `php bin/console doctrine:migrations:diff` after generating entities to capture schema updates.
- Update `translations/admin.*.json` with editor-friendly labels to keep the Sulu back office polished.
- Commit the generated Sulu custom entity files immediately so future diffs focus on business logic.

## Development
- Autoloading is PSR-4 via the `Hexis\SuluCustomEntityGeneratorBundle\` namespace.
- Services auto-register through `src/Resources/config/services.php` with autowiring and autoconfiguration enabled.
- Requires PHP 8.1+ and Symfony 6.3+ (fully compatible with Symfony 7.x).
- Depends on `doctrine/inflector` for naming conventions used in Sulu custom entity scaffolding.

## FAQ
- **Does this replace Sulu's default resource bundle?** No. It complements the Sulu admin by generating boilerplate so you can focus on domain logic.
- **Can I scaffold multiple Sulu custom entity modules?** Yes. Run the command as often as needed, adjusting namespaces to keep domains isolated.
- **What about headless or API-only projects?** You can disable admin scaffolding with `--no-admin` to generate only entity logic.

## Contributing
Bug reports, feature requests, and pull requests are welcome. Please open an issue describing your use case before submitting larger changes so we can keep the generator aligned with Sulu custom entity best practices.

## License
This bundle is open-sourced software licensed under the [MIT license](LICENSE).
