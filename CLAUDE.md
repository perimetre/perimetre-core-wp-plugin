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

- `AcfBlock` (abstract) — ACF-based blocks. Fields registered via `acf_add_local_field_group()` produce typed GraphQL output through WPGraphQL for ACF. Queried as `... on XHeroBlock { hero { heading } }`. Opt-in InnerBlocks via `get_inner_blocks_template()` (with `get_allowed_blocks()` / `get_template_lock()` companions) — when non-null, registration auto-bumps `acf_block_version` to 2 and merges `'jsx' => true` into supports. `render()` dispatches to `render_preview()` (editor canvas, default: placeholder div / InnerBlocks slot) and `render_frontend()` (public page, default: empty or bare `<InnerBlocks />` token). Headless subclasses leave both alone; standard-site subclasses override `render_frontend()` with real markup. `get_mode()` defaults to `'edit'`; override to `'preview'` for live in-editor rendering on standard sites.
- `NativeBlock` (abstract) — Gutenberg blocks with `block.json`. Attributes exposed via WPGraphQL Content Blocks under `attributes`. Queried as `... on XCalloutBlock { attributes { message } }`.

**`Blocks\Registry`** — static registry. Project Core calls `Registry::register_block(ClassName::class)` on `plugins_loaded`; the registry instantiates and registers all blocks on `init`. Shared blocks go in `src/Blocks/Shared/` and are added in `register_shared_blocks()`.

**`GraphQL\Registry`** — wrappers around WPGraphQL's `register_graphql_field()` and `register_graphql_object_type()` that enforce naming conventions at registration time (camelCase fields, PascalCase types). Violations trigger `_doing_it_wrong()` and block registration.

**`Status\Endpoint`** — when the endpoint is disabled (default), no rewrite rule is registered and no cron is scheduled, so the plugin is a clean drop-in on standard WP sites. Enabling the toggle flags rewrite rules for flushing on the next admin page load.

**Admin surface** — there is a single **Settings > Perimetre Core** menu entry. Its page (`options-general.php?page=perimetre-core`) renders a tab strip (`Admin\Tabs`) with three tabs: **Status**, **Remote Login** (both backed by the WP Settings API on the same page, distinguished by `?tab=` and internal page-slug constants `Status\Settings::SECTION_PAGE`, `RemoteLogin\Settings::SECTION_PAGE`), and **Webhooks** (which links to the separate ACF options page below). The form's option group is `perimetre-core`; each module registers its fields against its own section page slug so `do_settings_sections()` only renders the active tab.

**`Webhook\Settings`** — registers an ACF options sub-page with fields for enable toggle, URL, secret token, watched post types, watched events, and request timeout. The ACF page (`options-general.php?page=acf-options-webhooks`) is the "Webhooks" tab — its own menu entry under Settings is hidden via `remove_submenu_page()` on `admin_menu` priority 999. The shared tab strip is injected on this screen through `all_admin_notices` so the two pages feel like one. Provides cached static accessors for all settings.

**`Webhook\Dispatcher`** — hooks `transition_post_status` and `before_delete_post`. Maps WordPress statuses to event keys, filters by watched post types/events, and fires non-blocking `wp_remote_post` calls with Bearer auth and a JSON payload.

**`RemoteLogin\Settings`** — adds the "Remote Login" tab to the Settings > Perimetre Core page. Fields: enable toggle, portal URL, and API key. Disabled and inert by default. There is no separate "Connect" button — saving the settings form is the single action, and `RemoteLogin\Connect::do_connect()` fires automatically on the post-save admin page load against the now-persisted options. The auto-connect handler is gated on `?tab=remote-login` so saving the Status tab never triggers a portal handshake. The first WP REST route in the plugin (`/wp-json/perimetre-core/v1/remote-login`) is registered by `RemoteLogin\Endpoint`; HMAC verification (`RemoteLogin\Token`), single-use callback to the portal, and `wp_set_auth_cookie` live in `RemoteLogin\Auth`. Single-use is enforced server-side by the portal — the plugin never trusts the signature alone.

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