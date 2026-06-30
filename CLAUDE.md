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

- `AcfBlock` (abstract) — ACF-based blocks. Fields registered via `acf_add_local_field_group()` produce typed GraphQL output through WPGraphQL for ACF. Queried as `... on XHeroBlock { hero { heading } }`. All blocks register as **ACF Blocks v3 / WordPress Block API v3** (`api_version => 3`, `acf_block_version => 3`) — required since WP 6.9 deprecates (and 7.0 enforces against) API version ≤ 2, and needs **ACF Pro ≥ 6.6**. Opt-in InnerBlocks via `get_inner_blocks_template()` (with `get_allowed_blocks()` / `get_template_lock()` companions) — when non-null, registration merges `'jsx' => true` into supports so the `<InnerBlocks />` token reaches the editor canvas. `render()` dispatches to `render_preview()` (editor canvas) and `render_frontend()` (public page, default: empty or bare `<InnerBlocks />` token). The default `render_preview()` renders a **content-summary card** — block icon + title, then an auto-generated snapshot of the block's filled ACF fields via `get_field_objects()` (lean coverage: text, image thumbnails, toggles, links, "N items" counts; unsupported/empty fields omitted) — so headless blocks have a useful editor without per-block render code. Customize with no required work: override `get_preview_summary()` to curate the summary rows, or override `render_preview()` for full control. Headless subclasses leave `render_frontend()` alone; standard-site subclasses override it with real markup. ACF Blocks v3 removed the edit/preview "mode" concept (fields live in the Block sidebar / slide-out modal), so there is no `get_mode()` hook.
- `NativeBlock` (abstract) — Gutenberg blocks with `block.json`. Attributes exposed via WPGraphQL Content Blocks under `attributes`. Queried as `... on XCalloutBlock { attributes { message } }`. Subclass `block.json` files must declare `"apiVersion": 3` (WP 6.9 deprecates ≤ 2).

**`Blocks\Registry`** — static registry. Project Core calls `Registry::register_block(ClassName::class)` on `plugins_loaded`; the registry instantiates and registers all blocks on `init`. Shared blocks go in `src/Blocks/Shared/` and are added in `register_shared_blocks()`.

**`GraphQL\Registry`** — wrappers around WPGraphQL's `register_graphql_field()` and `register_graphql_object_type()` that enforce naming conventions at registration time (camelCase fields, PascalCase types). Violations trigger `_doing_it_wrong()` and block registration.

**`Webhook\Settings`** — registers an ACF options sub-page (`options-general.php?page=acf-options-webhooks`), surfaced as a standalone **Settings > Webhooks** menu entry, with fields for enable toggle, a repeater of URLs (`get_urls()` returns a `list<string>`, with a backward-compat fallback to the legacy single `options_perimetre_webhook_url` option), shared secret token, watched post types, watched events, and request timeout. Provides cached static accessors for all settings.

**`Webhook\Dispatcher`** — hooks `transition_post_status` and `before_delete_post`. Maps WordPress statuses to event keys, filters by watched post types/events, and fires non-blocking `wp_remote_post` calls (one per configured URL) with Bearer auth and a JSON payload.

**Operational features live in a separate plugin.** The status / health-check endpoint and Helm portal Remote Login were split out into [Perimetre WP Tools](https://github.com/perimetre/perimetre-wp-tools-plugin) (`Perimetre\WpTools\` namespace) so they can be deployed on any site without the block/GraphQL framework. They are not in this repo. The two plugins are independent and can be installed side by side.

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