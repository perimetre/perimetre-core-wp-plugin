<?php

declare(strict_types=1);

namespace Perimetre\Core\Webhook;

use WP_Hook;
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

    /**
     * Relative permalink a post had BEFORE this request changed its slug or
     * parent, keyed by post ID. Captured in `on_pre_post_update()`, the last
     * moment the stored row is still the old one; emitted as `old_permalink`.
     *
     * @var array<int, string>
     */
    private static array $old_permalinks = [];

    /**
     * Options and menu payloads awaiting dispatch. They used to be sent from
     * their hook; they are now queued so `flush()` can attach the GraphQL purge
     * facts collected over the whole request. Purge-then-dispatch ordering for
     * `options.saved` is preserved — the purge still happens in the hook.
     *
     * @var list<array<string, mixed>>
     */
    private static array $queued = [];

    /**
     * WPGraphQL Smart Cache purge facts collected during this request from its
     * `graphql_purge` / `wpgraphql_cache_purge_all` actions.
     *
     * @var list<string>
     */
    private static array $purge_keys = [];

    /** @var list<string> */
    private static array $purge_events = [];

    private static bool $purge_all = false;

    /**
     * Nesting depth of `perimetre_core_webhooks_suspend()` calls.
     */
    private static int $suspend_depth = 0;

    /**
     * Set between `import_start` and `import_end`.
     */
    private static bool $importing = false;

    /**
     * Post events swallowed while webhooks were suspended, reported as ONE
     * `import.completed` event instead of a webhook per post.
     *
     * @var array{post_types: array<string, true>, post_ids: array<int, true>}
     */
    private static array $suppressed = ['post_types' => [], 'post_ids' => []];

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
        // The old slug / parent is only knowable before the row is rewritten.
        add_action('pre_post_update', [self::class, 'on_pre_post_update'], 10, 2);
        // Post payloads are built and sent here, not at transition time — see
        // `flush()`.
        add_action('shutdown', [self::class, 'flush'], 10, 0);
        add_action('acf/save_post', [self::class, 'on_options_save'], 20);
        add_action('wp_update_nav_menu', [self::class, 'on_menu_save'], 10);
        add_action('wp_delete_nav_menu', [self::class, 'on_menu_delete'], 10);
        add_action('pre_delete_term', [self::class, 'cache_menu_before_delete'], 10, 2);
        // WPGraphQL Smart Cache announces every key it evicts. Recording them
        // is how term, media, user and menu-location changes — which have no
        // hook of their own here — reach the frontend. No-ops without it.
        add_action('graphql_purge', [self::class, 'on_graphql_purge'], 10, 2);
        add_action('wpgraphql_cache_purge_all', [self::class, 'on_graphql_purge_all'], 10, 0);
        // WordPress Importer brackets a run with these; one event instead of
        // one webhook per imported post.
        add_action('import_start', [self::class, 'on_import_start'], 10, 0);
        add_action('import_end', [self::class, 'on_import_end'], 10, 0);
    }

    /**
     * Pause per-post webhooks. Posts saved while suspended are collected and
     * reported as a single `import.completed` event on `resume()`.
     *
     * Nestable: every `suspend()` needs a matching `resume()`. Prefer the
     * `perimetre_core_webhooks_suspend()` / `perimetre_core_webhooks_resume()`
     * helpers from procedural code such as a WP-CLI importer.
     */
    public static function suspend(): void
    {
        self::$suspend_depth++;
    }

    /**
     * Counterpart of `suspend()`. When the outermost suspension ends, the
     * collected posts are sent as one `import.completed` webhook.
     */
    public static function resume(): void
    {
        if (self::$suspend_depth === 0) {
            return;
        }

        self::$suspend_depth--;

        if (self::$suspend_depth === 0 && ! self::is_suspended()) {
            self::flush_suppressed();
        }
    }

    public static function on_import_start(): void
    {
        self::$importing = true;
    }

    public static function on_import_end(): void
    {
        self::$importing = false;

        if (! self::is_suspended()) {
            self::flush_suppressed();
        }
    }

    /**
     * Whether per-post webhooks are currently held back.
     *
     * True during `import_start` … `import_end`, while `WP_IMPORTING` is
     * defined (the WordPress Importer and WP-CLI's `wp import` set it), and
     * inside `suspend()` / `resume()`. Filterable for importers Core does not
     * know about.
     */
    public static function is_suspended(): bool
    {
        $suspended = self::$suspend_depth > 0
            || self::$importing
            || (defined('WP_IMPORTING') && WP_IMPORTING);

        /**
         * Filters whether per-post webhooks are suspended for this request.
         *
         * Return true from a custom importer to collapse its saves into one
         * `import.completed` event sent on `shutdown`.
         *
         * @param bool $suspended Whether webhooks are suspended.
         */
        return (bool) apply_filters('perimetre_core_webhook_suspended', $suspended);
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

        // Importers save hundreds of posts in one run; collect instead of
        // firing a webhook per post. See `flush_suppressed()`.
        if (self::is_suspended()) {
            self::record_suppressed($post);
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
     * Captures the permalink a post is about to lose, so the payload can report
     * it as `old_permalink`.
     *
     * Fires just before `wp_insert_post()` rewrites the row, with `$data` as
     * it is about to be stored. Only a slug or parent change moves the URL, so
     * nothing is recorded otherwise — and only a published post has a live path
     * worth telling a frontend to drop. The frontend needs this because the
     * payload's `permalink` is the NEW path; without the old one, the page at
     * the previous URL keeps serving from cache until it expires on time.
     *
     * @param array<string, mixed> $data Unslashed post data about to be written.
     */
    public static function on_pre_post_update(int $post_id, array $data): void
    {
        if (! Settings::can_dispatch()) {
            return;
        }

        $before = get_post($post_id);
        if (! $before instanceof WP_Post || $before->post_status !== 'publish') {
            return;
        }

        if (! in_array($before->post_type, Settings::get_post_types(), true)) {
            return;
        }

        $name   = isset($data['post_name']) && $data['post_name'] !== ''
            ? (string) $data['post_name']
            : $before->post_name;
        $parent = isset($data['post_parent']) ? (int) $data['post_parent'] : (int) $before->post_parent;

        if ($name === $before->post_name && $parent === (int) $before->post_parent) {
            return;
        }

        // First capture wins: a request that re-saves the post keeps the path
        // it had when the request started.
        self::$old_permalinks[$post_id] ??= self::get_relative_permalink($before);
    }

    /**
     * Records a key WPGraphQL Smart Cache just evicted.
     *
     * Smart Cache fires `graphql_purge` with the cache key (a Relay global ID
     * such as `cG9zdDo0Mg==`, or a list key such as `list:post`), the event
     * that caused it and the GraphQL endpoint host. Keys are forwarded as-is
     * — Core reports what WordPress knows and leaves mapping them to cache tags
     * to the frontend.
     *
     * Ignored while suspended: an import purges far more than any receiver
     * wants itemised, and `import.completed` tells it to revalidate broadly.
     */
    public static function on_graphql_purge(mixed $key, mixed $event = null): void
    {
        if (! is_string($key) || $key === '' || self::is_suspended()) {
            return;
        }

        self::$purge_keys[] = $key;

        if (is_string($event) && $event !== '') {
            self::$purge_events[] = $event;
        }
    }

    public static function on_graphql_purge_all(): void
    {
        // Core fires this hook itself from `purge_graphql_cache()`; without
        // Smart Cache listening nothing was actually purged, so don't claim it.
        if (self::is_suspended() || ! self::smart_cache_listens()) {
            return;
        }

        self::$purge_all = true;
    }

    /**
     * Whether something other than this dispatcher listens to Smart Cache's
     * purge-all hook — in practice, whether WPGraphQL Smart Cache is active.
     *
     * `has_action()` alone no longer answers that: `register()` adds Core's own
     * `on_graphql_purge_all()` recorder to the hook, which would make the check
     * always true and report `purge_all: true` for a purge that never happened.
     */
    private static function smart_cache_listens(): bool
    {
        global $wp_filter;

        $hook = $wp_filter['wpgraphql_cache_purge_all'] ?? null;
        if (! $hook instanceof WP_Hook) {
            return false;
        }

        foreach ($hook->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (($callback['function'] ?? null) !== [self::class, 'on_graphql_purge_all']) {
                    return true;
                }
            }
        }

        return false;
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
        $pending        = self::$pending;
        $removed        = self::$removed_terms;
        $old_permalinks = self::$old_permalinks;
        $queued         = self::$queued;
        $purge          = self::take_purge_facts();

        // Cleared before dispatching so a fatal or a re-entrant `flush()`
        // cannot send the same webhooks twice.
        self::$pending        = [];
        self::$removed_terms  = [];
        self::$old_permalinks = [];
        self::$queued         = [];

        // `WP_IMPORTING` runs, or an importer that never fired `import_end`:
        // the collected posts go out as one event here instead.
        self::flush_suppressed();

        $sent = 0;

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

            // Null when the path did not move. Trashing rewrites the slug to
            // `…__trashed`, but `permalink` already reports the pre-trash path,
            // so the two are equal and nothing is reported.
            $old_permalink = $old_permalinks[$post_id] ?? null;
            $payload['old_permalink'] = $old_permalink !== null && $old_permalink !== $payload['permalink']
                ? $old_permalink
                : null;

            self::dispatch($payload + $purge);
            $sent++;
        }

        foreach ($queued as $payload) {
            self::dispatch($payload + $purge);
            $sent++;
        }

        // Something was purged but nothing above explains it — a term edit, a
        // media edit, a user profile update. This is the only signal the
        // frontend gets for those.
        if ($sent === 0 && ($purge['purge_keys'] !== [] || $purge['purge_all'])) {
            self::dispatch_cache_purged($purge);
        }
    }

    /**
     * Snapshot and reset the purge facts, shaped for merging into a payload.
     *
     * @return array{purge_keys: list<string>, purge_events: list<string>, purge_all: bool}
     */
    private static function take_purge_facts(): array
    {
        $facts = [
            'purge_keys'   => array_values(array_unique(self::$purge_keys)),
            'purge_events' => array_values(array_unique(self::$purge_events)),
            'purge_all'    => self::$purge_all,
        ];

        self::$purge_keys   = [];
        self::$purge_events = [];
        self::$purge_all    = false;

        return $facts;
    }

    /**
     * @param array{purge_keys: list<string>, purge_events: list<string>, purge_all: bool} $purge
     */
    private static function dispatch_cache_purged(array $purge): void
    {
        if (! Settings::can_dispatch()) {
            return;
        }

        if (! in_array('purge', Settings::get_events(), true)) {
            return;
        }

        self::dispatch([
            'event'        => 'cache.purged',
            'purge_keys'   => $purge['purge_keys'],
            'purge_events' => $purge['purge_events'],
            'purge_all'    => $purge['purge_all'],
            'timestamp'    => time(),
        ]);
    }

    /**
     * Remembers a post whose webhook was held back by `is_suspended()`.
     */
    private static function record_suppressed(WP_Post $post): void
    {
        self::$suppressed['post_types'][$post->post_type] = true;
        self::$suppressed['post_ids'][$post->ID] = true;
    }

    /**
     * Sends the posts collected while suspended as ONE `import.completed`
     * event. No-op when nothing was collected.
     *
     * The gates were already applied per post when it was recorded (enabled,
     * watched post type, watched event), so this event needs no checkbox of
     * its own: it stands in for the per-post webhooks the site opted into.
     */
    private static function flush_suppressed(): void
    {
        $post_types = array_keys(self::$suppressed['post_types']);
        $post_ids   = array_keys(self::$suppressed['post_ids']);

        self::$suppressed = ['post_types' => [], 'post_ids' => []];

        if ($post_ids === []) {
            return;
        }

        if (! Settings::can_dispatch()) {
            return;
        }

        self::dispatch([
            'event'      => 'import.completed',
            'post_types' => $post_types,
            'post_ids'   => $post_ids,
            'count'      => count($post_ids),
            'timestamp'  => time(),
        ]);
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

        if (self::is_suspended()) {
            self::record_suppressed($post);
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

        // The purge above still happens during an import; only the event is
        // dropped — `import.completed` tells the frontend to revalidate broadly.
        if (self::is_suspended()) {
            return;
        }

        // Queued, not sent: `flush()` attaches the purge facts on `shutdown`.
        self::$queued[] = [
            'event'        => 'options.saved',
            'options_page' => $page_slug,
            'timestamp'    => time(),
        ];
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
     * No-op when WPGraphQL Smart Cache isn't installed (nothing but Core's own
     * recorder listens to the action), so this stays safe on standard WP sites.
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

        if (! self::smart_cache_listens()) {
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

        if (self::is_suspended()) {
            return;
        }

        $menu = wp_get_nav_menu_object($menu_id) ?: (self::$menu_cache[$menu_id] ?? null);
        if (! $menu) {
            return;
        }

        // Queued, not sent: `flush()` attaches the purge facts on `shutdown`.
        self::$queued[] = [
            'event'     => $event,
            'menu_id'   => $menu_id,
            'menu_name' => $menu->name,
            'menu_slug' => $menu->slug,
            'timestamp' => time(),
        ];
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
        $descendants = self::get_descendants($post);

        $payload = [
            'event'         => $event,
            'post_id'       => $post->ID,
            'post_type'     => $post->post_type,
            'post_slug'     => $post->post_name,
            'post_title'    => $post->post_title,
            'permalink'     => self::get_relative_permalink($post),
            // Filled in by `flush()`, which holds the captured old path.
            'old_permalink' => null,
            'language'      => self::get_language($post->ID),
            'translations'  => self::get_translations($post),
            'taxonomies'    => self::get_taxonomies($post),
            'descendants'   => $descendants['ids'],
            'embedders'     => self::get_embedders($post),
            'timestamp'     => time(),
        ];

        if ($descendants['truncated']) {
            $payload['descendants_truncated'] = true;
        }

        if ($old_status !== null && $new_status !== null) {
            $payload['old_status'] = $old_status;
            $payload['new_status'] = $new_status;
        }

        return $payload;
    }

    /**
     * The other-language versions of a post, under WPML.
     *
     * A translated page links to its siblings (language switcher, hreflang),
     * so a change to one — its slug above all — must revalidate the others.
     * Only published translations are listed: a draft has no live path to
     * revalidate. Empty when WPML is inactive or the post stands alone.
     *
     * @return list<array{post_id: int, language: string, permalink: string}>
     */
    private static function get_translations(WP_Post $post): array
    {
        if (! has_filter('wpml_element_trid') || ! has_filter('wpml_get_element_translations')) {
            return [];
        }

        $element_type = 'post_' . $post->post_type;

        $trid = apply_filters('wpml_element_trid', null, $post->ID, $element_type);
        if (! $trid) {
            return [];
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        if (! is_array($translations)) {
            return [];
        }

        $result = [];
        foreach ($translations as $translation) {
            $translation_id = (int) ($translation->element_id ?? 0);
            if ($translation_id === 0 || $translation_id === $post->ID) {
                continue;
            }

            $translated = get_post($translation_id);
            if (! $translated instanceof WP_Post || $translated->post_status !== 'publish') {
                continue;
            }

            $result[] = [
                'post_id'   => $translation_id,
                'language'  => (string) ($translation->language_code ?? ''),
                // WPML's `post_link` filter resolves the URL in the post's OWN
                // language, so no language switch is needed here.
                'permalink' => self::get_relative_permalink($translated),
            ];
        }

        return $result;
    }

    /**
     * IDs of the published descendants of a hierarchical post, every level
     * down.
     *
     * A child page's URL embeds its parents' slugs, so renaming or moving a
     * parent moves every page beneath it — and only WordPress can enumerate
     * them. Walked breadth-first with one query per level, capped so a
     * pathological tree cannot stall `shutdown`. Empty for non-hierarchical
     * post types.
     *
     * @return array{ids: list<int>, truncated: bool}
     */
    private static function get_descendants(WP_Post $post): array
    {
        if (! is_post_type_hierarchical($post->post_type)) {
            return ['ids' => [], 'truncated' => false];
        }

        /**
         * Filters how many descendant IDs a post payload may carry.
         *
         * When the tree is larger, the list is cut and the payload carries
         * `descendants_truncated: true` so the frontend can fall back to a
         * broader revalidation.
         *
         * @param int      $limit Maximum number of descendant IDs. Default 500.
         * @param \WP_Post $post  The post being reported.
         */
        $limit = max(0, (int) apply_filters('perimetre_core_webhook_descendants_limit', 500, $post));

        $ids       = [];
        $truncated = false;
        $parents   = [$post->ID];

        while ($parents !== [] && ! $truncated) {
            $children = get_posts([
                'post_type'              => $post->post_type,
                'post_status'            => 'publish',
                'post_parent__in'        => $parents,
                'fields'                 => 'ids',
                // One more than the remaining budget tells us the cut happened.
                'posts_per_page'         => $limit - count($ids) + 1,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]);

            $children = array_map('intval', $children);

            if (count($ids) + count($children) > $limit) {
                $children  = array_slice($children, 0, $limit - count($ids));
                $truncated = true;
            }

            $ids     = [...$ids, ...$children];
            $parents = $children;
        }

        return ['ids' => $ids, 'truncated' => $truncated];
    }

    /**
     * IDs of the posts that embed this one — a product showing a document, a
     * landing page pulling in a testimonial.
     *
     * Core cannot know a project's relationships, so this is a filter with an
     * empty default: Project Core answers it from its own ACF relationship or
     * meta queries.
     *
     * @return list<int>
     */
    private static function get_embedders(WP_Post $post): array
    {
        /**
         * Filters the IDs of posts that embed the saved post.
         *
         * @param list<int> $embedders Post IDs. Default empty.
         * @param int       $post_id   The saved post's ID.
         * @param \WP_Post  $post      The saved post.
         */
        $embedders = apply_filters('perimetre_core_webhook_embedders', [], $post->ID, $post);

        if (! is_array($embedders)) {
            return [];
        }

        $ids = [];
        foreach (array_unique(array_map('intval', $embedders)) as $id) {
            if ($id > 0 && $id !== $post->ID) {
                $ids[] = $id;
            }
        }

        return $ids;
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
