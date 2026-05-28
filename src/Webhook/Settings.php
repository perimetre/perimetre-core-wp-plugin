<?php

declare(strict_types=1);

namespace Perimetre\Core\Webhook;

use Perimetre\Core\Admin\Tabs;

/**
 * Registers the ACF options sub-page and fields for webhook configuration.
 *
 * The page is the "Webhooks" tab on the Perimetre Core settings surface.
 * The underlying ACF options page lives at
 * `options-general.php?page=acf-options-webhooks` but its menu entry is
 * hidden so the surface appears under a single "Perimetre Core" item.
 * Tab navigation is rendered by `Admin\Tabs` via `all_admin_notices`.
 */
final class Settings
{
    public const PAGE_SLUG = 'acf-options-webhooks';

    private const FIELD_GROUP_KEY = 'group_perimetre_webhooks';
    private const SCREEN_ID       = 'settings_page_' . self::PAGE_SLUG;

    /** @var array<string, mixed>|null */
    private static ?array $options = null;

    public static function register(): void
    {
        add_action('acf/init', [self::class, 'register_options_page']);
        add_action('acf/init', [self::class, 'register_field_group']);
        add_filter(
            'acf/load_field/key=field_perimetre_webhook_post_types',
            [self::class, 'populate_post_type_choices']
        );
        // Late priority so this runs after ACF's own admin_menu callbacks
        // have registered the sub-page.
        add_action('admin_menu', [self::class, 'hide_menu_entry'], 999);
        add_action('all_admin_notices', [self::class, 'render_tab_nav']);
    }

    public static function register_options_page(): void
    {
        if (! function_exists('acf_add_options_sub_page')) {
            return;
        }

        acf_add_options_sub_page([
            'page_title'  => __('Perimetre Core', 'perimetre-core'),
            'menu_title'  => __('Perimetre Core', 'perimetre-core'),
            'menu_slug'   => self::PAGE_SLUG,
            'parent_slug' => 'options-general.php',
            'capability'  => 'manage_options',
        ]);
    }

    /**
     * Removes the duplicate "Perimetre Core" entry that ACF would add under
     * Settings — the Status\Settings page already owns that slot, and the
     * webhooks page is reached via the Webhooks tab.
     */
    public static function hide_menu_entry(): void
    {
        remove_submenu_page('options-general.php', self::PAGE_SLUG);
    }

    public static function render_tab_nav(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->id !== self::SCREEN_ID) {
            return;
        }
        Tabs::render(Tabs::TAB_WEBHOOKS);
    }

    public static function register_field_group(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'      => self::FIELD_GROUP_KEY,
            'title'    => __('Webhook Settings', 'perimetre-core'),
            'location' => [
                [
                    [
                        'param'    => 'options_page',
                        'operator' => '==',
                        'value'    => self::PAGE_SLUG,
                    ],
                ],
            ],
            'fields' => [
                [
                    'key'           => 'field_perimetre_webhook_enabled',
                    'name'          => 'perimetre_webhook_enabled',
                    'label'         => __('Enable Webhooks', 'perimetre-core'),
                    'type'          => 'true_false',
                    'ui'            => 1,
                    'default_value' => 0,
                    'instructions'  => __(
                        'Fires a JSON POST request to the configured URL on watched events '
                        . '(post changes, options saves, menu updates).',
                        'perimetre-core'
                    ),
                ],
                [
                    'key'               => 'field_perimetre_webhook_url',
                    'name'              => 'perimetre_webhook_url',
                    'label'             => __('Webhook URL', 'perimetre-core'),
                    'type'              => 'url',
                    'conditional_logic' => [
                        [['field' => 'field_perimetre_webhook_enabled', 'operator' => '==', 'value' => '1']],
                    ],
                ],
                [
                    'key'               => 'field_perimetre_webhook_secret',
                    'name'              => 'perimetre_webhook_secret',
                    'label'             => __('Secret Token', 'perimetre-core'),
                    'type'              => 'text',
                    'instructions'      => __(
                        'Sent as a Bearer token in the Authorization header. Treat this value as sensitive.',
                        'perimetre-core'
                    ),
                    'conditional_logic' => [
                        [['field' => 'field_perimetre_webhook_enabled', 'operator' => '==', 'value' => '1']],
                    ],
                ],
                [
                    'key'               => 'field_perimetre_webhook_post_types',
                    'name'              => 'perimetre_webhook_post_types',
                    'label'             => __('Watched Post Types', 'perimetre-core'),
                    'type'              => 'checkbox',
                    'choices'           => [],
                    'instructions'      => __(
                        'Leave all unchecked to watch every public post type.',
                        'perimetre-core'
                    ),
                    'conditional_logic' => [
                        [['field' => 'field_perimetre_webhook_enabled', 'operator' => '==', 'value' => '1']],
                    ],
                ],
                [
                    'key'               => 'field_perimetre_webhook_events',
                    'name'              => 'perimetre_webhook_events',
                    'label'             => __('Watched Events', 'perimetre-core'),
                    'type'              => 'checkbox',
                    'choices'           => [
                        'publish' => __('Publish / Update', 'perimetre-core'),
                        'draft'   => __('Draft', 'perimetre-core'),
                        'trash'   => __('Trash', 'perimetre-core'),
                        'delete'  => __('Permanent Delete', 'perimetre-core'),
                        'options' => __('ACF Options Saved', 'perimetre-core'),
                        'menu'    => __('Menu Saved or Deleted', 'perimetre-core'),
                    ],
                    'default_value'     => ['publish', 'trash', 'delete'],
                    'conditional_logic' => [
                        [['field' => 'field_perimetre_webhook_enabled', 'operator' => '==', 'value' => '1']],
                    ],
                ],
                [
                    'key'               => 'field_perimetre_webhook_timeout',
                    'name'              => 'perimetre_webhook_timeout',
                    'label'             => __('Request Timeout (s)', 'perimetre-core'),
                    'type'              => 'number',
                    'min'               => 1,
                    'max'               => 30,
                    'default_value'     => 5,
                    'conditional_logic' => [
                        [['field' => 'field_perimetre_webhook_enabled', 'operator' => '==', 'value' => '1']],
                    ],
                ],
                [
                    'key'               => 'field_perimetre_webhook_info',
                    'name'              => '',
                    'label'             => '',
                    'type'              => 'message',
                    'message'           => self::get_info_message(),
                    'conditional_logic' => [
                        [['field' => 'field_perimetre_webhook_enabled', 'operator' => '==', 'value' => '1']],
                    ],
                ],
            ],
        ]);
    }

    private static function get_info_message(): string
    {
        $payload = wp_json_encode([
            'event'      => 'post.published',
            'post_id'    => 42,
            'post_type'  => 'page',
            'post_slug'  => 'about-us',
            'post_title' => 'About Us',
            'permalink'  => '/about-us/',
            'language'   => 'en',
            'taxonomies' => (object) [],
            'timestamp'  => 1713000000,
            'old_status' => 'draft',
            'new_status' => 'publish',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $options_payload = wp_json_encode([
            'event'        => 'options.saved',
            'options_page' => 'acf-options-seo',
            'timestamp'    => 1713000000,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $menu_payload = wp_json_encode([
            'event'     => 'menu.saved',
            'menu_id'   => 3,
            'menu_name' => 'Main Navigation',
            'menu_slug' => 'main-navigation',
            'timestamp' => 1713000000,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $pre = 'background:#f0f0f0;padding:10px;max-width:480px;overflow:auto';

        return
            '<p>'
            . esc_html__(
                'Fires a JSON POST request to the configured URL on watched events. '
                . 'The request includes an Authorization: Bearer header with the secret token.',
                'perimetre-core'
            )
            . '</p>'
            . '<p><strong>' . esc_html__('Post event payload:', 'perimetre-core') . '</strong></p>'
            . '<pre style="' . esc_attr($pre) . '">'
            . esc_html((string) $payload)
            . '</pre>'
            . '<p><strong>' . esc_html__('Options event payload:', 'perimetre-core') . '</strong></p>'
            . '<pre style="' . esc_attr($pre) . '">'
            . esc_html((string) $options_payload)
            . '</pre>'
            . '<p><strong>' . esc_html__('Menu event payload:', 'perimetre-core') . '</strong></p>'
            . '<pre style="' . esc_attr($pre) . '">'
            . esc_html((string) $menu_payload)
            . '</pre>'
            . '<p><em>'
            . esc_html__(
                'Post payloads: old_status/new_status included on transitions, omitted on deletes. '
                . 'language is included when WPML is active, null otherwise.',
                'perimetre-core'
            )
            . '</em></p>';
    }

    /**
     * Dynamically populate the post type checkbox choices.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function populate_post_type_choices(array $field): array
    {
        $post_types = get_post_types(['public' => true], 'objects');
        unset($post_types['attachment']);

        $field['choices'] = [];
        foreach ($post_types as $post_type) {
            $field['choices'][$post_type->name] = $post_type->label;
        }

        return $field;
    }

    /**
     * Read and cache all webhook options for the current request.
     *
     * @return array<string, mixed>
     */
    public static function get_settings(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        if (! function_exists('get_field')) {
            self::$options = [
                'enabled'    => false,
                'url'        => '',
                'secret'     => '',
                'post_types' => [],
                'events'     => [],
                'timeout'    => 5,
            ];
            return self::$options;
        }

        $post_types = get_field('perimetre_webhook_post_types', 'option');
        if (empty($post_types) || ! is_array($post_types)) {
            $all = get_post_types(['public' => true], 'names');
            unset($all['attachment']);
            $post_types = array_values($all);
        }

        $events = get_field('perimetre_webhook_events', 'option');
        if (empty($events) || ! is_array($events)) {
            $events = [];
        }

        self::$options = [
            'enabled'    => (bool) get_field('perimetre_webhook_enabled', 'option'),
            'url'        => (string) (get_field('perimetre_webhook_url', 'option') ?? ''),
            'secret'     => (string) (get_field('perimetre_webhook_secret', 'option') ?? ''),
            'post_types' => $post_types,
            'events'     => $events,
            'timeout'    => (int) (get_field('perimetre_webhook_timeout', 'option') ?: 5),
        ];

        return self::$options;
    }

    public static function is_enabled(): bool
    {
        return self::get_settings()['enabled'];
    }

    public static function get_url(): string
    {
        return self::get_settings()['url'];
    }

    public static function get_secret(): string
    {
        return self::get_settings()['secret'];
    }

    /**
     * @return list<string>
     */
    public static function get_post_types(): array
    {
        return self::get_settings()['post_types'];
    }

    /**
     * @return list<string>
     */
    public static function get_events(): array
    {
        return self::get_settings()['events'];
    }

    public static function get_timeout(): int
    {
        return self::get_settings()['timeout'];
    }

    public static function can_dispatch(): bool
    {
        return self::is_enabled() && self::get_url() !== '' && self::get_secret() !== '';
    }
}
