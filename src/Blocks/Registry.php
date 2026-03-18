<?php

declare(strict_types=1);

namespace Perimetre\Core\Blocks;

/**
 * Central block registry.
 *
 * All blocks — whether defined in Perimetre Core or Project Core — are
 * registered here. This is the single entry point for block registration.
 *
 * Perimetre Core registers its own shared blocks internally.
 * Project Core registers its project-specific blocks by calling register_block()
 * before the 'init' hook fires (typically on 'plugins_loaded').
 *
 * Usage in Project Core:
 *
 *   use Perimetre\Core\Blocks\Registry;
 *
 *   add_action('plugins_loaded', function () {
 *       Registry::register_block(\MicroBird\Core\Blocks\HeroBlock::class);
 *       Registry::register_block(\MicroBird\Core\Blocks\FeaturedPostsBlock::class);
 *   });
 */
final class Registry
{
    /**
     * Registered block class names.
     *
     * @var array<class-string<AcfBlock|NativeBlock>>
     */
    private static array $blocks = [];

    /**
     * Register a block class for later instantiation and registration.
     *
     * @param class-string<AcfBlock|NativeBlock> $class Fully qualified class name.
     */
    public static function register_block(string $class): void
    {
        if (in_array($class, self::$blocks, true)) {
            return;
        }

        self::$blocks[] = $class;
    }

    /**
     * Instantiate and register all registered blocks.
     * Hooked to 'init' in perimetre-core.php.
     */
    public static function register(): void
    {
        self::register_shared_blocks();

        foreach (self::$blocks as $class) {
            if (! class_exists($class)) {
                _doing_it_wrong(
                    __METHOD__,
                    sprintf('Block class "%s" does not exist.', esc_html($class)),
                    '1.0.0'
                );
                continue;
            }

            (new $class())->register();
        }
    }

    /**
     * Register Perimetre Core's own shared blocks.
     * Add new shared blocks here as they are created.
     */
    private static function register_shared_blocks(): void
    {
        // Shared blocks are registered here as they are added to Perimetre Core.
        // Example:
        // self::register_block(\Perimetre\Core\Blocks\Shared\HeroBlock::class);
    }
}
