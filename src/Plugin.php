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
     */
    public static function enqueue_editor_assets(): void
    {
        wp_enqueue_style(
            'perimetre-core-editor',
            PERIMETRE_CORE_URL . 'assets/editor.css',
            [],
            PERIMETRE_CORE_VERSION
        );
    }
}
