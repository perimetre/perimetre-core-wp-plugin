<?php

declare(strict_types=1);

namespace Perimetre\Core\Admin;

/**
 * Renders the shared tab strip that ties the Perimetre Core admin surface
 * together. Three tabs:
 *
 *   - status        → options-general.php?page=perimetre-core (default tab)
 *   - remote-login  → options-general.php?page=perimetre-core&tab=remote-login
 *   - webhooks      → options-general.php?page=acf-options-webhooks
 *
 * The Webhooks tab links to a separate underlying ACF options page; its
 * menu entry is hidden so all three tabs appear under a single "Perimetre
 * Core" entry in Settings.
 */
final class Tabs
{
    public const TAB_STATUS       = 'status';
    public const TAB_REMOTE_LOGIN = 'remote-login';
    public const TAB_WEBHOOKS     = 'webhooks';

    public static function render(string $active): void
    {
        $tabs = [
            self::TAB_STATUS => [
                'label' => __('Status', 'perimetre-core'),
                'url'   => admin_url('options-general.php?page=perimetre-core'),
            ],
            self::TAB_REMOTE_LOGIN => [
                'label' => __('Remote Login', 'perimetre-core'),
                'url'   => admin_url('options-general.php?page=perimetre-core&tab=remote-login'),
            ],
            self::TAB_WEBHOOKS => [
                'label' => __('Webhooks', 'perimetre-core'),
                'url'   => admin_url('options-general.php?page=acf-options-webhooks'),
            ],
        ];

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $tab) {
            $class = 'nav-tab' . ($key === $active ? ' nav-tab-active' : '');
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($tab['url']),
                esc_attr($class),
                esc_html($tab['label'])
            );
        }
        echo '</h2>';
    }
}
