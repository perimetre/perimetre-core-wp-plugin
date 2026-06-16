<?php

declare(strict_types=1);

namespace Perimetre\Core\Blocks;

/**
 * Abstract base class for all ACF blocks.
 *
 * ACF blocks are registered server-side in PHP. The server has full knowledge
 * of the block's fields and types, which produces typed GraphQL output via
 * WPGraphQL for ACF.
 *
 * Usage — extend this class in Perimetre Core or Project Core:
 *
 *   class HeroBlock extends AcfBlock
 *   {
 *       protected function get_name(): string
 *       {
 *           return 'perimetre/hero';
 *       }
 *
 *       protected function get_title(): string
 *       {
 *           return __('Hero', 'perimetre-core');
 *       }
 *
 *       protected function get_graphql_field_name(): string
 *       {
 *           return 'hero';
 *       }
 *
 *       protected function get_description(): string
 *       {
 *           return __('Full-width hero section with heading, subheading, and CTA.', 'perimetre-core');
 *       }
 *
 *       protected function get_category(): string
 *       {
 *           return 'perimetre';
 *       }
 *
 *       protected function register_fields(): void
 *       {
 *           acf_add_local_field_group([
 *               'key'                => 'group_perimetre_hero',
 *               'title'              => 'Hero',
 *               'show_in_graphql'    => 1,
 *               'graphql_field_name' => $this->get_graphql_field_name(),
 *               'location'           => [[
 *                   ['param' => 'block', 'operator' => '==', 'value' => $this->get_acf_name()],
 *               ]],
 *               'fields' => [ ... ],
 *           ]);
 *       }
 *   }
 *
 * Then register it:
 *
 *   Registry::register_block(HeroBlock::class);
 *
 * To enable nested children (InnerBlocks), additionally override
 * get_inner_blocks_template() — see the method's docblock for details and
 * the get_allowed_blocks() / get_template_lock() companions for restricting
 * insertion or locking shape.
 */
abstract class AcfBlock
{
    /**
     * The block name in `namespace/block-name` format — the pre-transform,
     * human-facing identifier.
     *
     * Must follow `perimetre/block-name` or `project-slug/block-name`.
     * This is the value passed to `acf_register_block_type()`, which
     * transforms it (lowercases, runs `acf_slugify()`, prepends `acf/`)
     * before storing. For the post-transform form — required by ACF
     * `block` location rules and anywhere WordPress looks up the block
     * by its registered name — use `get_acf_name()`.
     */
    abstract protected function get_name(): string;

    /**
     * The block name as ACF stores it internally after registration.
     *
     * `acf_register_block_type()` rewrites the block name before storing it:
     * it lowercases the name, runs `acf_slugify()` (which replaces `/`, `_`,
     * `-`, and spaces with a single `-`), and prepends `acf/`. So a block
     * whose `get_name()` returns `'project-slug/hero'` is stored under
     * `'acf/project-slug-hero'`.
     *
     * Use this method wherever WordPress or ACF needs the post-transform
     * form — most commonly the `value` of an ACF field-group `block`
     * location rule, so the field group binds to the registered block:
     *
     *     acf_add_local_field_group([
     *         'location' => [[
     *             ['param' => 'block', 'operator' => '==', 'value' => $this->get_acf_name()],
     *         ]],
     *         // ...
     *     ]);
     *
     * Do NOT use this for `acf_register_block_type(['name' => ...])` —
     * ACF does the transform itself and expects the pre-transform form
     * there. Use `get_name()` for that.
     */
    protected function get_acf_name(): string
    {
        return 'acf/' . acf_slugify($this->get_name());
    }

    /**
     * The human-readable block title shown in the block inserter.
     */
    abstract protected function get_title(): string;

    /**
     * The GraphQL field name for this block's ACF field group.
     * Used as graphql_field_name in acf_add_local_field_group().
     * Must be camelCase: 'hero', 'featuredPosts', etc.
     */
    abstract protected function get_graphql_field_name(): string;

    /**
     * A short description of the block shown in the block inserter.
     */
    abstract protected function get_description(): string;

    /**
     * The block category slug. Defaults to 'perimetre'.
     * Override in Project Core blocks to use a project-specific category.
     */
    protected function get_category(): string
    {
        return 'perimetre';
    }

    /**
     * The block icon. Accepts a Dashicon slug or an SVG string.
     *
     * @see https://developer.wordpress.org/resource/dashicons/
     */
    protected function get_icon(): string
    {
        return 'screenoptions';
    }

    /**
     * Optional editor-only notice rendered above the InnerBlocks slot.
     * Useful for blocks whose ACF fields live only in the Block sidebar —
     * a short reminder like "don't forget to fill in the heading data in
     * the Block panel" keeps that surface discoverable.
     *
     * Return null (default) to skip the notice. Plain text only; HTML
     * is escaped before output. Only consulted when
     * get_inner_blocks_template() is non-null.
     */
    protected function get_editor_notice(): ?string
    {
        return null;
    }

    /**
     * Optional curated content summary for the editor preview card.
     *
     * The default editor preview (see render_preview()) auto-summarizes the
     * block's ACF fields with no per-block code. Override this to control
     * exactly which rows appear instead, without re-implementing the card
     * chrome: return an associative `label => value` array. Both label and
     * value are output as raw HTML and are NOT escaped — callers are
     * responsible for escaping any user-derived content (e.g. wrap values in
     * esc_html()).
     *
     * Return null (default) to keep the automatic ACF-field summary. For full
     * control over the preview markup, override render_preview() instead.
     *
     * @param array<string, mixed> $block
     * @return array<string, string>|null
     */
    protected function get_preview_summary(array $block): ?array
    {
        unset($block);

        return null;
    }

    /**
     * Additional block supports configuration.
     *
     * Note: when get_inner_blocks_template() returns non-null, `'jsx' => true`
     * is automatically merged into the supports array at registration time —
     * subclasses with InnerBlocks do not need to declare it manually.
     *
     * @return array<string, mixed>
     */
    protected function get_supports(): array
    {
        return [
            'align'  => false,
            'anchor' => true,
        ];
    }

    /**
     * Inner-blocks template, in WordPress's `[name, attrs, innerBlocks]`
     * shape. Return non-null to enable an InnerBlocks slot for this block.
     *
     * Returning a non-null value merges `'jsx' => true` into
     * `get_supports()` so the render output is parsed as JSX in the
     * editor and the `<InnerBlocks />` token reaches the canvas. Blocks
     * are always registered as ACF Blocks v3 (`acf_block_version => 3`),
     * which maps `<InnerBlocks />` to ACF's wrapper component (honoring
     * the `template`, `templateLock`, and `allowedBlocks` JSX
     * attributes).
     *
     * The shape mirrors WordPress's block-template format:
     *
     *     return [
     *         ['core/columns', [], [
     *             ['core/column', [], []],
     *             ['core/column', [], []],
     *         ]],
     *     ];
     *
     * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-templates/
     *
     * @return array<int, array<int, mixed>>|null
     */
    protected function get_inner_blocks_template(): ?array
    {
        return null;
    }

    /**
     * Block names that may be inserted into this block's InnerBlocks slot.
     * Null means any registered block is allowed.
     *
     * Block names use the post-transform form (`acf/project-slug-hero`,
     * `core/paragraph`, etc.), since that's what the editor's inserter
     * compares against.
     *
     * Only consulted when get_inner_blocks_template() is non-null.
     *
     * @return list<string>|null
     */
    protected function get_allowed_blocks(): ?array
    {
        return null;
    }

    /**
     * Lock policy applied to the InnerBlocks slot. One of:
     *
     *   - false         — no lock (default)
     *   - 'all'         — children can't be added, removed, or moved
     *   - 'insert'      — children can't be added or removed (but can move)
     *   - 'contentOnly' — only block content is editable, no structure changes
     *
     * Note: WordPress propagates a parent's templateLock down to nested
     * InnerBlocks instances unless those instances (or their parent block
     * attributes) override it. When seeding a template with nested blocks
     * (e.g. core/columns) and you want children to remain editable, set
     * `templateLock => false` as a block attribute in the inner template
     * entries to break the propagation.
     *
     * Only consulted when get_inner_blocks_template() is non-null.
     */
    protected function get_template_lock(): false|string
    {
        return false;
    }

    /**
     * ACF render callback. Dispatches to render_preview() in the editor
     * and render_frontend() on the public page.
     *
     * Visibility is `public` (not `protected`) because ACF's
     * `acf_render_block()` calls `is_callable($block['render_callback'])`
     * from outside the class scope; `[$this, 'render']` reports as
     * not-callable while the method is protected, so ACF silently skips
     * the callback and the editor canvas stays empty (the
     * `<InnerBlocks />` token never reaches the editor in JSX-enabled
     * blocks). Subclasses overriding this method must keep the same
     * visibility — PHP forbids narrowing.
     *
     * Subclasses generally don't need to override `render()` itself —
     * override `render_preview()` for editor chrome and `render_frontend()`
     * for the public page output. Existing subclasses that already
     * override `render()` keep working unchanged.
     *
     * @param array<string, mixed> $block
     */
    public function render(array $block, string $content, bool $is_preview, int $post_id): void
    {
        if ($is_preview) {
            $this->render_preview($block, $content, $post_id);
            return;
        }

        $this->render_frontend($block, $content, $post_id);
    }

    /**
     * Editor-preview output. The default renders a content-summary card —
     * the block icon and title, followed by a snapshot of the block's
     * filled ACF fields (see render_preview_summary()) — so authors can
     * tell blocks apart and confirm their content on a headless site that
     * has no real frontend rendering. When an InnerBlocks template is
     * configured, the `<InnerBlocks />` JSX slot is appended below the card.
     *
     * Three ways to customize, none required (see also get_preview_summary()):
     *   1. Do nothing — inherit the automatic field summary.
     *   2. Override get_preview_summary() to curate the summary rows.
     *   3. Override this method for full control over the preview markup.
     *
     * @param array<string, mixed> $block
     */
    protected function render_preview(array $block, string $content, int $post_id): void
    {
        unset($content, $post_id);

        $template = $this->get_inner_blocks_template();
        $classes  = 'perimetre-block-preview';
        if ($template !== null) {
            $classes .= ' perimetre-block-preview--inner-blocks';
        }

        echo '<div class="' . esc_attr($classes) . '">';

        $this->render_preview_header();

        $notice = $this->get_editor_notice();
        if (is_string($notice) && $notice !== '') {
            echo '<p class="perimetre-block-preview__notice">' . esc_html($notice) . '</p>';
        }

        $this->render_preview_summary($block);

        if ($template !== null) {
            $this->emit_inner_blocks_token($template);
        }

        echo '</div>';
    }

    /**
     * Render the preview card header — the block icon followed by its title.
     * The icon is either a Dashicon slug (rendered as a dashicons span) or a
     * raw inline SVG string, mirroring get_icon()'s contract.
     */
    protected function render_preview_header(): void
    {
        $icon = $this->get_icon();

        echo '<div class="perimetre-block-preview__head">';

        if (str_starts_with(ltrim($icon), '<svg')) {
            echo '<span class="perimetre-block-preview__icon">' . $icon . '</span>';
        } elseif ($icon !== '') {
            echo '<span class="perimetre-block-preview__icon dashicons dashicons-'
                . esc_attr($icon) . '"></span>';
        }

        echo '<span class="perimetre-block-preview__title">'
            . esc_html($this->get_title()) . '</span>';
        echo '</div>';
    }

    /**
     * Render the content summary: a definition list of label => value rows.
     * Uses get_preview_summary() when a subclass curates one; otherwise
     * auto-summarizes the block's ACF fields via get_field_objects(),
     * skipping empty values and field types summarize_field() doesn't
     * support. Falls back to a muted hint when there is nothing to show.
     *
     * @param array<string, mixed> $block
     */
    protected function render_preview_summary(array $block): void
    {
        $rows   = [];
        $custom = $this->get_preview_summary($block);

        if (is_array($custom)) {
            $rows = $custom;
        } elseif (function_exists('get_field_objects')) {
            $fields = get_field_objects();
            if (is_array($fields)) {
                foreach ($fields as $field) {
                    $value = $this->summarize_field($field);
                    if ($value !== null) {
                        $rows[(string) $field['label']] = $value;
                    }
                }
            }
        }

        if ($rows === []) {
            echo '<p class="perimetre-block-preview__hint">'
                . esc_html__('No content yet — add content in the Block panel →', 'perimetre-core')
                . '</p>';
            return;
        }

        echo '<dl class="perimetre-block-preview__fields">';
        foreach ($rows as $label => $value) {
            echo '<dt>' . esc_html((string) $label) . '</dt>';
            echo '<dd>' . $value . '</dd>';
        }
        echo '</dl>';
    }

    /**
     * Summarize a single ACF field object for the preview card. Handles the
     * common field types and returns null for everything else (and for empty
     * values) so unsupported/unfilled fields are omitted. The returned string
     * may contain HTML (e.g. an image thumbnail); text values are escaped here.
     *
     * @param array<string, mixed> $field
     */
    protected function summarize_field(array $field): ?string
    {
        $type  = (string) ($field['type'] ?? '');
        $value = $field['value'] ?? null;

        // true_false is always shown (a false value is meaningful), so it is
        // resolved before the empty-value guard below.
        if ($type === 'true_false') {
            return $value
                ? esc_html__('Yes', 'perimetre-core')
                : esc_html__('No', 'perimetre-core');
        }

        if ($value === null || $value === '' || $value === [] || $value === false) {
            return null;
        }

        switch ($type) {
            case 'text':
            case 'textarea':
            case 'email':
            case 'url':
            case 'number':
            case 'range':
                return esc_html($this->truncate((string) $value));

            case 'wysiwyg':
                return esc_html($this->truncate(wp_strip_all_tags((string) $value)));

            case 'select':
            case 'radio':
            case 'button_group':
                $items = is_array($value) ? $value : [$value];
                $parts = [];
                foreach ($items as $item) {
                    // return_format 'array' yields ['label' => .., 'value' => ..] entries.
                    $parts[] = is_array($item)
                        ? (string) ($item['label'] ?? $item['value'] ?? '')
                        : (string) $item;
                }
                $parts = array_filter($parts, static fn(string $p): bool => $p !== '');
                return $parts === [] ? null : esc_html($this->truncate(implode(', ', $parts)));

            case 'image':
                return $this->summarize_image($value);

            case 'link':
                if (is_array($value)) {
                    $label = $value['title'] ?? ($value['url'] ?? '');
                    return $label === '' ? null : esc_html($this->truncate((string) $label));
                }
                return esc_html($this->truncate((string) $value));

            case 'repeater':
            case 'relationship':
                if (is_array($value)) {
                    $count = count($value);
                    /* translators: %d: number of items. */
                    return esc_html(sprintf(_n('%d item', '%d items', $count, 'perimetre-core'), $count));
                }
                return null;

            default:
                return null;
        }
    }

    /**
     * Render a small thumbnail for an image field value, handling ACF's three
     * return formats: array (sizes['thumbnail'] or ID), attachment ID, or URL.
     *
     * @param mixed $value
     */
    protected function summarize_image($value): ?string
    {
        if (is_array($value)) {
            $url = $value['sizes']['thumbnail'] ?? ($value['url'] ?? '');
            if ($url === '' && isset($value['ID'])) {
                return $this->summarize_image((int) $value['ID']);
            }
            $alt = (string) ($value['alt'] ?? '');
            return $url === '' ? null : $this->image_tag((string) $url, $alt);
        }

        if (is_numeric($value)) {
            $tag = wp_get_attachment_image((int) $value, 'thumbnail', false, [
                'class' => 'perimetre-block-preview__thumb',
            ]);
            return $tag === '' ? null : $tag;
        }

        if (is_string($value) && $value !== '') {
            return $this->image_tag($value, '');
        }

        return null;
    }

    /**
     * Build an escaped <img> tag for the preview thumbnail.
     */
    private function image_tag(string $url, string $alt): string
    {
        return '<img class="perimetre-block-preview__thumb" src="' . esc_url($url)
            . '" alt="' . esc_attr($alt) . '" />';
    }

    /**
     * Truncate a plain-text summary value to keep the preview card compact.
     */
    private function truncate(string $value, int $length = 80): string
    {
        $value = trim($value);
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($value, 0, $length, '…');
        }
        return strlen($value) > $length ? substr($value, 0, $length - 1) . '…' : $value;
    }

    /**
     * Public-frontend output. The default emits nothing for plain blocks
     * and the bare `<InnerBlocks />` JSX token for InnerBlocks-enabled
     * blocks (so ACF v2 expands the children on the public page).
     *
     * On a headless site this output isn't visited — leave the default.
     * On a standard WordPress site, override this method with real markup
     * (typically using `get_field()` calls or by including a
     * `template-parts/blocks/<slug>.php` file). Subclasses with
     * InnerBlocks should call `$this->emit_inner_blocks_token()` from
     * their override so child blocks still render at the desired position.
     *
     * @param array<string, mixed> $block
     */
    protected function render_frontend(array $block, string $content, int $post_id): void
    {
        unset($block, $content, $post_id);

        $template = $this->get_inner_blocks_template();

        if ($template === null) {
            return;
        }

        $this->emit_inner_blocks_token($template);
    }

    /**
     * Emit the `<InnerBlocks />` JSX token used by ACF v2 to render the
     * nested children slot. Available to subclasses overriding
     * `render_preview()` or `render_frontend()` so they don't re-derive
     * the encoding.
     *
     * @param array<int, array<int, mixed>> $template
     */
    protected function emit_inner_blocks_token(array $template): void
    {
        $allowed = $this->get_allowed_blocks();
        $lock    = $this->get_template_lock();

        echo '<InnerBlocks';
        echo ' template="' . esc_attr((string) wp_json_encode($template)) . '"';
        if ($allowed !== null && $allowed !== []) {
            echo ' allowedBlocks="' . esc_attr((string) wp_json_encode($allowed)) . '"';
        }
        if ($lock !== false) {
            echo ' templateLock="' . esc_attr($lock) . '"';
        }
        echo ' />';
    }

    /**
     * Register ACF field groups for this block.
     * Called automatically during register().
     * Use acf_add_local_field_group() here.
     */
    abstract protected function register_fields(): void;

    /**
     * Register the block type and its ACF fields.
     * Called by Registry::register() on the 'init' hook.
     */
    final public function register(): void
    {
        if (! function_exists('acf_register_block_type')) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'ACF Pro is required to register block "%s". Install and activate ACF Pro.',
                    esc_html($this->get_name())
                ),
                '1.0.0'
            );
            return;
        }

        $args = [
            'name'              => $this->get_name(),
            'title'             => $this->get_title(),
            'description'       => $this->get_description(),
            'category'          => $this->get_category(),
            'icon'              => $this->get_icon(),
            'supports'          => $this->get_supports(),
            'render_callback'   => [$this, 'render'],
            'show_in_graphql'   => true,
            'api_version'       => 3,
            'acf_block_version' => 3,
        ];

        if ($this->get_inner_blocks_template() !== null) {
            $args['supports']['jsx'] = true;
        }

        acf_register_block_type($args);

        $this->register_fields();
    }
}
