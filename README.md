# Perimetre Core

Shared agency plugin for Perimetre WordPress projects. Built for headless setups, but safe to drop into any standard WordPress site — every feature is opt-in and defaults to inert.

Perimetre Core defines the rules. Project Core follows them.

**Repository:** `perimetre-core-wp-plugin`
**WordPress plugin slug:** `perimetre-core`

The repository name reflects the platform and purpose. The plugin slug is what WordPress uses internally and stays consistent across all projects.

---

## Requirements

- PHP 8.1+
- WordPress 6.4+
- [ACF Pro](https://www.advancedcustomfields.com) (for ACF blocks)
- [WPGraphQL](https://www.wpgraphql.com)
- [WPGraphQL Content Blocks](https://github.com/wpengine/wp-graphql-content-blocks)
- [WPGraphQL for ACF](https://github.com/wp-graphql/wpgraphql-acf)

---

## What This Plugin Does

- Provides abstract base classes for registering ACF blocks and custom native blocks
- Provides a central block registry that Project Core uses to register all blocks
- Provides GraphQL registration utilities that enforce naming conventions
- Hosts shared blocks that are used across multiple projects (grows over time)
- Provides a configurable status / health-check endpoint (DB, object cache, cron)
- Provides configurable outgoing webhooks on post status changes (publish, draft, trash, delete)
- Provides a Remote Login feature that lets the Helm portal SSO existing WP users in by email

What it does **not** do:

- Manage third-party plugins or dependencies
- Render block markup on its behalf — `AcfBlock` provides hooks (`render_preview()` / `render_frontend()`) but the markup is the subclass's job
- Contain project-specific logic of any kind

---

## Where This Works

Perimetre Core is designed for headless Perimetre projects but is safe to install on any standard WordPress site. The plugin's surface area is opt-in and the defaults don't change anything about a host site:

- **Status endpoint** is off by default. While disabled, no rewrite rule is registered and no scheduled events run — the `/status` slug stays available for the host site to use.
- **Webhooks** are off by default. The dispatcher doesn't fire until the master toggle is on and a URL is configured.
- **Remote Login** is off by default. The REST route exists once the plugin is active but rejects every request unless the toggle is on and the portal URL and API key are both set. No matching WP user — no login; no auto-creation.
- **Block abstracts** (`AcfBlock`, `NativeBlock`) only register blocks the host project explicitly opts into via `Registry::register_block()`. Existing ACF blocks registered the conventional way are untouched.
- **GraphQL utilities** are no-ops unless WPGraphQL is installed and active.
- **CTA helpers** are pure functions — they only run when called.

How `AcfBlock` differs between contexts:

- **Headless site:** the WordPress frontend isn't visited. The default `render_preview()` shows an editor placeholder and `render_frontend()` is a no-op (or emits the bare InnerBlocks token for nested-children blocks). Subclasses leave both alone — frontend rendering happens in the headless consumer.
- **Standard site:** subclasses override `render_frontend()` with real markup (typically `get_field()` calls or a `template-parts/blocks/<slug>.php` include). The editor placeholder still shows in the canvas. See [Frontend rendering](#frontend-rendering) below.

---

## Versioning

Releases follow [semantic versioning](https://semver.org):

| Increment | When |
|---|---|
| `1.0.1` — patch | Bug fixes, no breaking changes |
| `1.1.0` — minor | New features, backwards compatible |
| `2.0.0` — major | Breaking changes — renamed classes, changed method signatures, restructured namespaces |

Projects should never update to a new major version without testing on staging first.

To create a release, push a version tag:

```bash
git tag v1.2.0
git push origin v1.2.0
```

This triggers the release workflow which builds a clean plugin zip and publishes it as a GitHub release with auto-generated release notes.

---

## Installation

Download the zip for the desired version from the [GitHub releases page](https://github.com/perimetre/perimetre-core-wp-plugin/releases) and install via WP-CLI:

```bash
# Local (DDEV)
ddev wp plugin install /path/to/perimetre-core-1.2.0.zip --activate

# Remote server
wp plugin install /path/to/perimetre-core-1.2.0.zip --activate
```

Record the installed version in the project's `plugins.md`.

Updates are detected automatically via GitHub Releases. When a new version is published, WordPress will show an update notice in the admin dashboard, allowing one-click updates. You can also update manually by downloading the release zip and installing it.

---

## Structure

```
perimetre-core/
├── perimetre-core.php          Plugin entry point. Loads autoloader, bootstraps registries.
├── composer.json               Autoloading and Plugin Update Checker dependency.
├── composer.lock
├── vendor/
│   └── autoload.php            Generated autoloader. Committed to the repository.
├── src/
│   ├── Plugin.php              Textdomain loading and top-level bootstrap helpers.
│   ├── Acf/
│   │   └── cta-fields.php      Helper functions for CTA and CTA Group ACF fields.
│   ├── Blocks/
│   │   ├── Registry.php        Central block registry. Used by Project Core to register blocks.
│   │   ├── AcfBlock.php        Abstract base class for ACF blocks.
│   │   ├── NativeBlock.php     Abstract base class for custom native blocks.
│   │   └── Shared/             Shared blocks used across projects. Empty initially.
│   ├── GraphQL/
│   │   └── Registry.php        GraphQL registration utilities. Enforces naming conventions.
│   ├── Admin/
│   │   └── Tabs.php            Shared tab strip for the Settings > Perimetre Core surface.
│   ├── Status/
│   │   ├── Settings.php        Settings page owner + Status tab.
│   │   ├── Endpoint.php        Rewrite rule, request handling, cron scheduling.
│   │   └── HealthChecks.php    DB, cache, and cron health checks.
│   ├── RemoteLogin/
│   │   ├── Settings.php        Remote Login tab + auto-handshake on save.
│   │   ├── Endpoint.php        WP REST route the portal redirects users to.
│   │   ├── Auth.php            Verify token, consume jti at portal, set auth cookie.
│   │   ├── Connect.php         Site-handshake POST to the portal.
│   │   └── Token.php           HMAC-SHA256 token verification.
│   └── Webhook/
│       ├── Settings.php        Webhooks tab (ACF-backed) + menu-entry hiding.
│       └── Dispatcher.php      Post status hooks and outgoing HTTP dispatch.
├── languages/
│   ├── perimetre-core.pot      Translation template.
│   ├── perimetre-core-fr_FR.po French translations.
│   └── perimetre-core-fr_FR.mo Compiled French translations.
└── README.md
```

---

## Namespace

All Perimetre Core classes live under the `Perimetre\Core` namespace, following PSR-4:

```
Perimetre\Core\Plugin                 →  src/Plugin.php
Perimetre\Core\Blocks\Registry        →  src/Blocks/Registry.php
Perimetre\Core\Blocks\AcfBlock        →  src/Blocks/AcfBlock.php
Perimetre\Core\Blocks\NativeBlock     →  src/Blocks/NativeBlock.php
Perimetre\Core\GraphQL\Registry       →  src/GraphQL/Registry.php
Perimetre\Core\Admin\Tabs             →  src/Admin/Tabs.php
Perimetre\Core\Status\Settings        →  src/Status/Settings.php
Perimetre\Core\Status\Endpoint        →  src/Status/Endpoint.php
Perimetre\Core\Status\HealthChecks    →  src/Status/HealthChecks.php
Perimetre\Core\RemoteLogin\Settings   →  src/RemoteLogin/Settings.php
Perimetre\Core\RemoteLogin\Endpoint   →  src/RemoteLogin/Endpoint.php
Perimetre\Core\RemoteLogin\Auth       →  src/RemoteLogin/Auth.php
Perimetre\Core\RemoteLogin\Connect    →  src/RemoteLogin/Connect.php
Perimetre\Core\RemoteLogin\Token      →  src/RemoteLogin/Token.php
Perimetre\Core\Webhook\Settings       →  src/Webhook/Settings.php
Perimetre\Core\Webhook\Dispatcher     →  src/Webhook/Dispatcher.php
```

---

## How to Register Blocks from Project Core

All blocks — whether defined in Perimetre Core or Project Core — are registered through `Perimetre\Core\Blocks\Registry`. Call `register_block()` before the `init` hook fires.

```php
use Perimetre\Core\Blocks\Registry;

add_action('plugins_loaded', function () {
    Registry::register_block(\MicroBird\Core\Blocks\HeroBlock::class);
    Registry::register_block(\MicroBird\Core\Blocks\FeaturedPostsBlock::class);
});
```

---

## How to Create an ACF Block

Extend `Perimetre\Core\Blocks\AcfBlock` in Project Core (or in Perimetre Core for shared blocks).

```php
<?php

declare(strict_types=1);

namespace MicroBird\Core\Blocks;

use Perimetre\Core\Blocks\AcfBlock;

class HeroBlock extends AcfBlock
{
    protected function get_name(): string
    {
        return 'micro-bird/hero';
    }

    protected function get_title(): string
    {
        return __('Hero', 'micro-bird-core');
    }

    protected function get_graphql_field_name(): string
    {
        // This becomes the field name on the block type in GraphQL:
        // ... on MicroBirdHeroBlock { hero { heading } }
        return 'hero';
    }

    protected function get_description(): string
    {
        return __('Full-width hero section.', 'micro-bird-core');
    }

    protected function register_fields(): void
    {
        acf_add_local_field_group([
            'key'                => 'group_micro_bird_hero',
            'title'              => 'Hero',
            'show_in_graphql'    => 1,
            'graphql_field_name' => $this->get_graphql_field_name(),
            'location'           => [[
                ['param' => 'block', 'operator' => '==', 'value' => $this->get_acf_name()],
            ]],
            'fields' => [
                [
                    'key'   => 'field_micro_bird_hero_heading',
                    'label' => 'Heading',
                    'name'  => 'heading',
                    'type'  => 'text',
                ],
                [
                    'key'   => 'field_micro_bird_hero_subheading',
                    'label' => 'Subheading',
                    'name'  => 'subheading',
                    'type'  => 'textarea',
                ],
            ],
        ]);
    }
}
```

The block is then queryable in GraphQL:

```graphql
... on MicroBirdHeroBlock {
  hero {
    heading
    subheading
  }
}
```

### InnerBlocks (optional)

To allow nested child blocks inside an ACF block, override `get_inner_blocks_template()`. Returning a non-null template automatically bumps the block to `acf_block_version: 2` and merges `'jsx' => true` into `supports`, so the editor renders the InnerBlocks slot.

```php
protected function get_inner_blocks_template(): ?array
{
    return [
        ['core/columns', [], [
            ['core/column', [], []],
            ['core/column', [], []],
        ]],
    ];
}

protected function get_allowed_blocks(): ?array
{
    return ['core/columns', 'core/column', 'core/paragraph'];
}

protected function get_template_lock(): false|string
{
    return 'all';
}
```

`get_allowed_blocks()` returns the post-transform names (e.g. `acf/micro-bird-card`, `core/paragraph`). `get_template_lock()` accepts `false`, `'all'`, `'insert'`, or `'contentOnly'`.

### Frontend rendering

`AcfBlock::render()` dispatches to two protected methods:

- `render_preview()` — what the block editor canvas shows. Default: a labeled placeholder div, or the InnerBlocks slot for blocks with a template.
- `render_frontend()` — what visitors see on the public page. Default: nothing for plain blocks; the bare `<InnerBlocks />` JSX token for InnerBlocks-enabled blocks (so ACF v2 expands children at the right position).

On a **headless site** the frontend isn't rendered by WordPress, so the defaults are correct as-is — leave both alone.

On a **standard (non-headless) site**, override `render_frontend()` with real markup using `get_field()` calls (or include a `template-parts/blocks/<slug>.php` file). For InnerBlocks-enabled blocks, call `$this->emit_inner_blocks_token($this->get_inner_blocks_template())` from your override so child blocks still render at the desired position. You may also want to override `get_mode()` to return `'preview'` for live in-editor rendering.

```php
protected function render_frontend(array $block, string $content, int $post_id): void
{
    $heading = get_field('heading');
    $subheading = get_field('subheading');
    ?>
    <section class="hero">
        <h1><?= esc_html($heading) ?></h1>
        <p><?= esc_html($subheading) ?></p>
    </section>
    <?php
}
```

---

## How to Create a Custom Native Block

Extend `Perimetre\Core\Blocks\NativeBlock` and place a `block.json` in the block directory.

```php
<?php

declare(strict_types=1);

namespace MicroBird\Core\Blocks;

use Perimetre\Core\Blocks\NativeBlock;

class CalloutBlock extends NativeBlock
{
    protected function get_name(): string
    {
        return 'micro-bird/callout';
    }

    protected function get_block_dir(): string
    {
        return MICRO_BIRD_CORE_PATH . 'blocks/callout';
    }
}
```

The `block.json` in `blocks/callout/` defines all attributes. These are automatically exposed in GraphQL via WPGraphQL Content Blocks under `attributes`:

```graphql
... on MicroBirdCalloutBlock {
  attributes {
    message
    type
  }
}
```

---

## How to Register Custom GraphQL Fields

Use `Perimetre\Core\GraphQL\Registry` instead of calling WPGraphQL functions directly. This enforces naming conventions.

```php
use Perimetre\Core\GraphQL\Registry as GraphQLRegistry;

add_action('graphql_register_types', function () {
    GraphQLRegistry::register_field('Post', 'formattedDate', [
        'type'        => 'String',
        'description' => 'The post date formatted for display.',
        'resolve'     => fn ($post) => get_the_date('F j, Y', $post->databaseId),
    ]);
});
```

Field names must be `camelCase`. Type names must be `PascalCase`. Violations trigger a `_doing_it_wrong()` notice in debug mode.

---

## CTA Field Helpers

Perimetre Core provides two helper functions for registering CTA (Call-to-Action) fields, so every project uses the same field structure.

### `perimetre_cta_fields`

Returns ACF sub-fields for a single CTA: a link field, a variant select, and an optional icon (SVG or PNG).

```php
perimetre_cta_fields(
    string $prefix = 'cta',
    array  $variants = ['default' => 'Default', 'primary' => 'Primary', 'secondary' => 'Secondary'],
): array
```

The icon sub-field is registered as an ACF `image` field with `return_format: id` and `mime_types: svg,png`. It is optional (`required: 0`) and exposed through WPGraphQL.

### `perimetre_cta_group_fields`

Returns a tab + repeater of CTAs (each with link, variant, and optional icon), ready to spread into a field group's `fields` array.

```php
perimetre_cta_group_fields(
    string $prefix   = 'cta_group',
    string $label    = 'CTAs',
    int    $min      = 0,
    int    $max      = 3,
    array  $variants = ['default' => 'Default', 'primary' => 'Primary', 'secondary' => 'Secondary'],
): array
```

### Usage

Spread the result into your ACF field group's `fields` array:

```php
'fields' => [
    [
        'key'   => 'field_micro_bird_hero_heading',
        'label' => 'Heading',
        'name'  => 'heading',
        'type'  => 'text',
    ],
    ...perimetre_cta_group_fields('hero_ctas', 'Hero CTAs'),
],
```

To customize variants or limits:

```php
...perimetre_cta_group_fields(
    prefix:   'hero_ctas',
    label:    'Hero CTAs',
    min:      1,
    max:      2,
    variants: ['primary' => 'Primary', 'ghost' => 'Ghost'],
),
```

### Template example

```php
$ctas = get_field('hero_ctas');

if ($ctas) : ?>
    <div class="cta-group">
        <?php foreach ($ctas as $cta) :
            $link    = $cta['hero_ctas_item_link'];
            $variant = $cta['hero_ctas_item_variant'];
            if ($link) : ?>
                <a href="<?= esc_url($link['url']) ?>"
                   class="btn btn--<?= esc_attr($variant) ?>"
                   target="<?= esc_attr($link['target']) ?>">
                    <?= esc_html($link['title']) ?>
                </a>
        <?php endif; endforeach; ?>
    </div>
<?php endif; ?>
```

---

## Status Endpoint

Perimetre Core includes a configurable health-check endpoint. Configure it under **Settings → Perimetre Core**.

The endpoint is off by default and registers no rewrite rule, query var, or scheduled event until enabled — safe to install on a site that already uses the `/status` slug for something else.

| Setting | Default | Description |
|---|---|---|
| Status enabled | Off | Enables the endpoint. While off, no rewrite rule is registered and the URL falls through to a regular 404. |
| Status slug | `status` | The URL path (e.g. `https://example.com/status`). |
| Secret token | Auto-generated | Required for the full health payload. |

### Responses

**Without token** (or wrong token) — `GET /status`:

```json
{ "status": "ok" }
```

**With valid token** — `GET /status?token=xxx`:

```json
{
  "status": "ok",
  "timestamp": "2026-04-02T12:00:00Z",
  "db": "ok",
  "cache": "ok",
  "wp_version": "6.7",
  "php_version": "8.3.0",
  "plugin_version": "1.9.0"
}
```

If any check fails, `status` becomes `"error"`, a `"failing"` array lists the failing checks, and the response returns HTTP 500.

### Health checks

| Check | Method |
|---|---|
| `db` | `$wpdb->check_connection()` |
| `cache` | `wp_using_ext_object_cache()` + set/get probe. Returns `"disabled"` when no external cache is active. |

---

## Webhooks

Perimetre Core can fire outgoing HTTP POST requests when posts change status. Configure it under **Settings → Perimetre Core**, on the **Webhooks** tab (requires ACF Pro).

Designed for headless on-demand revalidation (e.g. Next.js `revalidatePath` / `revalidateTag`), but the dispatch mechanism is generic — any HTTP-receiving consumer (sync jobs, automation tools, third-party integrations) can be the target. The master toggle is off by default; nothing fires until it's enabled and at least one URL is configured. Each watched event is dispatched to every configured URL.

### Settings

| Setting | Default | Description |
|---|---|---|
| Enable Webhooks | Off | Master toggle. When off, no requests are sent. |
| Webhook URLs | — | One or more endpoints that receive the POST request. Add as many as needed; every URL receives each event. |
| Secret Token | — | Sent as a `Bearer` token in the `Authorization` header on every request. |
| Watched Post Types | All public types | Which post types trigger webhooks. Leave empty to watch all. |
| Watched Events | Publish, Trash, Delete | Which events trigger webhooks (post changes, options saves, menu updates). |
| Request Timeout (s) | 5 | HTTP timeout (1–30 seconds). Requests are non-blocking. |

### Events

| Event | Trigger |
|---|---|
| `post.published` | Post transitions into `publish` from another status |
| `post.updated` | Already-published post is saved again |
| `post.drafted` | Post transitions to `draft` or `pending` |
| `post.privatized` | Post transitions to `private` |
| `post.scheduled` | Post transitions to `future` |
| `post.trashed` | Post transitions to `trash` |
| `post.deleted` | Post is permanently deleted |
| `options.saved` | ACF options page is saved (excludes the webhook settings page itself) |
| `menu.saved` | Navigation menu is created or updated |
| `menu.deleted` | Navigation menu is deleted |

### Post Payload

```json
{
  "event": "post.published",
  "post_id": 42,
  "post_type": "page",
  "post_slug": "about-us",
  "post_title": "About Us",
  "permalink": "/about-us/",
  "language": "en",
  "taxonomies": {
    "category": ["news"],
    "post_tag": ["launch"]
  },
  "timestamp": 1713000000,
  "old_status": "draft",
  "new_status": "publish"
}
```

- `permalink` — relative URL path, useful for on-demand revalidation (e.g. Next.js `revalidatePath`)
- `language` — WPML language code when WPML is active, `null` otherwise
- `taxonomies` — public taxonomy terms keyed by taxonomy slug, so the frontend can revalidate archive pages
- `old_status` / `new_status` — included on status transitions, omitted on permanent deletes

### Options Payload

```json
{
  "event": "options.saved",
  "options_page": "acf-options-seo",
  "timestamp": 1713000000
}
```

### Menu Payload

```json
{
  "event": "menu.saved",
  "menu_id": 3,
  "menu_name": "Main Navigation",
  "menu_slug": "main-navigation",
  "timestamp": 1713000000
}
```

---

## Naming Conventions

| Concept | Convention | Example |
|---|---|---|
| Block namespace | Plugin slug | `perimetre/hero`, `micro-bird/callout` |
| GraphQL type | PascalCase from namespace | `PerimetreHeroBlock`, `MicroBirdCalloutBlock` |
| ACF field group name | camelCase | `hero`, `featuredPosts` |
| Field names | camelCase | `heading`, `subheading`, `ctaLabel` |
| Shared concept names | Consistent across all blocks | `heading` not `title` |
| Link/CTA fields | Always `cta` with `label` and `url` | `cta { label url }` |
| Image fields | Always `image` | `image { sourceUrl altText }` |

---

## Coding Standards

- PHP style: **PSR-12**
- File/namespace structure: **PSR-4**
- No anonymous functions on hooks — use named methods or class methods

---

## Adding a Shared Block

When a block has proven useful across multiple projects, it can be added to Perimetre Core:

1. Create the block class in `src/Blocks/Shared/`
2. Use the `perimetre/` namespace
3. Register it in `src/Blocks/Registry::register_shared_blocks()`
4. Document it in this README

The old block in Project Core can remain under its project namespace — both coexist without conflict.

---

## Current Version

**1.14.0**

Update this when bumping the version in `perimetre-core.php`.

---

## Changelog

### 1.14.0

- Register all ACF blocks as **ACF Blocks v3 / WordPress Block API v3** (`api_version => 3`, `acf_block_version => 3`). WordPress 6.9 emits a console deprecation warning for blocks registered with API version ≤ 2 ("Block with API version 2 or lower is deprecated since version 6.9"), and WordPress 7.0 will enforce v3. This silences the warning on every ACF block and makes blocks iframe-editor compatible.
- **Requires ACF Pro ≥ 6.6** (the floor for ACF Blocks v3).
- **Breaking:** removed `AcfBlock::get_mode()`. ACF Blocks v3 drops the edit/preview "mode" concept — blocks always render their preview template on the canvas while ACF fields move to the Block sidebar / slide-out modal, so the `mode` arg is ignored. Any Project Core subclass overriding `get_mode()` now has a dead override (no error, but it has no effect). `get_inner_blocks_template()` still merges `'jsx' => true` into supports for the `<InnerBlocks />` slot.
- `NativeBlock` subclass `block.json` files should declare `"apiVersion": 3`.

### 1.13.1

- Fix **Watched Post Types** on the Webhooks settings page omitting headless CPTs. The checkbox list (and the "watch everything" default fallback) enumerated post types with `get_post_types(['public' => true])`, so CPTs registered the headless way (`public => false, show_in_graphql => true`) never appeared and never fired webhooks. Enumeration now unions public **and** GraphQL-exposed post types (minus `attachment`) via a shared `Webhook\Settings::get_watchable_post_types()` helper.

### 1.13.0

- Add **opt-in per-request edge caching** for WPGraphQL (`GraphQL\CacheControl`). The frontend appends a `?edgeCache=<seconds>` query var to specific calls (search, build-time) to make that response cacheable; the global default stays uncacheable so nothing else is affected. A `graphql_response_headers_to_send` filter (registered at `PHP_INT_MAX`, after WPGraphQL Smart Cache) sets `Cache-Control: max-age/s-maxage` from the requested TTL, capped at 3600s. Logged-in requests stay no-store, and invalidation is TTL-only (Cloudways/Varnish cannot purge by tag), so keep TTLs short (30–60s) where staleness is user-visible.

### 1.12.0

- Webhooks now support **multiple URLs**: the single Webhook URL field is replaced by a repeater so any number of endpoints can be added. Each watched event is dispatched to every configured URL, all sharing the same secret token. Existing single-URL configs keep firing via a backward-compat fallback to the legacy `options_perimetre_webhook_url` value.

### 1.11.0

- Add **Remote Login** feature: WP REST endpoint (`/wp-json/perimetre-core/v1/remote-login`) that accepts an HMAC-SHA256 signed token from the Helm portal, consumes the `jti` server-side at the portal (single-use enforced there), and logs the matching WP user in via `wp_set_auth_cookie`. No matching WP user means no login — never auto-creates users. Disabled and inert by default.
- Unify the admin surface under a single **Settings → Perimetre Core** menu entry with a tab strip (`Admin\Tabs`): **Status**, **Remote Login**, **Webhooks**. The Webhooks tab links to the existing ACF options page; its standalone menu entry is hidden via `remove_submenu_page()` so both pages appear as tabs on one surface.
- Remote Login auto-handshake on save: saving the Remote Login tab POSTs to `{portalUrl}/api/sites/connect` with `Authorization: Bearer <apiKey>` and surfaces the result as an admin notice. No separate "Connect" button — saves and handshakes always run against the same persisted values.

### 1.10.0

- `AcfBlock::get_mode()` now picks a smart default: `'preview'` when `get_inner_blocks_template()` is non-null (so the `<InnerBlocks />` slot stays visible on the canvas), `'edit'` otherwise. Subclasses with InnerBlocks no longer need to override `get_mode()` manually.
- Add `AcfBlock::get_editor_notice()` hook — return a string to render an amber reminder banner above the InnerBlocks slot, useful for `mode='preview'` blocks where ACF fields live only in the right-hand sidebar.
- Ship an editor stylesheet (`assets/editor.css`) enqueued via `Plugin::enqueue_editor_assets()` that keeps the InnerBlocks appender always visible, outlines empty columns, and styles the editor notice.

### 1.9.0

- Drop-in safety on non-headless sites: the `/status` rewrite rule now only registers when the endpoint is enabled, and the hourly status cron has been removed (`cron_last_run` is no longer in the payload — monitor `status` directly).
- `AcfBlock::render()` split into protected `render_preview()` (editor canvas) and `render_frontend()` (public page) so the editor placeholder no longer leaks into rendered HTML on standard sites. Headless sites are unchanged — subclasses that don't override `render()` keep their existing editor preview.
- Add `AcfBlock::get_mode()` override (defaults to `'edit'`) and `AcfBlock::emit_inner_blocks_token()` helper for subclasses overriding `render_frontend()`.

**Upgrade note:** sites that previously ran 1.8.0 with the status endpoint enabled will have an orphaned `perimetre_status_cron` event scheduled. The action handler no longer exists so it's a silent no-op, but to clear it from the schedule, deactivate and reactivate the plugin after upgrading.

### 1.8.0

- Add opt-in InnerBlocks support to `AcfBlock`. Override `get_inner_blocks_template()` to enable nested children; `get_allowed_blocks()` and `get_template_lock()` restrict insertion and lock the template shape. When a template is returned, `acf_block_version` is bumped to 2 and `'jsx' => true` is merged into `supports` automatically.

### 1.7.0

- Add optional icon sub-field on CTA helpers (`perimetre_cta_fields`, `perimetre_cta_group_fields`) — accepts SVG or PNG, returns the attachment ID, exposed through WPGraphQL

### 1.6.0

- Add `AcfBlock::get_acf_name()` for use in ACF field-group block location rules

### 1.5.1

- Default ACF blocks to edit mode instead of preview mode

### 1.5.0

- Add options and menu webhook events

### 1.4.1

- Add permalink, language (WPML), and taxonomies to webhook payload
- Hide webhook settings fields when disabled via ACF conditional logic
- Move payload example to bottom of settings page
- Fix trashed post permalinks including `__trashed` suffix in payload

### 1.4.0

- Add outgoing webhooks on post status changes with ACF options configuration

### 1.3.1

- Fix rewrite rules not flushing when enabling the status endpoint for the first time
- Add helper text to status slug and secret token settings fields

### 1.3.0

- Add status / health-check endpoint with admin settings (enable toggle, slug, secret token)
- Add i18n support with English (default) and French translations

### 1.2.0

- Add automatic plugin updates from GitHub Releases via Plugin Update Checker

### 1.1.0

- Add CTA field helpers (`perimetre_cta_fields`, `perimetre_cta_group_fields`)

### 1.0.0

- Initial release
- Abstract base classes for ACF blocks and native blocks
- Block registry for centralized registration
- GraphQL registry with naming convention enforcement

---

## Development

After cloning, install the autoloader:

```bash
composer install
```

This also installs [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) for automatic updates from GitHub Releases.

To regenerate the autoloader after adding new classes:

```bash
composer dump-autoload --optimize
```
