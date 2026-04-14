<?php

declare(strict_types=1);

namespace Perimetre\Core\Webhook;

use WP_Post;

/**
 * Listens for post status changes and dispatches webhook payloads.
 */
final class Dispatcher
{
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'publish' => 'publish',
        'private' => 'publish',
        'future'  => 'publish',
        'draft'   => 'draft',
        'pending' => 'draft',
        'trash'   => 'trash',
    ];

    public static function register(): void
    {
        add_action('transition_post_status', [self::class, 'on_transition'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_delete'], 10, 2);
    }

    public static function on_transition(string $new_status, string $old_status, WP_Post $post): void
    {
        if (! Settings::can_dispatch()) {
            return;
        }

        if (wp_is_post_revision($post->ID)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! in_array($post->post_type, Settings::get_post_types(), true)) {
            return;
        }

        $event_key = self::STATUS_MAP[$new_status] ?? null;
        if ($event_key === null) {
            return;
        }

        if (! in_array($event_key, Settings::get_events(), true)) {
            return;
        }

        $event_label = self::resolve_event_label($new_status, $old_status);

        $payload = self::build_payload($event_label, $post, $old_status, $new_status);
        self::dispatch($payload);
    }

    public static function on_delete(int $post_id, WP_Post $post): void
    {
        if (! Settings::can_dispatch()) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! in_array($post->post_type, Settings::get_post_types(), true)) {
            return;
        }

        if (! in_array('delete', Settings::get_events(), true)) {
            return;
        }

        $payload = self::build_payload('post.deleted', $post);
        self::dispatch($payload);
    }

    private static function resolve_event_label(string $new_status, string $old_status): string
    {
        if ($new_status === 'trash') {
            return 'post.trashed';
        }

        if ($new_status === 'private') {
            return 'post.privatized';
        }

        if ($new_status === 'future') {
            return 'post.scheduled';
        }

        if ($new_status === 'draft' || $new_status === 'pending') {
            return 'post.drafted';
        }

        if ($new_status === 'publish' && $old_status !== 'publish') {
            return 'post.published';
        }

        if ($new_status === 'publish' && $old_status === 'publish') {
            return 'post.updated';
        }

        return 'post.status_changed';
    }

    /**
     * @return array<string, mixed>
     */
    private static function build_payload(
        string $event,
        WP_Post $post,
        ?string $old_status = null,
        ?string $new_status = null,
    ): array {
        $payload = [
            'event'      => $event,
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'post_slug'  => $post->post_name,
            'post_title' => $post->post_title,
            'permalink'  => self::get_relative_permalink($post),
            'language'   => self::get_language($post->ID),
            'taxonomies' => self::get_taxonomies($post),
            'timestamp'  => time(),
        ];

        if ($old_status !== null && $new_status !== null) {
            $payload['old_status'] = $old_status;
            $payload['new_status'] = $new_status;
        }

        return $payload;
    }

    private static function get_relative_permalink(WP_Post $post): string
    {
        // WordPress appends __trashed to slugs — temporarily restore for a clean permalink.
        $original_status = $post->post_status;
        $original_name = $post->post_name;
        if ($original_status === 'trash') {
            $post->post_status = 'publish';
            $post->post_name = preg_replace('/__trashed$/', '', $post->post_name);
        }

        $permalink = get_permalink($post);

        $post->post_status = $original_status;
        $post->post_name = $original_name;

        if (! is_string($permalink)) {
            return '';
        }

        return (string) wp_parse_url($permalink, PHP_URL_PATH) ?: '/';
    }

    /**
     * Returns the WPML language code for a post, or null when WPML is inactive.
     */
    private static function get_language(int $post_id): ?string
    {
        if (! has_filter('wpml_post_language_details')) {
            return null;
        }

        /** @var array{language_code?: string}|false $details */
        $details = apply_filters('wpml_post_language_details', null, $post_id);

        return is_array($details) && isset($details['language_code'])
            ? $details['language_code']
            : null;
    }

    /**
     * Returns public taxonomy terms keyed by taxonomy slug.
     *
     * @return array<string, list<string>>
     */
    private static function get_taxonomies(WP_Post $post): array
    {
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        $result = [];

        foreach ($taxonomies as $taxonomy) {
            if (! $taxonomy->public) {
                continue;
            }

            $terms = get_the_terms($post, $taxonomy->name);
            if (! is_array($terms)) {
                continue;
            }

            $slugs = [];
            foreach ($terms as $term) {
                $slugs[] = $term->slug;
            }

            if ($slugs !== []) {
                $result[$taxonomy->name] = $slugs;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function dispatch(array $payload): void
    {
        wp_remote_post(Settings::get_url(), [
            'body'     => wp_json_encode($payload),
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . Settings::get_secret(),
            ],
            'timeout'  => Settings::get_timeout(),
            'blocking' => false,
        ]);
    }
}
