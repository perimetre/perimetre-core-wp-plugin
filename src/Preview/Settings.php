<?php

declare(strict_types=1);

namespace Perimetre\Core\Preview;

/**
 * The preview-link signing secret, and its field on the Perimetre Core
 * settings page.
 *
 * One shared secret drives the whole flow: {@see PreviewUrl} signs every
 * Preview link with it, and {@see TokenAuth} verifies the same signature coming
 * back from the frontend. The headless frontend holds the identical value in
 * its own environment (`PREVIEW_SECRET`), so the secret itself never travels
 * over the wire.
 *
 * Unset is the default and it means the feature is OFF: no signed links, no
 * editor pane, and `TokenAuth` authenticates nothing. That keeps this inert on
 * every site that has not opted in.
 */
final class Settings
{
    /**
     * wp-config constant that OVERRIDES the stored secret, to keep it out of
     * the database. When DEFINED it is authoritative — an empty constant
     * therefore disables preview links rather than silently falling back to the
     * option.
     */
    public const SECRET_CONST = 'PERIMETRE_PREVIEW_SECRET';

    private const FIELD_GROUP_KEY = 'group_perimetre_preview';
    private const OPTION_NAME = 'options_perimetre_preview_secret';

    public static function register(): void
    {
        add_action('acf/init', [self::class, 'register_field_group']);
    }

    /**
     * A second field group on the existing **Settings > Perimetre Core** page
     * (ACF stacks groups as separate postboxes), so preview configuration sits
     * with the webhook configuration the same frontend consumes.
     */
    public static function register_field_group(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'      => self::FIELD_GROUP_KEY,
            'title'    => __('Frontend Preview', 'perimetre-core'),
            'location' => [
                [
                    [
                        'param'    => 'options_page',
                        'operator' => '==',
                        'value'    => \Perimetre\Core\Webhook\Settings::PAGE_SLUG,
                    ],
                ],
            ],
            'fields' => [
                [
                    'key'          => 'field_perimetre_preview_secret',
                    'name'         => 'perimetre_preview_secret',
                    'label'        => __('Preview secret', 'perimetre-core'),
                    'type'         => 'password',
                    'instructions' => self::instructions(),
                    'readonly'     => defined(self::SECRET_CONST) ? 1 : 0,
                ],
            ],
        ]);
    }

    private static function instructions(): string
    {
        if (defined(self::SECRET_CONST)) {
            return sprintf(
                /* translators: %s: name of the wp-config PHP constant. */
                __(
                    'Defined by the %s constant in wp-config.php, which takes precedence over this field.',
                    'perimetre-core'
                ),
                self::SECRET_CONST
            );
        }

        return sprintf(
            /* translators: 1: frontend environment variable name, 2: name of the wp-config PHP constant. */
            __(
                'Shared with the headless frontend as %1$s. Signs every Preview link (HMAC-SHA256, 12h expiry); '
                . 'the frontend verifies it and sends it back as its GraphQL credential, so drafts are read as the '
                . 'editor who clicked Preview. Any long random string (openssl rand -hex 32). '
                . 'Leave empty to disable frontend previews, or define %2$s in wp-config.php to keep it out of '
                . 'the database.',
                'perimetre-core'
            ),
            'PREVIEW_SECRET',
            self::SECRET_CONST
        );
    }

    /**
     * The signing secret, or `null` when the feature is off.
     *
     * Reads the option directly rather than through `get_field()`: this runs on
     * `determine_current_user` (see {@see TokenAuth}), long before ACF has
     * booted on a GraphQL request.
     */
    public static function secret(): ?string
    {
        if (defined(self::SECRET_CONST)) {
            $constant = (string) constant(self::SECRET_CONST);

            return $constant !== '' ? $constant : null;
        }

        $stored = get_option(self::OPTION_NAME);
        $secret = is_string($stored) ? trim($stored) : '';

        return $secret !== '' ? $secret : null;
    }
}
