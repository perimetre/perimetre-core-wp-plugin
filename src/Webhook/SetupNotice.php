<?php

declare(strict_types=1);

namespace Perimetre\Core\Webhook;

/**
 * Warns admins when enabled webhooks are set up in a way that leaves the
 * headless frontend serving stale pages.
 *
 * New defaults only reach new installs: an existing site keeps the Watched
 * Events it saved. This notice is what catches the next site where someone
 * forgot a checkbox or never installed WPGraphQL Smart Cache.
 *
 * Shown only on the Perimetre Core settings page, to users who can change
 * it, and only while webhooks are enabled (a site not using them is not
 * misconfigured): whoever sets up webhooks sees it, client admins elsewhere
 * in the admin do not.
 *
 * A site that leaves an event off on purpose answers the
 * `perimetre_core_webhook_setup_notice` filter.
 */
final class SetupNotice
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options') || ! self::on_relevant_screen() || ! Settings::is_enabled()) {
            return;
        }

        $problems = self::problems();
        if ($problems === []) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong></p>'
                . '<ul style="list-style:disc;padding-left:1.5em">',
            esc_html__('Perimetre Core webhooks may leave stale pages on the frontend:', 'perimetre-core')
        );

        foreach ($problems as $problem) {
            printf('<li>%s</li>', esc_html($problem));
        }

        echo '</ul></div>';
    }

    /**
     * @return list<string>
     */
    private static function problems(): array
    {
        $problems = [];

        /**
         * The Watched Events this site is expected to have on; the notice lists
         * the ones that are off. Return a smaller list for a site that leaves
         * an event off on purpose, or `false` to silence the notice entirely
         * (the Smart Cache warning included).
         *
         * @param list<string>|false $expected Every Watched Event value by default.
         */
        $expected = apply_filters('perimetre_core_webhook_setup_notice', array_keys(Settings::event_choices()));
        if ($expected === false) {
            return [];
        }

        if (! Dispatcher::smart_cache_listens()) {
            $problems[] = __(
                'WPGraphQL Smart Cache is not active, so payloads carry no purge keys and term, media '
                . 'and user changes never reach the frontend.',
                'perimetre-core'
            );
        }

        $expected = is_array($expected) ? array_map('strval', $expected) : [];
        $missing  = array_diff_key(
            array_intersect_key(Settings::event_choices(), array_flip($expected)),
            array_flip(Settings::get_events())
        );
        if ($missing !== []) {
            $labels = array_values($missing);

            $problems[] = sprintf(
                /* translators: %s: comma-separated list of watched event names. */
                __('These Watched Events are off, so those changes never reach the frontend: %s.', 'perimetre-core'),
                implode(', ', $labels)
            );
        }

        return $problems;
    }

    private static function on_relevant_screen(): bool
    {
        if (! function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if ($screen === null) {
            return false;
        }

        return str_ends_with($screen->id, '_page_' . Settings::PAGE_SLUG);
    }
}
