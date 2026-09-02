<?php

declare(strict_types=1);

namespace Perimetre\Core\SEO;

/**
 * Yoast replacement variable that builds a meta description from ACF content.
 *
 * Yoast cannot see ACF. Its own `%%excerpt%%` falls back to `post_content` when
 * `post_excerpt` is empty, and `post_content` on an ACF-block page is nothing
 * but the block delimiter comments — `wp_strip_all_tags()` eats those, so the
 * fallback yields an empty string and pages ship with no meta description at
 * all. Classic-editor CPTs whose editorial text lives in ACF postmeta have the
 * same problem from the other direction: nothing ever reaches `post_content`.
 *
 * This registers `%%perimetre_excerpt%%`, which reads the prose out of wherever
 * ACF actually put it. Drop it into **SEO → Settings → Content types → <type> →
 * Meta description** and every post of that type without a hand-written
 * description gets one derived from its content. Nothing happens until you do:
 * a registered variable is inert until a template references it.
 *
 * It is a superset of Yoast's `%%excerpt%%`, not a replacement for it: content
 * ACF never touched — core blocks, Classic-editor HTML — falls back to the same
 * text the built-in variable would have produced. That matters because a Yoast
 * template concatenates its variables rather than picking the first non-empty
 * one, so a template cannot express a fallback and the variable has to. It is
 * therefore safe as a template's sole value on any site.
 *
 * It does not replace `%%cf_<field>%%`. Yoast's built-in custom-field variable
 * reads flat postmeta directly, so for a post type with one field that always
 * describes it, `%%cf_hero_description%%` is the better template — deterministic,
 * and no sweep to reason about. This variable is for what that cannot cover: ACF
 * *block* content, which lives as JSON in `post_content` and is unreachable from
 * postmeta, and post types with no single obvious field. Pick per content type.
 *
 * Nothing is stored. The value is computed when Yoast resolves a description
 * and thrown away, which means:
 *
 * - it tracks the content with no save hook, no staleness and no provenance
 *   flag to distinguish "generated" from "an editor wrote this";
 * - it applies to existing content immediately, with no backfill pass;
 * - it never writes, so it cannot fire a second `transition_post_status` and
 *   double-trigger a frontend rebuild ({@see \Perimetre\Core\Webhook\Dispatcher}).
 *
 * A description an editor typed always wins: Yoast only reaches for the content
 * type's template when the post's own `_yoast_wpseo_metadesc` is empty, and a
 * hand-written description contains no `%%` token, so this callback is never
 * even invoked for those posts.
 *
 * ### Cost
 *
 * The callback runs on most posts of any type whose template uses it, so the
 * work is kept flat rather than rare. Measured at 0.02–0.26 ms per post:
 *
 * - `parse_blocks()` duplicates a parse the same GraphQL request already
 *   performs for `editorBlocks`, over content already in memory — no query;
 * - ACF field *types* resolve through a request-static map. `acf_get_field()`
 *   is not a cheap array read: ACFML filters `acf/load_field` for string
 *   translation, so a naive per-field lookup would pay WPML on every field of
 *   every post. Field keys repeat across posts (they all draw on the same field
 *   groups), so the map converges after a post or two and a full-site build
 *   resolves each key once;
 * - the postmeta path deliberately avoids `get_fields()`, which runs
 *   `acf/format_value` over every field and fires extra queries expanding
 *   image, relationship and post-object fields. One already-cached
 *   `get_post_meta()` and the raw values are all this needs;
 * - collection stops once there is enough text for the limit, so a long page
 *   costs about what a short one costs.
 *
 * {@see \Perimetre\Core\CLI\SeoExcerptAuditCommand} measures it over real content.
 */
final class MetaDescriptionVariable
{
    /**
     * The variable, without Yoast's `%%` delimiters.
     *
     * Deliberately prefixed rather than named something natural like
     * `content_excerpt`: Yoast checks its own `retrieve_{$var}` methods *before*
     * externally registered replacements (`WPSEO_Replace_Vars::set_up_replacements()`),
     * so a future core variable of the same name would silently shadow this one
     * and change every description on every site running Core. The name is also
     * referenced from a per-site Yoast option, so renaming it later means
     * re-editing the template on every content type of every environment.
     */
    private const VARIABLE = 'perimetre_excerpt';

    /** ACF field types that hold prose worth describing a page with. */
    private const PROSE_FIELD_TYPES = ['text', 'textarea', 'wysiwyg'];

    /**
     * Character budget. Mirrors `WPSEO_Replace_Vars::retrieve_excerpt()`, which
     * cuts at 156 (80 for Japanese, whose characters carry more per glyph).
     */
    private const MAX_LENGTH = 156;
    private const MAX_LENGTH_JA = 80;

    /**
     * ACF field key => field type, for the life of the request.
     *
     * The win is across posts: one build pass over 200 pages resolves each
     * distinct key once instead of 200 times, and every lookup avoided is an
     * `acf/load_field` pass avoided (see the note on cost above).
     *
     * @var array<string, string>
     */
    private static array $field_types = [];

    /**
     * Post ID => generated description, for the life of the request.
     *
     * Yoast can ask for a description more than once per post — the meta
     * description and the Open Graph description share the fallback chain.
     *
     * @var array<int, string>
     */
    private static array $excerpts = [];

    public static function register(): void
    {
        add_action('wpseo_register_extra_replacements', [self::class, 'register_variable']);
    }

    /**
     * Yoast fires this the first time anything asks for a replacement, in any
     * request context — admin, front end, or a GraphQL query resolving
     * `seo { metaDesc }`.
     *
     * Yoast is not a Core dependency, so this no-ops cleanly when it is absent.
     */
    public static function register_variable(): void
    {
        if (! function_exists('wpseo_register_var_replacement')) {
            return;
        }

        wpseo_register_var_replacement(
            '%%' . self::VARIABLE . '%%',
            [self::class, 'resolve'],
            'advanced',
            'The first prose in the post\'s ACF blocks or fields, trimmed to meta description length.'
        );
    }

    /**
     * Resolves the variable for the post Yoast is currently describing.
     *
     * The second argument is *not* a `WP_Post`, despite the source being one:
     * Yoast casts it through `(object) wp_parse_args( $args, $this->defaults )`,
     * so what arrives is a `stdClass` carrying the post's fields plus term and
     * author defaults. Type-hinting `WP_Post` here would silently never match.
     * Its `post_content` has already had shortcodes stripped by Yoast.
     *
     * Returns an empty string rather than `null` when there is nothing to say.
     * Yoast drops `null` replacements and then strips the unreplaced token —
     * but only while `wpseo_replacements_final` is true, and a site that filters
     * that off would render a literal `%%perimetre_excerpt%%` as its description.
     *
     * @param mixed $variable The variable being replaced (unused — this callback
     *                        is registered for exactly one).
     * @param mixed $source   Yoast's merged source object.
     */
    public static function resolve($variable, $source): string
    {
        $post_id = isset($source->ID) ? (int) $source->ID : 0;
        if ($post_id <= 0) {
            return '';
        }

        if (array_key_exists($post_id, self::$excerpts)) {
            return self::$excerpts[$post_id];
        }

        $content = isset($source->post_content) && is_string($source->post_content)
            ? $source->post_content
            : '';

        self::$excerpts[$post_id] = self::build($post_id, $content);

        return self::$excerpts[$post_id];
    }

    /**
     * Blocks first, ACF postmeta second, raw content last.
     *
     * The first two are alternatives in practice rather than a priority order —
     * a block-editor page has no editorial postmeta and a Classic-editor CPT
     * has no blocks — but a page whose blocks hold no prose (a hero image and a
     * product grid, say) still gets a shot at its fields.
     *
     * The last covers Classic-editor content that is neither: plain HTML in
     * `post_content`, with no block delimiters to parse. Stripping raw content
     * is only safe once we know it holds no blocks — block delimiters carry
     * their attributes as JSON inside an HTML comment, and anything that leaked
     * through tag-stripping would end up in the description.
     */
    private static function build(int $post_id, string $content): string
    {
        $has_blocks = str_contains($content, '<!-- wp:');

        $pieces = $has_blocks ? self::collect_from_blocks(parse_blocks($content)) : [];

        if ($pieces === []) {
            $pieces = self::collect_from_fields($post_id);
        }

        if ($pieces === [] && ! $has_blocks && trim($content) !== '') {
            $pieces = [$content];
        }

        return self::condense($pieces);
    }

    /**
     * Walks the block tree in document order, collecting prose until there is
     * enough of it.
     *
     * Order is the page's own reading order, which is the best available proxy
     * for "what this page is about" — the hero heading and its supporting
     * paragraph come first because they are first on the page.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, string>
     */
    private static function collect_from_blocks(array $blocks): array
    {
        $pieces = [];
        $length = 0;
        $limit = self::max_length();
        $excluded = self::excluded_blocks();

        foreach ($blocks as $block) {
            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';
            $data = $block['attrs']['data'] ?? null;

            if (! in_array($name, $excluded, true)) {
                if (is_array($data)) {
                    foreach (self::prose_values($data) as $value) {
                        $pieces[] = $value;
                        $length += mb_strlen($value);
                    }
                } else {
                    $text = self::inner_text($block);
                    if ($text !== '') {
                        $pieces[] = $text;
                        $length += mb_strlen($text);
                    }
                }
            }

            if ($length < $limit && ! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                foreach (self::collect_from_blocks($block['innerBlocks']) as $value) {
                    $pieces[] = $value;
                    $length += mb_strlen($value);
                }
            }

            if ($length >= $limit) {
                break;
            }
        }

        return $pieces;
    }

    /**
     * The visible text a non-ACF block contributes.
     *
     * Core blocks keep their content in `innerHTML` rather than in attributes,
     * so without this a page built from `core/paragraph` and `core/heading`
     * yields nothing — and Yoast's own `%%excerpt%%` would have handled it,
     * since that text survives in `post_content`. Reading it here is what keeps
     * this variable a superset of the built-in one.
     *
     * Container blocks are safe to include. The parser puts only the wrapper
     * markup in a container's `innerHTML` and hands the children over as
     * `innerBlocks`, so nothing is collected twice. Dynamic blocks — which
     * render server-side and store nothing — strip to an empty string and are
     * skipped. A `null` block name is Classic-editor content sitting between
     * blocks, and its text counts.
     *
     * @param array<string, mixed> $block
     */
    private static function inner_text(array $block): string
    {
        $html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';

        return $html === '' ? '' : trim(wp_strip_all_tags($html));
    }

    /**
     * Reads an ACF block's saved field values.
     *
     * ACF stores block data in the delimiter as `name => value` alongside a
     * `_name => field_key` companion for each, and flattens repeaters into
     * `items_0_title`. Because every value carries its own key, sub-fields
     * resolve their type exactly like top-level ones — no repeater-aware
     * traversal needed.
     *
     * The field key is what makes this safe. A block with a `layout` select
     * whose value is the literal string "centered" is common; a "looks like
     * prose" heuristic would put that in the meta description. Only the ACF
     * field type keeps it out.
     *
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private static function prose_values(array $data): array
    {
        $values = [];
        $types = self::prose_types();

        foreach ($data as $name => $value) {
            if (! is_string($value) || str_starts_with((string) $name, '_') || trim($value) === '') {
                continue;
            }

            $key = $data['_' . $name] ?? null;
            if (! is_string($key) || ! str_starts_with($key, 'field_')) {
                continue;
            }

            if (in_array(self::field_type($key), $types, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Reads prose out of a post's ACF postmeta, for content that never had
     * blocks — Classic-editor CPTs whose text is all in fields.
     *
     * One `get_post_meta()` call, already primed by whatever loaded the post,
     * returns both the values and ACF's `_name => field_key` companions.
     * `get_fields()` would be the obvious call and is the wrong one: it runs
     * `acf/format_value` over every field, which expands images, relationships
     * and post objects with additional queries — a lot of work to produce one
     * sentence.
     *
     * Ordering here is postmeta insertion order rather than field-group order.
     * In practice that is the order ACF first wrote the fields, which tracks the
     * group, but it is not guaranteed the way block order is. A post type with
     * one field that always describes it is better served by Yoast's own
     * `%%cf_<field>%%` than by this sweep.
     *
     * @return array<int, string>
     */
    private static function collect_from_fields(int $post_id): array
    {
        $meta = get_post_meta($post_id);
        if (! is_array($meta) || $meta === []) {
            return [];
        }

        $pieces = [];
        $length = 0;
        $limit = self::max_length();
        $types = self::prose_types();

        foreach ($meta as $name => $values) {
            if (str_starts_with((string) $name, '_') || ! is_array($values)) {
                continue;
            }

            $key = $meta['_' . $name][0] ?? null;
            if (! is_string($key) || ! str_starts_with($key, 'field_')) {
                continue;
            }

            $value = $values[0] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (! in_array(self::field_type($key), $types, true)) {
                continue;
            }

            $pieces[] = $value;
            $length += mb_strlen($value);

            if ($length >= $limit) {
                break;
            }
        }

        return $pieces;
    }

    /**
     * The ACF type for a field key, resolved once per request per key.
     *
     * Returns an empty string for keys ACF cannot resolve — a field from a
     * block that has since been removed, say — which simply fails the prose
     * test and is skipped. ACF is not a hard dependency of this class either.
     */
    private static function field_type(string $key): string
    {
        if (array_key_exists($key, self::$field_types)) {
            return self::$field_types[$key];
        }

        $type = '';
        if (function_exists('acf_get_field')) {
            $field = acf_get_field($key);
            if (is_array($field) && isset($field['type']) && is_string($field['type'])) {
                $type = $field['type'];
            }
        }

        self::$field_types[$key] = $type;

        return $type;
    }

    /**
     * Flattens the collected values into one description-shaped sentence.
     *
     * @param array<int, string> $pieces
     */
    private static function condense(array $pieces): string
    {
        if ($pieces === []) {
            return '';
        }

        $text = implode(' ', $pieces);
        $text = strip_shortcodes($text);
        // Tags first, then entities: decoding first could reintroduce markup.
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        $limit = self::max_length();
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $trimmed = mb_substr($text, 0, $limit);
        $last_space = mb_strrpos($trimmed, ' ');
        if ($last_space !== false) {
            $trimmed = mb_substr($trimmed, 0, $last_space);
        }

        return rtrim($trimmed, " \t\n\r\0\x0B,;:-");
    }

    private static function max_length(): int
    {
        $default = get_locale() === 'ja' ? self::MAX_LENGTH_JA : self::MAX_LENGTH;

        return max(1, (int) apply_filters('perimetre_core_seo_excerpt_max_length', $default));
    }

    /**
     * @return array<int, string>
     */
    private static function prose_types(): array
    {
        /** @var array<int, string> $types */
        $types = apply_filters('perimetre_core_seo_excerpt_field_types', self::PROSE_FIELD_TYPES);

        return is_array($types) ? $types : self::PROSE_FIELD_TYPES;
    }

    /**
     * Block names to skip, by registered name (`acf/project-bestsellers`).
     *
     * Empty by default — the field-type test already excludes most of what you
     * would not want. This is the per-project escape hatch for a block whose
     * prose is real but useless as a page description.
     *
     * @return array<int, string>
     */
    private static function excluded_blocks(): array
    {
        /** @var array<int, string> $excluded */
        $excluded = apply_filters('perimetre_core_seo_excerpt_excluded_blocks', []);

        return is_array($excluded) ? $excluded : [];
    }
}
