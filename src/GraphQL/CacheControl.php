<?php

declare(strict_types=1);

namespace Perimetre\Core\GraphQL;

/**
 * Per-request edge caching of WPGraphQL responses.
 *
 * Opt-in via a `?edgeCache=<seconds>` query var the frontend appends to
 * specific calls (search, build-time). The global default stays uncacheable
 * (`global_max_age=0`); only requests carrying the var become cacheable.
 *
 * A query var is used rather than a request header because it is guaranteed to
 * reach PHP (it is in the URL Varnish forwards) and it is part of Varnish's
 * `hash_data(req.url)` cache key, so opted-in vs. plain requests never collide.
 * A custom header would require the managed Cloudways VCL to forward it and
 * `Vary` on it — neither of which we can guarantee.
 *
 * Invalidation is TTL-only: Cloudways cannot purge by tag, so a cached
 * response serves stale up to its TTL after content changes. Keep TTLs short
 * (30–60s) for anything where staleness is user-visible.
 */
final class CacheControl
{
    /**
     * Upper bound on the requested TTL, in seconds.
     */
    private const MAX_TTL = 3600;

    /**
     * Hook the cache-control filter.
     *
     * Runs at PHP_INT_MAX so it lands after WPGraphQL Smart Cache's
     * priority-10 filter and has the final say on the header.
     */
    public static function register(): void
    {
        add_filter('graphql_response_headers_to_send', [self::class, 'filter_headers'], PHP_INT_MAX);
    }

    /**
     * Make the response cacheable when a valid `?edgeCache=<seconds>` var is present.
     *
     * @param array<string, string> $headers The response headers WPGraphQL will send.
     * @return array<string, string> The (possibly modified) headers.
     */
    public static function filter_headers(array $headers): array
    {
        if (is_user_logged_in()) {
            return $headers; // auth responses stay no-store (Smart Cache also enforces this)
        }

        $raw = $_GET['edgeCache'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
        if (! is_string($raw) || ! ctype_digit($raw)) {
            return $headers;
        }

        $ttl = (int) $raw;
        if ($ttl <= 0) {
            return $headers;
        }

        $ttl = min($ttl, self::MAX_TTL);
        $headers['Cache-Control'] = sprintf('max-age=%1$d, s-maxage=%1$d, must-revalidate', $ttl);

        return $headers;
    }
}
