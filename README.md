# Perimetre Core

Shared agency plugin for headless WordPress projects. Installed on every Perimetre project as a standard WordPress plugin.

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

What it does **not** do:

- Manage third-party plugins or dependencies
- Handle frontend rendering (this is a headless setup)
- Contain project-specific logic of any kind

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
│   └── Status/
│       ├── Settings.php        Admin settings page for the status endpoint.
│       ├── Endpoint.php        Rewrite rule, request handling, cron scheduling.
│       └── HealthChecks.php    DB, cache, and cron health checks.
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
Perimetre\Core\Plugin              →  src/Plugin.php
Perimetre\Core\Blocks\Registry     →  src/Blocks/Registry.php
Perimetre\Core\Blocks\AcfBlock     →  src/Blocks/AcfBlock.php
Perimetre\Core\Blocks\NativeBlock  →  src/Blocks/NativeBlock.php
Perimetre\Core\GraphQL\Registry    →  src/GraphQL/Registry.php
Perimetre\Core\Status\Settings     →  src/Status/Settings.php
Perimetre\Core\Status\Endpoint     →  src/Status/Endpoint.php
Perimetre\Core\Status\HealthChecks →  src/Status/HealthChecks.php
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
                ['param' => 'block', 'operator' => '==', 'value' => $this->get_name()],
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

Returns ACF sub-fields for a single CTA: a link field and a variant select.

```php
perimetre_cta_fields(
    string $prefix = 'cta',
    array  $variants = ['default' => 'Default', 'primary' => 'Primary', 'secondary' => 'Secondary'],
): array
```

### `perimetre_cta_group_fields`

Returns a tab + repeater of CTAs, ready to spread into a field group's `fields` array.

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

| Setting | Default | Description |
|---|---|---|
| Status enabled | Off | Enables the endpoint. When off, the URL returns a 404. |
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
  "cron_last_run": "2026-04-02T11:00:00Z",
  "wp_version": "6.7",
  "php_version": "8.3.0",
  "plugin_version": "1.3.0"
}
```

If any check fails, `status` becomes `"error"`, a `"failing"` array lists the failing checks, and the response returns HTTP 500.

### Health checks

| Check | Method |
|---|---|
| `db` | `$wpdb->check_connection()` |
| `cache` | `wp_using_ext_object_cache()` + set/get probe. Returns `"disabled"` when no external cache is active. |
| `cron_last_run` | Recorded by an hourly cron event. `null` if never run. |

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

**1.3.0**

Update this when bumping the version in `perimetre-core.php`.

---

## Changelog

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
