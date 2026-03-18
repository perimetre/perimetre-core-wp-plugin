<?php

declare(strict_types=1);

namespace Perimetre\Core\GraphQL;

/**
 * GraphQL registration utilities.
 *
 * Wrappers around WPGraphQL's registration functions that enforce naming
 * conventions across all projects. Use these instead of calling WPGraphQL
 * functions directly from Project Core.
 *
 * Naming conventions enforced:
 * - Type names must be PascalCase (e.g. 'HeroBlock', 'FeaturedPost')
 * - Field names must be camelCase (e.g. 'heading', 'featuredPosts')
 * - Shared concept names are consistent: 'heading' not 'title',
 *   'cta { label url }' not 'button', 'image' not 'photo'
 *
 * Usage in Project Core:
 *
 *   use Perimetre\Core\GraphQL\Registry;
 *
 *   add_action('graphql_register_types', function () {
 *       Registry::register_field('Post', 'formattedDate', [
 *           'type'        => 'String',
 *           'description' => 'The post date formatted for display.',
 *           'resolve'     => fn ($post) => get_the_date('F j, Y', $post->databaseId),
 *       ]);
 *   });
 */
final class Registry
{
    /**
     * Register a GraphQL field on an existing type.
     *
     * Enforces camelCase field naming.
     *
     * @param string $type_name The GraphQL type to add the field to (e.g. 'Post', 'Page').
     * @param string $field_name The field name in camelCase (e.g. 'formattedDate').
     * @param array<string, mixed> $config The field configuration passed to register_graphql_field().
     */
    public static function register_field(string $type_name, string $field_name, array $config): void
    {
        if (! self::is_camel_case($field_name)) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'GraphQL field name "%s" must be camelCase (e.g. "%s"). Field was not registered.',
                    esc_html($field_name),
                    esc_html(lcfirst(str_replace('_', '', ucwords($field_name, '_'))))
                ),
                '1.0.0'
            );
            return;
        }

        register_graphql_field($type_name, $field_name, $config);
    }

    /**
     * Register a new GraphQL object type.
     *
     * Enforces PascalCase type naming.
     *
     * @param string $type_name The type name in PascalCase (e.g. 'HeroBlock', 'Cta').
     * @param array<string, mixed> $config The type configuration passed to register_graphql_object_type().
     */
    public static function register_object_type(string $type_name, array $config): void
    {
        if (! self::is_pascal_case($type_name)) {
            _doing_it_wrong(
                __METHOD__,
                sprintf(
                    'GraphQL type name "%s" must be PascalCase (e.g. "%s"). Type was not registered.',
                    esc_html($type_name),
                    esc_html(ucfirst($type_name))
                ),
                '1.0.0'
            );
            return;
        }

        register_graphql_object_type($type_name, $config);
    }

    /**
     * Bootstrap any Perimetre-level GraphQL types and fields.
     * Hooked to 'graphql_register_types' in perimetre-core.php.
     */
    public static function register(): void
    {
        // Perimetre-level types and fields are registered here as needed.
        // Project Core registers its own types and fields via 'graphql_register_types'
        // using the utilities above.
    }

    /**
     * Check if a string is camelCase.
     */
    private static function is_camel_case(string $value): bool
    {
        return (bool) preg_match('/^[a-z][a-zA-Z0-9]*$/', $value);
    }

    /**
     * Check if a string is PascalCase.
     */
    private static function is_pascal_case(string $value): bool
    {
        return (bool) preg_match('/^[A-Z][a-zA-Z0-9]*$/', $value);
    }
}
