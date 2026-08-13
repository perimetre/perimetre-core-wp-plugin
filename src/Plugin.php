<?php

declare(strict_types=1);

namespace Perimetre\Core;

/**
 * Top-level plugin bootstrap helpers.
 */
final class Plugin
{
    public static function load_textdomain(): void
    {
        load_plugin_textdomain('perimetre-core', false, dirname(plugin_basename(PERIMETRE_CORE_FILE)) . '/languages');
    }

    /**
     * Enqueue Perimetre Core's block-editor stylesheet.
     *
     * The file lives at assets/editor.css. It styles the
     * `.perimetre-block-preview` wrappers emitted by AcfBlock::render(),
     * including the always-visible appender override for InnerBlocks
     * columns. Cache-busted by the plugin version constant.
     *
     * Hooked on `enqueue_block_assets`, which is the only hook whose styles
     * WordPress loads *inside* the iframed editor canvas where the preview
     * markup actually lives. `enqueue_block_editor_assets` targets the
     * document around the canvas, so since WP 6.3 the editor copies such
     * styles into the iframe and logs "…added to the iframe incorrectly".
     * `enqueue_block_assets` also fires on the front end, hence the
     * `is_admin()` guard — these rules only describe editor previews.
     */
    public static function enqueue_editor_assets(): void
    {
        if (! is_admin()) {
            return;
        }

        wp_enqueue_style(
            'perimetre-core-editor',
            PERIMETRE_CORE_URL . 'assets/editor.css',
            [],
            PERIMETRE_CORE_VERSION
        );
    }
}
