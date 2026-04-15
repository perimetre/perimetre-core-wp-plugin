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
 *               'key'            => 'group_perimetre_hero',
 *               'title'          => 'Hero',
 *               'show_in_graphql' => 1,
 *               'graphql_field_name' => $this->get_graphql_field_name(),
 *               'location'       => [[
 *                   ['param' => 'block', 'operator' => '==', 'value' => $this->get_acf_name()],
 *               ]],
 *               'fields'         => [
 *                   [
 *                       'key'   => 'field_perimetre_hero_heading',
 *                       'label' => 'Heading',
 *                       'name'  => 'heading',
 *                       'type'  => 'text',
 *                   ],
 *               ],
 *           ]);
 *       }
 *   }
 *
 * Then register it:
 *
 *   Registry::register_block(HeroBlock::class);
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
     * Additional block supports configuration.
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
     * The render callback for the block editor preview.
     * Defaults to null (ACF renders a simple placeholder).
     * Override to provide a custom PHP render template.
     *
     * @param array<string, mixed> $block
     * @param string $content
     * @param bool $is_preview
     * @param int $post_id
     */
    protected function render(array $block, string $content, bool $is_preview, int $post_id): void
    {
        // Override in child class to provide a custom editor preview.
        // In a headless context this is only used in the block editor, not on the frontend.
        $label = $this->get_name() . ': ' . $this->get_title();
        echo '<div class="perimetre-block-preview">' . esc_html($label) . '</div>';
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

        acf_register_block_type([
            'name'            => $this->get_name(),
            'title'           => $this->get_title(),
            'description'     => $this->get_description(),
            'category'        => $this->get_category(),
            'icon'            => $this->get_icon(),
            'mode'            => 'edit',
            'supports'        => $this->get_supports(),
            'render_callback' => [$this, 'render'],
            'show_in_graphql' => true,
        ]);

        $this->register_fields();
    }
}
