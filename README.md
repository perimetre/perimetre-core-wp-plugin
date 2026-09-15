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
- Provides configurable outgoing webhooks on post status changes (publish, draft, trash, delete)
- Points the WordPress **Preview** button at a headless frontend, signed, and authenticates the frontend's draft reads as the editor who clicked it

> **Operational features moved out.** The status / health-check endpoint and Helm portal Remote Login were split into a separate plugin, [Perimetre WP Tools](https://github.com/perimetre/perimetre-wp-tools-plugin), so they can be deployed on any site (including standard, non-headless client sites) without the block/GraphQL framework. See the [2.0.0 changelog](#changelog).

What it does **not** do:

- Manage third-party plugins or dependencies
- Render block markup on its behalf — `AcfBlock` provides hooks (`render_preview()` / `render_frontend()`) but the markup is the subclass's job
- Contain project-specific logic of any kind

---

## Where This Works

Perimetre Core is designed for headless Perimetre projects but is safe to install on any standard WordPress site. The plugin's surface area is opt-in and the defaults don't change anything about a host site:

- **Webhooks** are off by default. The dispatcher doesn't fire until the master toggle is on and a URL is configured.
- **Frontend preview** is off by default, twice over: it needs a preview secret AND a project-supplied frontend URL (see [Frontend Preview](#frontend-preview)). Without both, the Preview button behaves exactly as WordPress shipped it.
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
│   ├── Preview/
│   │   ├── Settings.php        Preview secret field, on the Perimetre Core options page.
│   │   ├── PreviewUrl.php      Builds and signs the frontend preview link; filters preview_post_link.
│   │   ├── TokenAuth.php       Accepts that signature back as the GraphQL credential.
│   │   └── EditorPreviewPane.php  Side-by-side preview sidebar in the block editor.
│   └── Webhook/
│       ├── Settings.php        Webhooks options page (ACF-backed).
│       └── Dispatcher.php      Post status hooks and outgoing HTTP dispatch.
├── assets/
│   ├── editor.css              Styles the AcfBlock editor previews.
│   └── editor-preview.js       The side-by-side preview sidebar (plain wp.* globals, no build step).
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

## Webhooks

Perimetre Core can fire outgoing HTTP POST requests when posts change status. Configure it under **Settings → Perimetre Core** (requires ACF Pro).

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
  "taxonomies_removed": {
    "category": ["archive"]
  },
  "timestamp": 1713000000,
  "old_status": "draft",
  "new_status": "publish"
}
```

- `permalink` — relative URL path, useful for on-demand revalidation (e.g. Next.js `revalidatePath`)
- `language` — WPML language code when WPML is active, `null` otherwise
- `taxonomies` — the post's terms, keyed by taxonomy slug, so the frontend can revalidate archive pages. Includes taxonomies registered `'public' => false` as long as they are reachable some other way (`publicly_queryable`, `show_in_rest` or `show_in_graphql`) — which is how a headless project registers them. Filter with `perimetre_core_webhook_reportable_taxonomy`.
- `taxonomies_removed` — terms the post was removed from during this save, same shape. **Present only when something was removed.** A frontend that caches an archive page per term needs this: the term is absent from `taxonomies` precisely because it was removed, so nothing else tells the frontend that that archive must drop the post, and it would keep serving it until its cache expired on time.
- `old_status` / `new_status` — included on status transitions, omitted on permanent deletes

Post webhooks are built and sent on `shutdown`, not the moment the post is saved. That is what lets the payload describe the post's final state: the REST controller (the block editor, and any `show_in_rest` post type) updates the post *before* applying its terms, so a payload built at `transition_post_status` time reports the terms as they were before the save. It also means a request that saves the same post several times — an ACF write that re-saves, a `save_post` hook calling `wp_update_post()` — sends ONE webhook rather than several identical ones.

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

## SEO — Meta Descriptions from ACF Content

Yoast cannot see ACF. Its `%%excerpt%%` variable falls back to `post_content` when the excerpt is empty, and on an ACF-block page `post_content` holds only the block delimiter comments — which `wp_strip_all_tags()` removes, leaving nothing. Classic-editor CPTs whose text lives in ACF postmeta have the same problem from the other side. Either way, posts without a hand-written meta description ship without one.

Core registers **`%%perimetre_excerpt%%`**, which reads the prose out of wherever ACF actually put it: it walks the block tree (or the post's ACF fields), keeps the `text` / `textarea` / `wysiwyg` values in reading order, and trims the result to meta-description length.

### Setup — one step per content type

In **SEO → Settings → Content types → \<type\> → Meta description**, set the template to `%%perimetre_excerpt%%`.

Nothing happens until you do — a registered variable is inert until a template references it. Yoast is not a Core dependency; without it the whole feature no-ops.

### What it guarantees

- **A description an editor typed always wins.** Yoast only consults the type's template when the post's own meta description is empty.
- **It is a superset of `%%excerpt%%`**, never worse. Content ACF never touched (core blocks, Classic-editor HTML) falls back to the same text the built-in variable would have produced. This matters because a Yoast template *concatenates* its variables rather than picking the first non-empty one — a template cannot express a fallback, so the variable has to. Safe as a template's sole value.
- **Nothing is stored.** The value is computed when Yoast resolves a description and discarded. It tracks the content with no save hook and no staleness, applies to existing content with no backfill, and never writes — so it cannot fire a second `transition_post_status` and double-trigger a frontend rebuild.
- **Cost is flat**, 0.02–0.26 ms per post. `parse_blocks()` duplicates a parse the same GraphQL request already performs for `editorBlocks`; ACF field types resolve through a request-static map (important, because ACFML filters `acf/load_field`); the postmeta path avoids `get_fields()` and the `acf/format_value` queries it triggers; and collection stops once there is enough text.

### It does not replace `%%cf_<field>%%`

Yoast's built-in custom-field variable reads flat postmeta directly, so for a post type with one field that always describes it, `%%cf_hero_description%%` is the better template — deterministic, and no sweep to reason about. `%%perimetre_excerpt%%` is for what that cannot cover: ACF **block** content, which lives as JSON in `post_content` and is unreachable from postmeta, and post types with no single obvious field. Pick per content type; they coexist.

### Auditing a project

Before wiring the variable into a template on a project, see what it would produce there. Every project's field groups are its own, and this calls the resolver directly — no configuration needed, nothing written:

```bash
wp perimetre:seo-excerpt-audit --post_type=page
wp perimetre:seo-excerpt-audit --post_type=product --limit=50
wp perimetre:seo-excerpt-audit --post_type=page --empty          # only posts producing nothing
wp perimetre:seo-excerpt-audit --post_type=page --timings-only   # cost, without the text
```

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `perimetre_core_seo_excerpt_field_types` | `['text', 'textarea', 'wysiwyg']` | Which ACF field types count as prose |
| `perimetre_core_seo_excerpt_max_length` | `156` (`80` for `ja`) | Character budget, mirroring Yoast's own |
| `perimetre_core_seo_excerpt_excluded_blocks` | `[]` | Block names to skip, e.g. `acf/project-bestsellers` |

---

## Frontend Preview

WordPress's **Preview** button opens `preview_post_link` — the WP permalink, which on a headless site renders nothing useful. This points it at the frontend's own preview route instead, signed so the frontend can trust it, and hands the frontend a credential to read the draft with:

```
{frontend}/preview/{post type}/{post id}/?locale=<code>&exp=<unix s>&user=<editor id>&token=<hex hmac>
```

`token = HMAC-SHA256("{id}|{type}|{locale}|{exp}|{user}", preview secret)`, 12-hour expiry.

### How the credential works

The signed payload does double duty. The frontend verifies the signature (so a stranger cannot render drafts), then forwards **the same payload + signature** to WPGraphQL as an `X-Preview-Auth` header. `Preview\TokenAuth` re-verifies it against the same secret and tells WordPress the current user is the `user` baked into the token — the editor who clicked Preview.

So the draft is read with that editor's own capabilities. There is **no service account, no Application Password, and no shared "preview user"** anywhere in the flow, and a leaked link grants read-as-that-editor for at most 12 hours rather than forever.

`TokenAuth` refuses: tampered or expired tokens, users who have since lost `edit_posts`, requests that already authenticated some other way, and anything that is not a GraphQL request. Every refusal is silent — the frontend sees "draft not visible" and renders its 404, never a GraphQL error.

### Setup

1. **Secret** — **Settings → Perimetre Core → Frontend Preview → Preview secret**, or define `PERIMETRE_PREVIEW_SECRET` in `wp-config.php` to keep it out of the database (the constant wins when defined). Generate with `openssl rand -hex 32`. The frontend holds the identical value in its own environment, conventionally `PREVIEW_SECRET`.
2. **Frontend URL** — answer one filter from Project Core. Returning `null` for a post is how you opt out the types your frontend has no route for:

```php
add_filter('perimetre_core_preview_frontend_url', [self::class, 'previewFrontendUrl'], 10, 2);

public static function previewFrontendUrl(?string $url, WP_Post $post): ?string
{
    // Only the post types the frontend actually renders.
    return MySettings::templateFor($post->post_type) === null
        ? null
        : MySettings::baseUrl();
}
```

3. **Frontend route** — implement `/preview/{type}/{id}/`, recompute the HMAC over `id|type|locale|exp|user`, and send the payload back as `X-Preview-Auth` on its GraphQL reads. Mark it `noindex` and keep it out of the sitemap.

Until both 1 and 2 are in place nothing changes: no signed links, no editor pane, and `TokenAuth` authenticates nothing.

### The editor pane

`Preview\EditorPreviewPane` adds a **Site preview** sidebar to the block editor holding an `<iframe>` of the signed link, widened while open so it reads side-by-side, plus an entry in the Preview dropdown.

**It refreshes when you leave a field or a block**, rather than waiting out Gutenberg's autosave interval. The frontend can only ever render what is in the database — it reads the newest revision — so "refresh the preview" necessarily means "autosave first, then reload". Two listeners cover the entire editor without touching a single block:

- a `core/block-editor` store subscription, which fires when the selected block changes — that *is* "the user left this block", and being store state it works even when the canvas is iframed, where a DOM event would not reach us;
- one delegated `focusout` on the document (capture), which covers the ACF fields — in Blocks v3 they live in the block sidebar and the slide-out modal, i.e. in this document rather than the canvas iframe.

Both debounce into a single `autosave()` (600ms), skipped when the post is not dirty, already saving, or save-locked by another plugin. This runs only while the pane is open, so an editor who never opens the preview keeps WordPress's stock autosave cadence.

What autosave means per status decides what the preview can show. For a **draft you own**, WordPress writes straight to the post, so the pane shows your edits. For a **published** post it writes a separate autosave revision, which is what the preview reads — the live page is untouched. For a post type with no `revisions` support whose content is post meta (an ACF-only CPT on the Classic editor), there is nothing to autosave and the preview can only ever show the last saved state.

Only block-editor post types get the pane. A CPT registered `show_in_rest => false` uses the Classic editor, where there is nothing to add a sidebar to — its Preview button still opens the signed link in a new tab.

Because the token travels in the URL and not a cookie, the frame works in every browser (Safari included) and across whatever domains the CMS and frontend live on — a local `next dev` included.

### Coexisting with a project that already has its own preview

Core auto-updates on every client site, so this subsystem lands on projects that already rolled their own preview (oiq-placepourtoi is one). It does not disturb them, because it is gated on things only a deliberate opt-in satisfies:

- **No secret → no link.** `PreviewUrl::for()` returns `null`, so the `preview_post_link` filter returns the incoming URL untouched and the project's own filter (at the usual priority 10) is what the editor sees. Verified: with the secret removed, `get_preview_post_link()` falls straight through to the project's permalink template.
- **No `perimetre_core_preview_frontend_url` listener → no link**, even if someone fills the secret field in. That filter is the hard gate: a project cannot satisfy it by accident.
- **No link → no pane.** `EditorPreviewPane::enqueue()` bails on the same `null`, so the editor script is never enqueued and cannot collide with a project's own sidebar.
- **No secret → no authentication.** `TokenAuth` returns the incoming user before looking at any header, so a project's own `determine_current_user` filter keeps working.

The one visible change on such a site is a new **Preview secret** field on Settings → Perimetre Core, alongside whatever field the project already has elsewhere. Harmless, but worth knowing before someone fills in both.

**Migrating a project onto this subsystem** means: delete the project's own `Preview\*` classes and its `preview_post_link` filter, answer `perimetre_core_preview_frontend_url`, move the shared secret to Core's field (or the `PERIMETRE_PREVIEW_SECRET` constant), and point the frontend at `/preview/{type}/{id}/?locale=…`. A frontend whose preview route has a different shape — a locale path prefix, say — keeps it by filtering `perimetre_core_preview_url` instead of changing its routes.

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `perimetre_core_preview_frontend_url` | `null` (feature off) | The frontend origin for this post, or `null` for no preview. **Required.** |
| `perimetre_core_preview_locale` | WPML language code, else the site locale's language | The language code baked into the link |
| `perimetre_core_preview_url` | the built URL | Last-resort rewrite, for a frontend whose route has a different shape (a locale path prefix, say) |

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

**2.3.0**

Update this when bumping the version in `perimetre-core.php`.

---

## Changelog

### 2.3.0

- **Fixed: `taxonomies` was always empty on headless projects.** The payload only reported taxonomies registered `'public' => true`, but a headless site renders no term archives in WordPress and therefore registers its taxonomies `'public' => false`, exposing them through GraphQL or REST instead. Every product/CPT webhook shipped `"taxonomies": []`, so a frontend keying cache invalidation off those terms silently invalidated nothing and its listing pages stayed stale until they expired on time. A taxonomy is now reported when it is reachable by *any* consumer — `public`, `publicly_queryable`, `show_in_rest` or `show_in_graphql` — which still excludes genuinely internal taxonomies such as ElasticPress's `ep_custom_result`. Override per taxonomy with the new `perimetre_core_webhook_reportable_taxonomy` filter.
- **Added `taxonomies_removed` to post payloads.** Reports the terms a post was just taken out of, captured from `set_object_terms`'s `$old_tt_ids` — the only point at which WordPress still knows the previous terms. Without it, removing a post from a term was invisible downstream: the term is missing from `taxonomies` *because* it was removed, so a frontend caching an archive per term had no signal to drop the post and kept listing it. Adding a term worked; removing one did not. The key is omitted entirely when nothing was removed.
- **Post webhooks are now built and sent on `shutdown`.** Previously the payload was assembled inside `transition_post_status`. The REST controller — the block editor, and any `show_in_rest` post type — updates the post *before* applying its terms, so those payloads reported the terms as they were before the save. Deferring to the end of the request means the payload always describes the post's final state, and it collapses repeated saves of the same post within one request into a single webhook instead of several identical ones. Deletes are unaffected: their payload is still captured while the post exists. No payload-shape change and no project code to update.

### 2.2.0

- **Added headless frontend preview.** WordPress's **Preview** button now opens the frontend's own preview route, signed with a shared secret (`HMAC-SHA256` over `id|type|locale|exp|user`, 12h expiry), and the block editor gains a side-by-side **Site preview** pane that refreshes as soon as you leave a field or a block (one store subscription plus one delegated `focusout` — no per-block code, and only while the pane is open). The signed link doubles as the frontend's GraphQL credential: `Preview\TokenAuth` verifies it on the way back in and runs the read as the editor who clicked Preview, so drafts resolve under that editor's own capabilities — no service account, no Application Password, no shared preview user, and a leaked link expires. Stateless on purpose (token in the URL, no cookie), so the iframe works in Safari and across separate CMS/frontend domains. Off until a preview secret is set **and** Project Core answers `perimetre_core_preview_frontend_url`; the Preview button is untouched otherwise. See [Frontend Preview](#frontend-preview).

### 2.1.0

- **Added `%%perimetre_excerpt%%`, a Yoast replacement variable that builds a meta description from ACF block and field content.** Yoast cannot read ACF: `%%excerpt%%` falls back to `post_content`, which on an ACF-block page is only block delimiter comments that `wp_strip_all_tags()` removes — so those pages shipped with no meta description at all. The new variable walks the block tree (and ACF postmeta for Classic-editor types), keeps `text` / `textarea` / `wysiwyg` values in reading order, and trims to Yoast's own 156-character budget. It is a superset of `%%excerpt%%` — core blocks and Classic HTML fall back to the same text the built-in produces — so it is safe as a template's sole value. Nothing is stored: no save hook, no staleness, no backfill, and no extra `transition_post_status` to double-trigger a frontend rebuild. A hand-written description always wins. Opt in per content type at **SEO → Settings → Content types → \<type\> → Meta description**; inert until you do, and a no-op when Yoast is absent. See [SEO — Meta Descriptions from ACF Content](#seo--meta-descriptions-from-acf-content).
- **Added `wp perimetre:seo-excerpt-audit`.** Reports the description each post of a type would get and what generating it costs, calling the resolver directly so nothing has to be configured first. Run it when adopting the variable on a project — the block walker follows ACF's documented storage shape, but every project's field groups are its own.

### 2.0.7

- **Fixed the block editor warning `perimetre-core-editor-css was added to the iframe incorrectly. Please use block.json or enqueue_block_assets to add styles to the iframe.`** `assets/editor.css` was enqueued on `enqueue_block_editor_assets`, which targets the document *around* the editor canvas. Since WP 6.3 the canvas is an iframe, so the editor has to copy those styles in and logs this warning. The stylesheet now hooks `enqueue_block_assets` — the hook whose styles WordPress loads inside the iframe — with an `is_admin()` guard so nothing loads on the front end. Cosmetic-only change: the same rules apply to the same `.perimetre-block-preview` markup, and no project code needs to change.
- **Typographic polish on the block preview card.** The card never declared a `font-family`, so inside the iframe it fell back to the browser default (serif) — headless themes ship no editor styles for the canvas to inherit. It now uses the WordPress admin UI font, applied only to the card's own parts (via `--perimetre-preview-font`) so real author content in an InnerBlocks slot keeps looking like content. Also: a hairline under the header row, more tracking and a lighter weight on the uppercase field labels, tabular figures on values so `N items` counts don't jitter, a softer border with a 1px shadow, and a hairline frame on image thumbnails.

### 2.0.6

- **Fixed `options.saved` never firing for non-default-language Site Options saves.** ACF appends the WPML language code to the options post id on secondary languages (`options` → `options_fr`), both in core (`acf_get_valid_post_id`) and via project `acf/validate_post_id` filters, so the strict `!== 'options'` guard silently dropped every localized save. The handler now accepts the bare `options` id and any `options_<lang>` variant.

### 2.0.5

- **Fixed `options.saved` never firing for any options page, in any language.** `on_options_save()` gated on `function_exists('acf_get_current_screen')`, but that function does not exist in ACF (verified against ACF Pro 6.8) — the guard was always false, so the handler returned before dispatching. Replaced with `current_options_page_slug()`, which reads the saved page from the request (ACF options pages POST to `?page={menu_slug}`) and validates it against `acf_get_options_pages()`, also rejecting unrelated admin screens. All other guards and the payload's `options_page` slug are unchanged.

### 2.0.4

- **Fix the webhook silently never firing for non-default-language content under WPML.** On a WPML site, saving a post in a secondary language (e.g. French on an `en`/`fr` site) never dispatched the webhook, while the default language worked fine. Webhook config lives on an ACF options page, and ACF options — including the URL repeater and enable toggle — are served **per-language at runtime**, so reading them while WordPress is in a non-default language context returned an empty/disabled config even though the settings page displayed the correct values. `Webhook\Settings::get_settings()` now reads all options under WPML's **default** language (via the new `wpml_default_language`-based `force_default_language()` helper, restored in a `finally`), so a single configuration fires for every language. The payload's `language` field is unaffected — it is still derived from the saved post via `wpml_post_language_details`, so receivers continue to get `language: "fr"` for French saves. No-op when WPML is inactive.

### 2.0.3

- **Fixed a fatal `TypeError` that blocked deleting any taxonomy term** (Product Categories, tags, categories, …). `Webhook\Dispatcher::cache_menu_before_delete()` runs on the `pre_delete_term` action, which passes `(int $term_id, string $taxonomy)` — but the callback declared its second argument as `int`, so the taxonomy slug string aborted the request under `declare(strict_types=1)` before the term could be deleted. The bug also defeated its own purpose: it was meant to cache a `nav_menu` term before deletion, but fataled before caching. The callback now uses the correct `(int $term_id, string $taxonomy)` signature, is registered as an action (not a filter), and caches only `nav_menu` terms.

### 2.0.2

- **Webhooks settings are now the single Settings → Perimetre Core entry.** 2.0.0–2.0.1 briefly surfaced them under a separate "Webhooks" menu item; they're now consolidated under one "Perimetre Core" entry (Core's only admin surface). No option-key or behavior changes — purely the menu label/placement.

### 2.0.1

- Maintenance release. Bumped the release workflow's `action-gh-release` to v3 (Node 24 runtime). No functional or plugin-code changes.

### 2.0.0

- **Split the operational features into a separate plugin.** The status / health-check endpoint (`Status\*`) and Helm portal Remote Login (`RemoteLogin\*`), along with the shared `Admin\Tabs` strip, moved to [Perimetre WP Tools](https://github.com/perimetre/perimetre-wp-tools-plugin). Core now carries only the dev framework — block registration, GraphQL conventions, and webhooks — for custom headless builds. WP Tools is ACF- and WPGraphQL-free and can be deployed on any site, including standard non-headless client sites. The two plugins are independent and can be installed side by side; option keys are unchanged, so existing Status/Remote Login settings carry over automatically. **Note:** the Remote Login REST namespace changed from `perimetre-core/v1` to `perimetre-wp-tools/v1` — see the WP Tools changelog for the portal-coordination requirement.
- **Webhooks moved off the unified Status/Remote-Login tab strip** (which was removed with those modules). The ACF option keys are unchanged, so existing webhook configuration is preserved. (See 2.0.2 for its final menu placement.)
- Fixed the long-standing mismatch between the plugin header version and the `PERIMETRE_CORE_VERSION` constant.

### 1.15.0

- **Purge the WPGraphQL cache when an ACF options page is saved.** WPGraphQL Smart Cache invalidates by content "node" (post/term/menu), but ACF options pages have no node — so cached GraphQL responses that read option values (global nav CTAs, site-wide settings, …) stayed stale until the cache TTL lapsed, making option edits look "stuck" on the headless frontend even after the `options.saved` revalidation webhook fired. `Webhook\Dispatcher::on_options_save()` now fires `do_action('wpgraphql_cache_purge_all')` before dispatching the webhook, so the frontend's post-webhook re-fetch reads fresh data. No-op when WPGraphQL Smart Cache isn't installed (safe on standard WP sites); opt out or customize via the `perimetre_core_purge_graphql_cache_on_options` filter. Note: this clears the object-cache layer — `?edgeCache=` / Varnish responses remain TTL-only invalidated (see 1.13.0), so keep runtime edge-cache TTLs short where option staleness is user-visible.

### 1.14.1

- **Friendlier headless editor preview.** Since v3 made the preview the only on-canvas surface, the default `AcfBlock::render_preview()` now renders a **content-summary card** — block icon + title, then an auto-generated snapshot of the block's filled ACF fields (`get_field_objects()`): truncated text, image thumbnails, Yes/No toggles, link labels, and "N items" counts. Unsupported and empty fields are omitted; blocks with nothing entered show a muted "add content" hint. Requires no per-block code. Customize via the new optional `AcfBlock::get_preview_summary()` hook (return a curated `label => value` array), or override `render_preview()` for full control. Editor-only — frontend/GraphQL output is unaffected.

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
