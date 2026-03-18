<?php

declare(strict_types=1);

namespace Perimetre\Core\Blocks;

/**
 * Abstract base class for custom native Gutenberg blocks.
 *
 * Native blocks are registered using WordPress's register_block_type() with
 * server-side support. Attributes are defined in block.json. Use this when
 * ACF blocks are insufficient or when deeper editor JS control is needed.
 *
 * Unlike ACF blocks, native block attributes are exposed through the `attributes`
 * object in GraphQL via WPGraphQL Content Blocks. This is a structural difference
 * from ACF blocks that is accepted as-is.
 *
 * Usage — extend this class and place block.json in the block directory:
 *
 *   class CalloutBlock extends NativeBlock
 *   {
 *       protected function get_name(): string
 *       {
 *           return 'perimetre/callout';
 *       }
 *
 *       protected function get_block_dir(): string
 *       {
 *           return PERIMETRE_CORE_PATH . 'blocks/callout';
 *       }
 *   }
 *
 * The block directory must contain a block.json file.
 * Block attributes defined in block.json are automatically exposed in GraphQL
 * via WPGraphQL Content Blocks — no additional registration is needed.
 *
 * Then register it:
 *
 *   Registry::register_block(CalloutBlock::class);
 */
abstract class NativeBlock
{
    /**
     * The block name in namespace/block-name format.
     * Must match the "name" field in block.json.
     */
    abstract protected function get_name(): string;

    /**
     * Absolute path to the directory containing block.json.
     * Typically within the plugin's /blocks/ directory.
     */
    abstract protected function get_block_dir(): string;

    /**
     * Optional server-side render callback.
     * Only needed if the block uses dynamic rendering in the editor.
     * In headless context this affects the editor preview only.
     *
     * Return null to use the render_callback defined in block.json (if any).
     *
     * @return callable|null
     */
    protected function get_render_callback(): ?callable
    {
        return null;
    }

    /**
     * Register the block type from block.json.
     * Called by Registry::register() on the 'init' hook.
     */
    final public function register(): void
    {
        $block_dir = $this->get_block_dir();

        if (! is_dir($block_dir) || ! file_exists($block_dir . '/block.json')) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'Block directory or block.json not found for block "%s" at path "%s".',
                    esc_html($this->get_name()),
                    esc_html($block_dir)
                ),
                '1.0.0'
            );
            return;
        }

        $contents = file_get_contents($block_dir . '/block.json');

        if ($contents === false) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'Could not read block.json for block "%s" at path "%s".',
                    esc_html($this->get_name()),
                    esc_html($block_dir)
                ),
                '1.0.0'
            );
            return;
        }

        $block_json = json_decode($contents, true);

        if (! is_array($block_json) || empty($block_json['name'])) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'Invalid block.json for block "%s" at path "%s".',
                    esc_html($this->get_name()),
                    esc_html($block_dir)
                ),
                '1.0.0'
            );
            return;
        }

        if ($block_json['name'] !== $this->get_name()) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'block.json "name" field "%s" does not match get_name() "%s".',
                    esc_html($block_json['name']),
                    esc_html($this->get_name())
                ),
                '1.0.0'
            );
            return;
        }

        $args = [];

        $render_callback = $this->get_render_callback();
        if ($render_callback !== null) {
            $args['render_callback'] = $render_callback;
        }

        register_block_type($block_dir, $args);
    }
}
