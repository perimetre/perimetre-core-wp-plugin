<?php

declare(strict_types=1);

namespace Perimetre\Core;

/**
 * Top-level plugin bootstrap helpers.
 */
final class Plugin
{
    public static function load_textdomain(): void
    {
        load_plugin_textdomain('perimetre-core', false, dirname(plugin_basename(PERIMETRE_CORE_FILE)) . '/languages');
    }
}
