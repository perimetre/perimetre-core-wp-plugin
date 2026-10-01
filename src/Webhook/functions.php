<?php

/**
 * Procedural helpers for batching webhooks around an import.
 *
 * Thin wrappers over `Perimetre\Core\Webhook\Dispatcher::suspend()` /
 * `resume()`, for WP-CLI commands and other procedural importers that would
 * otherwise fire one webhook per saved post.
 */

declare(strict_types=1);

use Perimetre\Core\Webhook\Dispatcher;

if (! function_exists('perimetre_core_webhooks_suspend')) {
    /**
     * Pause per-post webhooks. Posts saved until the matching
     * `perimetre_core_webhooks_resume()` are reported as ONE
     * `import.completed` event. Nestable.
     */
    function perimetre_core_webhooks_suspend(): void
    {
        Dispatcher::suspend();
    }
}

if (! function_exists('perimetre_core_webhooks_resume')) {
    /**
     * Counterpart of `perimetre_core_webhooks_suspend()`. Sends the collected
     * `import.completed` event when the outermost suspension ends.
     */
    function perimetre_core_webhooks_resume(): void
    {
        Dispatcher::resume();
    }
}
