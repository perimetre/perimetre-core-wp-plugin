<?php

declare(strict_types=1);

namespace Perimetre\Core\Status;

/**
 * Registers the rewrite rule and handles requests for the status endpoint.
 */
final class Endpoint
{
    public const QUERY_VAR = 'perimetre_status';
    public const CRON_HOOK = 'perimetre_status_cron';

    public static function register(): void
    {
        add_action('init', [self::class, 'add_rewrite_rule']);
        add_filter('query_vars', [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'handle_request']);
        add_action('admin_init', [self::class, 'maybe_flush_rewrite_rules']);

        // Cron event to record last run time.
        add_action(self::CRON_HOOK, [HealthChecks::class, 'record_cron_run']);
    }

    /**
     * Schedule cron and flush rewrite rules on plugin activation.
     */
    public static function activate(): void
    {
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }

        // Rewrite rule isn't registered yet at activation time,
        // so register it before flushing.
        self::add_rewrite_rule();
        flush_rewrite_rules();
    }

    public static function add_rewrite_rule(): void
    {
        $slug = Settings::get_slug();
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public static function add_query_var(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function handle_request(): void
    {
        if (! get_query_var(self::QUERY_VAR)) {
            return;
        }

        if (! Settings::is_enabled()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            echo esc_html__('404 Not Found', 'perimetre-core');
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public endpoint, no nonce
        $provided_token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $stored_token = Settings::get_token();

        $authenticated = $stored_token !== '' && $provided_token !== '' && hash_equals($stored_token, $provided_token);

        if ($authenticated) {
            $payload = HealthChecks::run_all();
            $status_code = $payload['status'] === 'ok' ? 200 : 500;
        } else {
            $payload = ['status' => 'ok'];
            $status_code = 200;
        }

        nocache_headers();
        status_header($status_code);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function maybe_flush_rewrite_rules(): void
    {
        if (get_option('perimetre_status_flush_rewrite')) {
            delete_option('perimetre_status_flush_rewrite');
            flush_rewrite_rules();
        }
    }

    /**
     * Clean up on plugin deactivation.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        flush_rewrite_rules();
    }
}
