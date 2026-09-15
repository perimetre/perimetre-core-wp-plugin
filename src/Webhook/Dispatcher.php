<?php

declare(strict_types=1);

namespace Perimetre\Core\Webhook;

use WP_Post;

/**
 * Listens for post status changes and dispatches webhook payloads.
 */
final class Dispatcher
{
    /** @var array<int, \WP_Term> */
    private static array $menu_cache = [];

    /**
     * Post events awaiting dispatch, keyed by post ID so repeated saves of the
     * same post within one request collapse into a single webhook. See `flush()`.
     *
     * @var array<int, array{event: string, old_status: ?string, new_status: ?string, payload: ?array<string, mixed>}>
     */
    private static array $pending = [];

    /**
     * Terms removed from a post during this request, as
     * `[post_id][taxonomy] => list<slug>`. Populated by `on_set_object_terms()`
     * and merged into the payload as `taxonomies_removed`.
     *
     * @var array<int, array<string, list<string>>>
     */
    private static array $removed_terms = [];

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
        // Terms are recorded as they change so the payload can report what was
        // REMOVED, which `get_the_terms()` can no longer see once the save is
        // over. Priority 10 with 6 args — WordPress passes `$old_tt_ids` last.
        add_action('set_object_terms', [self::class, 'on_set_object_terms'], 10, 6);
        // Post payloads are built and sent here, not at transition time — see
        // `flush()`.
        add_action('shutdown', [self::class, 'flush'], 10, 0);
        add_action('acf/save_post', [self::class, 'on_options_save'], 20);
        add_action('wp_update_nav_menu', [self::class, 'on_menu_save'], 10);
        add_action('wp_delete_nav_menu', [self::class, 'on_menu_delete'], 10);
        add_action('pre_delete_term', [self::class, 'cache_menu_before_delete'], 10, 2);
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

        // Buffered, not sent: the payload is built in `flush()` on `shutdown`.
        // Two reasons, both about reading the post's FINAL state:
        //
        //  - Term timing. `wp_insert_post()` writes `tax_input` terms before
        //    firing `transition_post_status`, but the REST controller (the
        //    block editor, and any `show_in_rest` post type) updates the post
        //    first and applies terms AFTERWARDS. Building the payload here
        //    reads the terms as they were BEFORE the save for those post types.
        //  - Removed terms. `on_set_object_terms()` may not have run yet for
        //    the same reason, so `taxonomies_removed` would be empty.
        //
        // Keyed by post ID, so a request that saves the same post repeatedly
        // (an ACF write that re-saves, a `save_post` hook that calls
        // `wp_update_post()`) sends ONE webhook instead of several identical
        // ones. The first event label wins — it describes the transition that
        // actually happened, e.g. `post.published` is not downgraded to
        // `post.updated` by a follow-up save.
        self::$pending[$post->ID] ??= [
            'event'      => $event_label,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'payload'    => null,
        ];
    }

    /**
     * Records the terms REMOVED from a post, so the payload can report them.
     *
     * This is the only place WordPress exposes the previous terms: once the
     * save completes, `get_the_terms()` returns the new set and what was taken
     * away is unrecoverable. A headless frontend needs it — a listing page for
     * a term the post no longer has gets no other signal that it must drop the
     * post, so it keeps showing it until its cache expires on time.
     *
     * @param array<int, int|string> $tt_ids     Term taxonomy IDs after the change.
     * @param array<int, int|string> $old_tt_ids Term taxonomy IDs before it.
     */
    public static function on_set_object_terms(
        int $object_id,
        mixed $terms,
        mixed $tt_ids,
        string $taxonomy,
        mixed $append,
        mixed $old_tt_ids,
    ): void {
        if (! is_array($tt_ids) || ! is_array($old_tt_ids)) {
            return;
        }

        // `set_object_terms` fires for every taxonomy on every object; ignore
        // anything this site does not dispatch for.
        $post = get_post($object_id);
        if (
            ! $post instanceof WP_Post
            || ! in_array($post->post_type, Settings::get_post_types(), true)
        ) {
            return;
        }

        $removed = array_diff(
            array_map('intval', $old_tt_ids),
            array_map('intval', $tt_ids),
        );

        if ($removed === []) {
            return;
        }

        $slugs = [];
        foreach ($removed as $tt_id) {
            // `get_term_by('term_taxonomy_id')` still resolves here: the row is
            // only unlinked from the object, the term itself still exists.
            $term = get_term_by('term_taxonomy_id', $tt_id);
            if ($term instanceof \WP_Term && $term->slug !== '') {
                $slugs[] = $term->slug;
            }
        }

        if ($slugs === []) {
            return;
        }

        $existing = self::$removed_terms[$object_id][$taxonomy] ?? [];
        self::$removed_terms[$object_id][$taxonomy] = array_values(
            array_unique([...$existing, ...$slugs]),
        );
    }

    /**
     * Builds and sends every buffered post webhook, at the end of the request.
     *
     * Deferring to `shutdown` is what makes the payload describe the post's
     * final state rather than a mid-save snapshot — see `on_transition()`.
     * Deletes are the exception: their payload is built at hook time, while the
     * post still exists, and is passed through here untouched.
     */
    public static function flush(): void
    {
        $pending = self::$pending;
        $removed = self::$removed_terms;

        // Cleared before dispatching so a fatal or a re-entrant `flush()`
        // cannot send the same webhooks twice.
        self::$pending = [];
        self::$removed_terms = [];

        foreach ($pending as $post_id => $entry) {
            $payload = $entry['payload'];

            if ($payload === null) {
                $post = get_post($post_id);

                // Gone before shutdown — the post was deleted later in the same
                // request, and `post.deleted` already covers it.
                if (! $post instanceof WP_Post) {
                    continue;
                }

                $payload = self::build_payload(
                    $entry['event'],
                    $post,
                    $entry['old_status'],
                    $entry['new_status'],
                );
            }

            if (isset($removed[$post_id]) && $removed[$post_id] !== []) {
                $payload['taxonomies_removed'] = $removed[$post_id];
            }

            self::dispatch($payload);
        }
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

        // Built now, while the post and its terms still exist, but queued so it
        // keeps the ordering and de-duplication of everything else. Overwrites
        // any pending transition for this post: it is being deleted, so the
        // earlier status change is moot.
        self::$pending[$post_id] = [
            'event'      => 'post.deleted',
            'old_status' => null,
            'new_status' => null,
            'payload'    => self::build_payload('post.deleted', $post),
        ];
    }

    /**
     * @param int|string $post_id
     */
    public static function on_options_save(mixed $post_id): void
    {
        // ACF appends the WPML language code to the options post id on
        // non-default languages (`options` -> `options_fr`) — both in core
        // (`acf_get_valid_post_id()`) and via project MultilingualOptions
        // filters on `acf/validate_post_id`. `acf/save_post` therefore fires
        // with the localized id, so a strict `!== 'options'` check silently
        // drops every non-default-language options save and the webhook never
        // fires for them. Accept the bare id and any `options_<lang>` variant.
        if (! is_string($post_id) || ($post_id !== 'options' && ! str_starts_with($post_id, 'options_'))) {
            return;
        }

        if (! Settings::can_dispatch()) {
            return;
        }

        if (! in_array('options', Settings::get_events(), true)) {
            return;
        }

        $page_slug = self::current_options_page_slug();
        if ($page_slug === null || $page_slug === '') {
            return;
        }

        // Avoid feedback loop when saving the webhook settings themselves.
        if ($page_slug === Settings::PAGE_SLUG) {
            return;
        }

        // ACF options pages aren't GraphQL "nodes", so WPGraphQL Smart Cache's
        // node-based invalidation never purges queries that read option values
        // (global nav CTAs, site-wide settings, …). Purge here so a headless
        // frontend revalidating on this `options.saved` webhook re-fetches
        // fresh data instead of a stale Smart Cache response.
        self::purge_graphql_cache($page_slug);

        self::dispatch([
            'event'        => 'options.saved',
            'options_page' => $page_slug,
            'timestamp'    => time(),
        ]);
    }

    /**
     * Resolve the ACF options-page menu slug being saved.
     *
     * `acf/save_post` fires for options pages with `$post_id === 'options'` but
     * doesn't identify WHICH page was saved. ACF options pages POST to their own
     * admin URL (`?page={menu_slug}`), so read the slug from the request and
     * validate it against the registered options pages — that also rejects any
     * unrelated `?page=` admin screen. (There is no `acf_get_current_screen()`
     * function in ACF; WordPress's `get_current_screen()->id` would return a
     * prefixed screen id like `settings_page_{slug}`, not the clean menu slug.)
     *
     * @return string|null The options-page menu slug, or null if this isn't a
     *                      recognised ACF options-page save.
     */
    private static function current_options_page_slug(): ?string
    {
        $page = isset($_REQUEST['page'])
            ? sanitize_key(wp_unslash($_REQUEST['page']))
            : '';
        if ($page === '') {
            return null;
        }

        if (function_exists('acf_get_options_pages')) {
            foreach ((array) acf_get_options_pages() as $options_page) {
                if (($options_page['menu_slug'] ?? null) === $page) {
                    return $page;
                }
            }

            return null;
        }

        return $page;
    }

    /**
     * Purge the WPGraphQL object/network cache after an ACF options-page save.
     *
     * WPGraphQL Smart Cache invalidates by content "node" (post / term / menu).
     * ACF options pages have no node, so any cached GraphQL response that reads
     * an option value stays stale until the cache TTL lapses — which makes
     * site-wide option edits (e.g. nav CTAs) look "stuck" on the headless
     * frontend even after the revalidation webhook fires. Firing the plugin's
     * documented purge-all hook clears those entries.
     *
     * No-op when WPGraphQL Smart Cache isn't installed (the action is
     * unregistered), so this stays safe on standard WP sites.
     *
     * @param string $page_slug The ACF options page slug that was saved.
     */
    private static function purge_graphql_cache(string $page_slug): void
    {
        /**
         * Filters whether saving an ACF options page purges the entire
         * WPGraphQL cache. Default true. Return false to opt out, or to wire a
         * more targeted purge for a specific options page.
         *
         * @param bool   $purge     Whether to purge the GraphQL cache.
         * @param string $page_slug The ACF options page slug being saved.
         */
        if (! apply_filters('perimetre_core_purge_graphql_cache_on_options', true, $page_slug)) {
            return;
        }

        if (! has_action('wpgraphql_cache_purge_all')) {
            return;
        }

        do_action('wpgraphql_cache_purge_all');
    }

    /**
     * Cache a nav-menu term before WordPress deletes it, so the menu's
     * name/slug are still available when on_menu_delete dispatches the webhook.
     *
     * Fires on the `pre_delete_term` action, which passes the term ID and
     * taxonomy slug — guard to `nav_menu` so other taxonomy deletions do no work.
     */
    public static function cache_menu_before_delete(int $term_id, string $taxonomy): void
    {
        if ($taxonomy !== 'nav_menu') {
            return;
        }

        $menu = wp_get_nav_menu_object($term_id);
        if ($menu) {
            self::$menu_cache[$term_id] = $menu;
        }
    }

    public static function on_menu_save(int $menu_id): void
    {
        self::handle_menu_event('menu.saved', $menu_id);
    }

    public static function on_menu_delete(int $menu_id): void
    {
        self::handle_menu_event('menu.deleted', $menu_id);
    }

    private static function handle_menu_event(string $event, int $menu_id): void
    {
        if (! Settings::can_dispatch()) {
            return;
        }

        if (! in_array('menu', Settings::get_events(), true)) {
            return;
        }

        $menu = wp_get_nav_menu_object($menu_id) ?: (self::$menu_cache[$menu_id] ?? null);
        if (! $menu) {
            return;
        }

        self::dispatch([
            'event'     => $event,
            'menu_id'   => $menu_id,
            'menu_name' => $menu->name,
            'menu_slug' => $menu->slug,
            'timestamp' => time(),
        ]);
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
     * Whether a taxonomy should appear in the webhook payload.
     *
     * This used to test `$taxonomy->public`, which was wrong for exactly the
     * sites Core exists to serve. A headless project renders no term archives
     * in WordPress, so it registers its taxonomies `'public' => false` and
     * exposes them through GraphQL or REST instead. The result was that
     * `taxonomies` arrived EMPTY on every product/CPT webhook, and a frontend
     * that keyed its cache invalidation off those terms never invalidated
     * anything — listings stayed stale until their cache expired on time.
     *
     * The test is therefore "is this taxonomy reachable by a consumer", not "is
     * it public on the WordPress front end": any of `public`,
     * `publicly_queryable`, `show_in_rest` or `show_in_graphql` qualifies. That
     * keeps genuinely internal taxonomies (ElasticPress's `ep_custom_result`,
     * private grouping taxonomies) out of the payload.
     *
     * Filterable so a project can force a taxonomy in or out.
     *
     * @param \WP_Taxonomy $taxonomy
     */
    private static function is_reportable_taxonomy(\WP_Taxonomy $taxonomy): bool
    {
        $reportable = $taxonomy->public
            || $taxonomy->publicly_queryable
            || $taxonomy->show_in_rest
            // Not a core `WP_Taxonomy` property — WPGraphQL adds it from the
            // registration args, so it is only set when that plugin is active.
            || ! empty($taxonomy->show_in_graphql);

        /**
         * Filters whether a taxonomy is reported in webhook payloads.
         *
         * @param bool         $reportable Whether to include it.
         * @param \WP_Taxonomy $taxonomy   The taxonomy being considered.
         */
        return (bool) apply_filters(
            'perimetre_core_webhook_reportable_taxonomy',
            $reportable,
            $taxonomy,
        );
    }

    /**
     * Returns the post's taxonomy terms keyed by taxonomy slug.
     *
     * Which taxonomies count is decided by `is_reportable_taxonomy()` — NOT by
     * `public` alone, which silently emptied this array on every headless site.
     *
     * @return array<string, list<string>>
     */
    private static function get_taxonomies(WP_Post $post): array
    {
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        $result = [];

        foreach ($taxonomies as $taxonomy) {
            if (! self::is_reportable_taxonomy($taxonomy)) {
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
        $args = [
            'body'     => wp_json_encode($payload),
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . Settings::get_secret(),
            ],
            'timeout'  => Settings::get_timeout(),
            'blocking' => false,
        ];

        foreach (Settings::get_urls() as $url) {
            wp_remote_post($url, $args);
        }
    }
}
