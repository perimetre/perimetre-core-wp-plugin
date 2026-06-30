<?php

declare(strict_types=1);

namespace Perimetre\Core\Webhook;

/**
 * Registers the ACF options sub-page and fields for webhook configuration.
 *
 * This is the single **Settings > Perimetre Core** entry (Core's only admin
 * surface), living at `options-general.php?page=acf-options-webhooks`. If Core
 * grows more settings later, this page can host a tab strip.
 */
final class Settings
{
    public const PAGE_SLUG = 'acf-options-webhooks';

    private const FIELD_GROUP_KEY = 'group_perimetre_webhooks';

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
                        'Fires a JSON POST request to each configured URL on watched events '
                        . '(post changes, options saves, menu updates).',
                        'perimetre-core'
                    ),
                ],
                [
                    'key'               => 'field_perimetre_webhook_urls',
                    'name'              => 'perimetre_webhook_urls',
                    'label'             => __('Webhook URLs', 'perimetre-core'),
                    'type'              => 'repeater',
                    'layout'            => 'table',
                    'button_label'      => __('Add URL', 'perimetre-core'),
                    'instructions'      => __(
                        'Each watched event is dispatched to every URL listed here.',
                        'perimetre-core'
                    ),
                    'sub_fields'        => [
                        [
                            'key'   => 'field_perimetre_webhook_url',
                            'name'  => 'url',
                            'label' => __('URL', 'perimetre-core'),
                            'type'  => 'url',
                        ],
                    ],
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
                        'Leave all unchecked to watch every public or GraphQL-exposed post type.',
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
                'Fires a JSON POST request to each configured URL on watched events. '
                . 'Every request includes an Authorization: Bearer header with the secret token.',
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
        $field['choices'] = [];
        foreach (self::get_watchable_post_types() as $post_type) {
            $field['choices'][$post_type->name] = $post_type->label;
        }

        return $field;
    }

    /**
     * Post types eligible for webhook watching: anything public OR exposed to
     * GraphQL (headless CPTs are often registered public => false). Excludes
     * attachments.
     *
     * @return array<string, \WP_Post_Type>
     */
    private static function get_watchable_post_types(): array
    {
        $types = get_post_types(['public' => true], 'objects')
            + get_post_types(['show_in_graphql' => true], 'objects');
        unset($types['attachment']);

        return $types;
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
                'urls'       => [],
                'secret'     => '',
                'post_types' => [],
                'events'     => [],
                'timeout'    => 5,
            ];
            return self::$options;
        }

        $post_types = get_field('perimetre_webhook_post_types', 'option');
        if (empty($post_types) || ! is_array($post_types)) {
            $post_types = array_keys(self::get_watchable_post_types());
        }

        $events = get_field('perimetre_webhook_events', 'option');
        if (empty($events) || ! is_array($events)) {
            $events = [];
        }

        self::$options = [
            'enabled'    => (bool) get_field('perimetre_webhook_enabled', 'option'),
            'urls'       => self::read_urls(),
            'secret'     => (string) (get_field('perimetre_webhook_secret', 'option') ?? ''),
            'post_types' => $post_types,
            'events'     => $events,
            'timeout'    => (int) (get_field('perimetre_webhook_timeout', 'option') ?: 5),
        ];

        return self::$options;
    }

    /**
     * Read the configured webhook URLs from the repeater, falling back to the
     * legacy single-URL option saved before the repeater existed.
     *
     * @return list<string>
     */
    private static function read_urls(): array
    {
        $urls = [];

        $rows = get_field('perimetre_webhook_urls', 'option');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $url = is_array($row) && isset($row['url']) ? $row['url'] : '';
                $url = is_string($url) ? trim($url) : '';
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        // Backward compatibility: a single URL stored before the repeater.
        if ($urls === []) {
            $legacy = get_option('options_perimetre_webhook_url');
            if (is_string($legacy) && trim($legacy) !== '') {
                $urls[] = trim($legacy);
            }
        }

        return $urls;
    }

    public static function is_enabled(): bool
    {
        return self::get_settings()['enabled'];
    }

    /**
     * @return list<string>
     */
    public static function get_urls(): array
    {
        return self::get_settings()['urls'];
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
        return self::is_enabled() && self::get_urls() !== [] && self::get_secret() !== '';
    }
}
