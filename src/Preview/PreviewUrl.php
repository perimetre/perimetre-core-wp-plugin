<?php

declare(strict_types=1);

namespace Perimetre\Core\Preview;

use WP_Post;

/**
 * Points WordPress's **Preview** button at the headless frontend's preview
 * route, signed so the frontend can trust it.
 *
 *     {frontend}/preview/{post type}/{post id}/?locale=<code>&exp=<unix s>&user=<editor id>&token=<hex hmac>
 *
 * `token = HMAC-SHA256("{id}|{type}|{locale}|{exp}|{user}", preview secret)`.
 * The secret never travels, so a link cannot be forged for another entry or
 * another user, and it expires after {@see self::TTL}. `user` is the editor who
 * built the link (`get_current_user_id()`); the frontend forwards the whole
 * signed payload back as its GraphQL credential and {@see TokenAuth} runs the
 * read as that editor. The frontend recomputes the same payload — keep
 * {@see self::payload()} byte-identical with its implementation.
 *
 * The locale rides in the QUERY STRING rather than the path, because that is
 * the one shape that works whether or not the frontend prefixes its routes with
 * a locale segment. A frontend that wants a different shape entirely can
 * rewrite the finished URL through the `perimetre_core_preview_url` filter.
 *
 * ## What a project must provide
 *
 * Core cannot know where the frontend lives or which post types it renders, so
 * one filter is required — everything else has a working default:
 *
 * - **`perimetre_core_preview_frontend_url`** `(?string $url, WP_Post $post)` —
 *   the frontend origin (no trailing slash) for this post, or `null` to leave
 *   the post without a frontend preview. Returning `null` per post is how a
 *   project opts out the types its frontend has no route for. Unfiltered, the
 *   whole feature stays off.
 * - `perimetre_core_preview_locale` `(string $locale, WP_Post $post)` — defaults
 *   to the post's WPML language code, falling back to the site locale's
 *   language.
 * - `perimetre_core_preview_url` `(string $url, WP_Post $post, array $params)` —
 *   last-resort rewrite of the finished link.
 */
final class PreviewUrl
{
    /** Route segment on the frontend (e.g. Next.js `app/preview/[type]/[id]`). */
    public const SEGMENT = 'preview';

    /** Link lifetime. Long enough for an editing session, short enough to expire a leaked link. */
    public const TTL = 12 * HOUR_IN_SECONDS;

    /**
     * Priority 20 so this wins over a project's own `preview_post_link`
     * permalink rewrite, which stays in place as the fallback for posts this
     * returns nothing for.
     */
    public static function register(): void
    {
        add_filter('preview_post_link', [self::class, 'filter_preview_post_link'], 20, 2);
    }

    /**
     * @param string $url  The link WordPress (or an earlier filter) resolved.
     * @param mixed  $post The post being previewed — a `WP_Post` on every core
     *                     call site, an id on some plugins'.
     */
    public static function filter_preview_post_link(string $url, mixed $post): string
    {
        if (is_numeric($post)) {
            $post = get_post((int) $post);
        }
        if (! $post instanceof WP_Post) {
            return $url;
        }

        return self::for($post) ?? $url;
    }

    /**
     * The signed preview link for `$post`, or `null` when previews are not
     * configured for it (no secret, or no frontend URL).
     */
    public static function for(WP_Post $post): ?string
    {
        $secret = Settings::secret();
        if ($secret === null) {
            return null;
        }

        /**
         * Filters the headless frontend origin a post previews on.
         *
         * @param string|null $url  Frontend origin, no trailing slash. May contain
         *                          `{locale}` for a per-language domain. `null` = no preview.
         * @param WP_Post     $post The post being previewed.
         */
        $base = apply_filters('perimetre_core_preview_frontend_url', null, $post);
        if (! is_string($base) || trim($base) === '') {
            return null;
        }

        // Built inside WP admin with the editor logged in — that identity is
        // what the frontend previews as. Anonymous (cron, CLI) → no link.
        $user = get_current_user_id();
        if ($user === 0) {
            return null;
        }

        $locale = self::locale_for($post);
        $exp = time() + self::TTL;
        $params = [
            'locale' => $locale,
            'exp'    => $exp,
            'user'   => $user,
            'token'  => hash_hmac(
                'sha256',
                self::payload($post->ID, (string) $post->post_type, $locale, $exp, $user),
                $secret
            ),
        ];

        // Trailing slash on purpose: a frontend running `trailingSlash: true`
        // would 308 the unslashed path first.
        $path = sprintf(
            '/%s/%s/%d/',
            self::SEGMENT,
            rawurlencode((string) $post->post_type),
            $post->ID
        );

        // `{locale}` in the origin supports a frontend that gives each language
        // its own domain, matching how project permalink templates read.
        $origin = rtrim(strtr(trim($base), ['{locale}' => $locale]), '/');

        $url = add_query_arg($params, $origin . $path);

        /**
         * Filters the finished preview link, for a frontend whose preview route
         * has a different shape (a locale path prefix, say).
         *
         * @param string               $url    The signed link.
         * @param WP_Post              $post   The post being previewed.
         * @param array<string, mixed> $params The signed query parameters.
         */
        return (string) apply_filters('perimetre_core_preview_url', $url, $post, $params);
    }

    /**
     * The post's language code: its WPML language, else the site locale's
     * language. Part of the signature, so the frontend cannot be handed a token
     * minted for another language.
     */
    public static function locale_for(WP_Post $post): string
    {
        $wpmlLang = apply_filters(
            'wpml_element_language_code',
            null,
            ['element_id' => $post->ID, 'element_type' => 'post_' . $post->post_type]
        );

        $locale = is_string($wpmlLang) && $wpmlLang !== ''
            ? $wpmlLang
            : substr(get_locale(), 0, 2);

        /**
         * Filters the language code baked into a preview link.
         *
         * @param string  $locale Language code.
         * @param WP_Post $post   The post being previewed.
         */
        return (string) apply_filters('perimetre_core_preview_locale', $locale, $post);
    }

    /** The signed string. Must stay byte-identical with the frontend's builder. */
    public static function payload(int $id, string $type, string $locale, int $exp, int $user): string
    {
        return $id . '|' . $type . '|' . $locale . '|' . $exp . '|' . $user;
    }
}
