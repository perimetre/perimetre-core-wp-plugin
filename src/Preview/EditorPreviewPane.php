<?php

declare(strict_types=1);

namespace Perimetre\Core\Preview;

use WP_Post;

/**
 * Side-by-side frontend preview inside the block editor.
 *
 * WordPress core has no such thing: Gutenberg's **Preview** button only opens
 * `preview_post_link` in a new tab. This registers a small editor plugin
 * (`assets/editor-preview.js`, plain ES5 on the `wp.*` globals — no build step)
 * that adds:
 *
 *   - a "Site preview" sidebar holding an `<iframe>` of the signed preview link
 *     ({@see PreviewUrl}), widened by CSS while open so it reads as a
 *     side-by-side pane rather than a 280px strip;
 *   - an entry in the Preview dropdown that opens it;
 *   - auto-refresh once a save/autosave completes, so Gutenberg's own autosave
 *     (which is what the frontend's `asPreview` read returns) drives the pane.
 *
 * The preview URL is stateless (the signed token is in the URL, no cookie), so
 * the frame works in every browser and whatever domains the CMS and frontend
 * live on — including a local `next dev`.
 *
 * Only reaches post types that use the block editor. A CPT registered with
 * `show_in_rest => false` uses the Classic editor, where there is no pane to
 * add — its **Preview** button still opens the signed link in a new tab.
 */
final class EditorPreviewPane
{
    private const HANDLE = 'perimetre-core-editor-preview';

    public static function register(): void
    {
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue']);
    }

    public static function enqueue(): void
    {
        $post = get_post();
        if (! $post instanceof WP_Post) {
            return;
        }

        $url = PreviewUrl::for($post);
        if ($url === null) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            PERIMETRE_CORE_URL . 'assets/editor-preview.js',
            ['wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'],
            PERIMETRE_CORE_VERSION,
            true
        );
        wp_add_inline_script(
            self::HANDLE,
            'window.perimetreEditorPreview = ' . wp_json_encode(['previewUrl' => $url]) . ';',
            'before'
        );
        // Ships the `__()` strings in assets/editor-preview.js to the editor,
        // so the pane speaks the admin language like the rest of Core.
        wp_set_script_translations(self::HANDLE, 'perimetre-core');

        wp_register_style(self::HANDLE, false, [], PERIMETRE_CORE_VERSION);
        wp_enqueue_style(self::HANDLE);
        wp_add_inline_style(self::HANDLE, self::css());
    }

    private static function css(): string
    {
        return <<<'CSS'
.perimetre-editor-preview-open .interface-interface-skeleton__sidebar {
    width: min(55vw, 1100px) !important;
    max-width: none !important;
}
.perimetre-editor-preview-open .interface-interface-skeleton__sidebar .interface-complementary-area,
.perimetre-editor-preview-open .interface-interface-skeleton__sidebar .interface-complementary-area__fill {
    width: 100% !important;
}
.perimetre-editor-preview {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 140px);
}
.perimetre-editor-preview__bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid #e0e0e0;
}
.perimetre-editor-preview__bar .components-button { flex: 0 0 auto; }
.perimetre-editor-preview__hint {
    margin: 0 0 0 auto;
    color: #757575;
    font-size: 11px;
}
.perimetre-editor-preview__frame {
    flex: 1 1 auto;
    width: 100%;
    border: 0;
    background: #fff;
}
CSS;
    }
}
