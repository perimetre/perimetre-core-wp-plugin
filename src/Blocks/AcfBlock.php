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
     * The ACF block render mode. One of:
     *
     *   - 'edit'    — always show ACF fields in the canvas; the render
     *                 output (and any `<InnerBlocks />` token in it) is
     *                 hidden.
     *   - 'preview' — always show the render output in the canvas; the
     *                 ACF fields are only reachable from the Block
     *                 sidebar.
     *   - 'auto'    — show fields when the block is selected, render
     *                 output when it isn't (default ACF v2 behavior for
     *                 most blocks).
     *
     * The default adapts to whether InnerBlocks are configured:
     *
     *   - With InnerBlocks: 'preview'. The render template (which holds
     *     the visible `<InnerBlocks />` slot) must always be on canvas
     *     so authors can drag/drop and use the appender. Fields stay
     *     reachable in the right-hand Block sidebar; subclasses can also
     *     override get_editor_notice() to surface a reminder near the
     *     slot.
     *   - Without InnerBlocks: 'edit'. Same behavior as before this
     *     hook existed — fields-only blocks render the field UI in the
     *     canvas and have nothing else to show.
     *
     * Override to force a specific mode (e.g. 'auto' for a field-heavy
     * InnerBlocks block where authors prefer toggling between surfaces).
     */
    protected function get_mode(): string
    {
        return $this->get_inner_blocks_template() !== null ? 'preview' : 'edit';
    }

    /**
     * Optional editor-only notice rendered above the InnerBlocks slot.
     * Useful for blocks in mode='preview' where the ACF fields live only
     * in the right-hand sidebar — a short reminder like "don't forget to
     * fill in the heading data in the Block panel" keeps that surface
     * discoverable.
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
     * Returning a non-null value triggers two automatic registration
     * adjustments so the JSX `<InnerBlocks />` token in the render
     * template actually reaches the editor canvas:
     *
     *   1. `acf_block_version` is bumped to 2 — required so ACF maps
     *      `<InnerBlocks />` to its wrapper component (which honors
     *      `template`, `templateLock`, and `allowedBlocks` JSX
     *      attributes). v1 maps it to a bare wp.blockEditor component
     *      that ignores those attributes.
     *   2. `'jsx' => true` is merged into `get_supports()` so the render
     *      output is parsed as JSX in the editor.
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
     * Editor-preview render callback.
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
     * Default behavior:
     *
     *   - Without an InnerBlocks template configured: emits the legacy
     *     placeholder div labeled with the block name and title.
     *   - With a template configured: emits a `<InnerBlocks />` JSX tag
     *     wrapped in a preview div, encoding `template`, `templateLock`,
     *     and `allowedBlocks` as JSX attributes.
     *
     * Override to provide a richer custom preview. In a headless context
     * this output is only consumed by the block editor — frontend
     * rendering is handled by the headless consumer.
     *
     * @param array<string, mixed> $block
     * @param string $content
     * @param bool $is_preview
     * @param int $post_id
     */
    public function render(array $block, string $content, bool $is_preview, int $post_id): void
    {
        unset($block, $content, $is_preview, $post_id);

        $template = $this->get_inner_blocks_template();

        if ($template === null) {
            $label = $this->get_name() . ': ' . $this->get_title();
            echo '<div class="perimetre-block-preview">' . esc_html($label) . '</div>';
            return;
        }

        $allowed_blocks = $this->get_allowed_blocks();
        $template_lock  = $this->get_template_lock();

        echo '<div class="perimetre-block-preview perimetre-block-preview--inner-blocks">';

        $notice = $this->get_editor_notice();
        if (is_string($notice) && $notice !== '') {
            echo '<p class="perimetre-block-preview__notice">' . esc_html($notice) . '</p>';
        }

        echo '<InnerBlocks';
        echo ' template="' . esc_attr((string) wp_json_encode($template)) . '"';
        if ($allowed_blocks !== null && $allowed_blocks !== []) {
            echo ' allowedBlocks="' . esc_attr((string) wp_json_encode($allowed_blocks)) . '"';
        }
        if ($template_lock !== false) {
            echo ' templateLock="' . esc_attr($template_lock) . '"';
        }
        echo ' />';
        echo '</div>';
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
            'name'            => $this->get_name(),
            'title'           => $this->get_title(),
            'description'     => $this->get_description(),
            'category'        => $this->get_category(),
            'icon'            => $this->get_icon(),
            'mode'            => $this->get_mode(),
            'supports'        => $this->get_supports(),
            'render_callback' => [$this, 'render'],
            'show_in_graphql' => true,
        ];

        if ($this->get_inner_blocks_template() !== null) {
            $args['acf_block_version'] = 2;
            $args['supports']['jsx']   = true;
        }

        acf_register_block_type($args);

        $this->register_fields();
    }
}
