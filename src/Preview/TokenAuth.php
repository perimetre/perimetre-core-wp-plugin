<?php

declare(strict_types=1);

namespace Perimetre\Core\Preview;

use WP_User;

/**
 * Authenticates the frontend's preview reads AS THE EDITOR who clicked Preview.
 *
 * {@see PreviewUrl} signs `id|type|locale|exp|user` into every preview link. The
 * frontend's preview route verifies that signature, then forwards the very same
 * payload + signature to WPGraphQL as `X-Preview-Auth`. This filter re-verifies
 * it against the same secret and, if valid, tells WordPress the current user is
 * `user` — so the GraphQL request runs with the editor's own capabilities
 * (drafts, pending, private entries, `asPreview` revisions) and no service
 * account or Application Password has to exist anywhere.
 *
 * Scope: GraphQL requests only, and only when nothing else already
 * authenticated the request (a logged-in cookie, an Application Password). The
 * token is a read credential for one entry's editor for at most
 * {@see PreviewUrl::TTL}; the check below refuses users who lost `edit_posts`
 * since the link was minted. The `type`/`id` in the payload are NOT used to
 * scope the GraphQL request itself — the payload just binds the signature to
 * one link.
 *
 * Failure is silent by design (returns the incoming user, i.e. anonymous), so a
 * bad header degrades to "draft not visible" — the frontend's 404 — rather than
 * a GraphQL error a public client could ever see.
 */
final class TokenAuth
{
    public const HEADER = 'X-Preview-Auth';

    /**
     * Re-entrancy guard. Anything that resolves capabilities inside this filter
     * (`user_can`, `current_user_can`, WPGraphQL helpers) can trigger
     * `wp_get_current_user()` → `determine_current_user` → here again, and that
     * loop runs until PHP exhausts memory. Keep the body free of such calls AND
     * refuse to nest, so a plugin hooking `user_has_cap` cannot bring it back.
     */
    private static bool $resolving = false;

    /** Set once a request was authenticated by this token; read by {@see preserve_authentication()}. */
    private static bool $authenticated = false;

    public static function register(): void
    {
        add_filter('determine_current_user', [self::class, 'determine_current_user'], 20);
        add_filter('graphql_authentication_errors', [self::class, 'preserve_authentication']);
    }

    /**
     * WPGraphQL >= 2.6 treats any logged-in request WITHOUT an `Authorization`
     * header as cookie auth and downgrades it to guest unless a nonce is present
     * (CSRF protection, `Router::validate_http_request_authentication`). A
     * token-authenticated request carries neither, so it would be silently
     * downgraded; returning `false` here is the documented way to say "this
     * authentication is not cookie-based, keep it". Only when WE authenticated —
     * a real cookie session stays subject to the nonce rule.
     *
     * @param mixed $errors `null` = default behaviour.
     * @return mixed
     */
    public static function preserve_authentication(mixed $errors): mixed
    {
        return self::$authenticated ? false : $errors;
    }

    /**
     * @param mixed $userId The user id resolved so far (0 / false when anonymous).
     * @return mixed
     */
    public static function determine_current_user(mixed $userId): mixed
    {
        if (! empty($userId) || self::$resolving) {
            return $userId;
        }
        if (! self::is_graphql_request()) {
            return $userId;
        }

        self::$resolving = true;
        try {
            return self::resolve() ?? $userId;
        } finally {
            self::$resolving = false;
        }
    }

    private static function resolve(): ?int
    {
        $header = self::header();
        if ($header === '') {
            return null;
        }

        $parsed = self::parse($header);
        if ($parsed === null) {
            return null;
        }

        $secret = Settings::secret();
        if ($secret === null) {
            return null;
        }

        $expected = hash_hmac(
            'sha256',
            PreviewUrl::payload($parsed['id'], $parsed['type'], $parsed['locale'], $parsed['exp'], $parsed['user']),
            $secret
        );
        if (! hash_equals($expected, $parsed['token'])) {
            return null;
        }
        if ($parsed['exp'] < time()) {
            return null;
        }

        $user = get_user_by('id', $parsed['user']);
        // `allcaps` is filled from the user's roles in `WP_User::init` with NO
        // filters involved — unlike `user_can()`, which runs `user_has_cap` and
        // re-enters the current-user resolution (see `$resolving`).
        if (! $user instanceof WP_User || empty($user->allcaps['edit_posts'])) {
            return null;
        }

        self::$authenticated = true;

        return $user->ID;
    }

    /**
     * Split `id|type|locale|exp|user|token` — what the frontend sends as
     * {@see self::HEADER}. `null` on any shape mismatch.
     *
     * @return array{id: int, type: string, locale: string, exp: int, user: int, token: string}|null
     */
    public static function parse(string $header): ?array
    {
        $parts = explode('|', $header);
        if (count($parts) !== 6) {
            return null;
        }
        [$id, $type, $locale, $exp, $user, $token] = $parts;
        if (! ctype_digit($id) || ! ctype_digit($exp) || ! ctype_digit($user) || $type === '' || $locale === '') {
            return null;
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return [
            'id'     => (int) $id,
            'type'   => $type,
            'locale' => $locale,
            'exp'    => (int) $exp,
            'user'   => (int) $user,
            'token'  => $token,
        ];
    }

    private static function header(): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', self::HEADER));
        $value = $_SERVER[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Match the endpoint path directly — the same `graphql` slug WPGraphQL
     * routes on. Not `is_graphql_http_request()`: it is not reliable this early
     * (`determine_current_user` can fire before WPGraphQL's `init` work) and
     * must not be given a chance to resolve the current user from inside its own
     * resolution.
     */
    private static function is_graphql_request(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';

        return (bool) preg_match('#(^|/)graphql/?$#', $path);
    }
}
