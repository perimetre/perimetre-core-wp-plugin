# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Perimetre Core is a shared WordPress plugin for headless WordPress projects. It provides abstract base classes and registries for block registration and GraphQL conventions. It is installed on every Perimetre client project — Project Core plugins extend it with project-specific blocks.

This is a **library plugin**, not an application. It defines contracts (abstract classes, registries) that downstream Project Core plugins consume.

## Development Commands

```bash
composer install                       # Install dependencies + autoloader
composer dump-autoload --optimize      # Regenerate autoloader after adding classes
composer lint                          # Run phpcs (PSR-12)
composer lint:fix                      # Auto-fix lint errors
```

## Releases

Push a semver tag to trigger the GitHub Actions release workflow, which builds a plugin zip:

```bash
git tag v1.2.0 && git push origin v1.2.0
```

**When making changes that warrant a version bump**, update all three locations:

1. `Version:` header in `perimetre-core.php`
2. **Current Version** section in `README.md`
3. **Changelog** section in `README.md` (add a new entry above previous versions)

## Architecture

**Entry point:** `perimetre-core.php` — loads autoloader, hooks `Blocks\Registry::register` on `init` (priority 5) and `GraphQL\Registry::register` on `graphql_register_types`.

**Two block types with different GraphQL patterns:**

- `AcfBlock` (abstract) — ACF-based blocks. Fields registered via `acf_add_local_field_group()` produce typed GraphQL output through WPGraphQL for ACF. Queried as `... on XHeroBlock { hero { heading } }`.
- `NativeBlock` (abstract) — Gutenberg blocks with `block.json`. Attributes exposed via WPGraphQL Content Blocks under `attributes`. Queried as `... on XCalloutBlock { attributes { message } }`.

**`Blocks\Registry`** — static registry. Project Core calls `Registry::register_block(ClassName::class)` on `plugins_loaded`; the registry instantiates and registers all blocks on `init`. Shared blocks go in `src/Blocks/Shared/` and are added in `register_shared_blocks()`.

**`GraphQL\Registry`** — wrappers around WPGraphQL's `register_graphql_field()` and `register_graphql_object_type()` that enforce naming conventions at registration time (camelCase fields, PascalCase types). Violations trigger `_doing_it_wrong()` and block registration.

**`Webhook\Settings`** — registers an ACF options sub-page (Settings > Perimetre Webhooks) with fields for enable toggle, URL, secret token, watched post types, watched events, and request timeout. Provides cached static accessors for all settings.

**`Webhook\Dispatcher`** — hooks `transition_post_status` and `before_delete_post`. Maps WordPress statuses to event keys, filters by watched post types/events, and fires non-blocking `wp_remote_post` calls with Bearer auth and a JSON payload.

## Coding Standards

- **PSR-12** style, **PSR-4** autoloading under `Perimetre\Core\` namespace
- `declare(strict_types=1)` in every PHP file
- `vendor/` is committed (contains only the generated autoloader)
- No anonymous functions on WordPress hooks — use named methods or class methods

## Naming Conventions

- Block names: `namespace/block-name` (e.g., `perimetre/hero`)
- GraphQL field names: camelCase (e.g., `featuredPosts`)
- GraphQL type names: PascalCase (e.g., `HeroBlock`)
- ACF field group graphql_field_name: camelCase (e.g., `hero`)
- Shared concept names are standardized: `heading` (not `title`), `cta { label url }` (not `button`), `image` (not `photo`)

## Adding a Shared Block

1. Create block class in `src/Blocks/Shared/` extending `AcfBlock` or `NativeBlock`
2. Use the `perimetre/` namespace for the block name
3. Register it in `Blocks\Registry::register_shared_blocks()`