<?php

declare(strict_types=1);

namespace Perimetre\Core\Status;

/**
 * Registers the Status settings section on the Perimetre Core admin page.
 */
final class Settings
{
    public const OPTION_ENABLED = 'perimetre_status_enabled';
    public const OPTION_SLUG = 'perimetre_status_slug';
    public const OPTION_TOKEN = 'perimetre_status_token';
    public const PAGE_SLUG = 'perimetre-core';
    public const SECTION_ID = 'perimetre_status_section';

    private const DEFAULT_SLUG = 'status';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function add_menu_page(): void
    {
        add_options_page(
            __('Perimetre Core', 'perimetre-core'),
            __('Perimetre Core', 'perimetre-core'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::PAGE_SLUG);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public static function register_settings(): void
    {
        add_settings_section(
            self::SECTION_ID,
            __('Status Endpoint', 'perimetre-core'),
            [self::class, 'render_section_description'],
            self::PAGE_SLUG
        );

        // Enabled checkbox
        register_setting(self::PAGE_SLUG, self::OPTION_ENABLED, [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => [self::class, 'sanitize_enabled'],
        ]);
        add_settings_field(
            self::OPTION_ENABLED,
            __('Status enabled', 'perimetre-core'),
            [self::class, 'render_enabled_field'],
            self::PAGE_SLUG,
            self::SECTION_ID
        );

        // Slug text field
        register_setting(self::PAGE_SLUG, self::OPTION_SLUG, [
            'type' => 'string',
            'default' => self::DEFAULT_SLUG,
            'sanitize_callback' => [self::class, 'sanitize_slug'],
        ]);
        add_settings_field(
            self::OPTION_SLUG,
            __('Status slug', 'perimetre-core'),
            [self::class, 'render_slug_field'],
            self::PAGE_SLUG,
            self::SECTION_ID
        );

        // Secret token
        register_setting(self::PAGE_SLUG, self::OPTION_TOKEN, [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => [self::class, 'sanitize_token'],
        ]);
        add_settings_field(
            self::OPTION_TOKEN,
            __('Secret token', 'perimetre-core'),
            [self::class, 'render_token_field'],
            self::PAGE_SLUG,
            self::SECTION_ID
        );
    }

    public static function render_section_description(): void
    {
        echo '<p>' . esc_html__('Configure the public status / health-check endpoint.', 'perimetre-core') . '</p>';
    }

    public static function sanitize_enabled(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function render_enabled_field(): void
    {
        $enabled = (bool) get_option(self::OPTION_ENABLED, false);
        printf(
            '<input type="checkbox" name="%s" value="1" %s />',
            esc_attr(self::OPTION_ENABLED),
            checked($enabled, true, false)
        );
    }

    public static function render_slug_field(): void
    {
        $slug = (string) get_option(self::OPTION_SLUG, self::DEFAULT_SLUG);
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" />',
            esc_attr(self::OPTION_SLUG),
            esc_attr($slug)
        );
    }

    public static function render_token_field(): void
    {
        $token = (string) get_option(self::OPTION_TOKEN, '');
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" />',
            esc_attr(self::OPTION_TOKEN),
            esc_attr($token)
        );
        echo '<p class="description">' . esc_html__('Leave empty to auto-generate on save.', 'perimetre-core') . '</p>';
    }

    public static function sanitize_slug(mixed $value): string
    {
        $old_slug = (string) get_option(self::OPTION_SLUG, self::DEFAULT_SLUG);
        $new_slug = sanitize_title((string) $value);

        if ($new_slug === '') {
            $new_slug = self::DEFAULT_SLUG;
        }

        if ($new_slug !== $old_slug) {
            // Flag that rewrite rules need flushing after settings are saved.
            update_option('perimetre_status_flush_rewrite', true);
        }

        return $new_slug;
    }

    public static function sanitize_token(mixed $value): string
    {
        $token = trim((string) $value);

        if ($token === '') {
            $token = wp_generate_password(48, false);
        }

        return $token;
    }

    public static function get_slug(): string
    {
        return (string) get_option(self::OPTION_SLUG, self::DEFAULT_SLUG);
    }

    public static function get_token(): string
    {
        return (string) get_option(self::OPTION_TOKEN, '');
    }

    public static function is_enabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }
}
