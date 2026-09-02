<?php

declare(strict_types=1);

namespace Perimetre\Core\CLI;

use Perimetre\Core\SEO\MetaDescriptionVariable;
use WP_CLI;
use WP_Query;

/**
 * Reports what {@see MetaDescriptionVariable} would produce, and what it costs.
 *
 * Two questions at once: are the generated descriptions any good on *this*
 * project's content, and is computing them on every request affordable. Run it
 * over a post type before wiring `%%perimetre_excerpt%%` into that type's Yoast
 * template — it calls the resolver directly, so nothing has to be configured
 * first and nothing is written.
 *
 * Worth running on any project newly adopting the variable: the block walker's
 * assumptions hold for ACF's documented storage shape, but every project's
 * field groups are its own.
 *
 * Timings are cumulative in processing order, which mirrors a full-site build:
 * the first post pays for a cold ACF field-type map, later ones read it warm.
 * The gap between the first timing and the average is the value of that map.
 */
final class SeoExcerptAuditCommand
{
    /**
     * Audits the generated meta description fallback for a post type.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Post type to audit. Default: page.
     *
     * [--limit=<n>]
     * : Audit at most N posts. Default: all.
     *
     * [--empty]
     * : List only posts that produce nothing — the ones that would still ship
     *   without a meta description.
     *
     * [--timings-only]
     * : Report the timings without the per-post text. (Not `--quiet` — that is
     *   a reserved WP-CLI global flag that suppresses all output, including
     *   this command's.)
     *
     * ## EXAMPLES
     *
     *     wp perimetre:seo-excerpt-audit
     *     wp perimetre:seo-excerpt-audit --post_type=product --limit=50
     *     wp perimetre:seo-excerpt-audit --post_type=page --empty
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args
     * @param array<string, string> $assoc_args
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        $post_type = isset($assoc_args['post_type']) ? (string) $assoc_args['post_type'] : 'page';
        $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : -1;
        $empty_only = ! empty($assoc_args['empty']);
        $timings_only = ! empty($assoc_args['timings-only']);

        if (! post_type_exists($post_type)) {
            WP_CLI::error(sprintf('Unknown post type "%s".', $post_type));
        }

        $query = new WP_Query([
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_term_cache' => false,
        ]);

        if ($query->posts === []) {
            WP_CLI::warning(sprintf('No published %s posts found.', $post_type));
            return;
        }

        $timings = [];
        $empty = 0;

        foreach ($query->posts as $post) {
            $source = (object) [
                'ID'           => $post->ID,
                'post_content' => $post->post_content,
            ];

            $start = hrtime(true);
            $excerpt = MetaDescriptionVariable::resolve(null, $source);
            $timings[] = (hrtime(true) - $start) / 1_000_000;

            if ($excerpt === '') {
                $empty++;
            }

            if ($timings_only || ($empty_only && $excerpt !== '')) {
                continue;
            }

            WP_CLI::log(sprintf(
                '#%d  %s%s%s',
                $post->ID,
                $post->post_title,
                PHP_EOL,
                $excerpt === '' ? '    (nothing)' : '    ' . $excerpt
            ));
        }

        $this->report_timings($timings, count($query->posts), $empty);
    }

    /**
     * @param array<int, float> $timings Milliseconds, in processing order.
     */
    private function report_timings(array $timings, int $total, int $empty): void
    {
        $sum = array_sum($timings);

        WP_CLI::log('');
        WP_CLI::log(sprintf('Posts:      %d (%d produced nothing)', $total, $empty));
        WP_CLI::log(sprintf('Total:      %.1f ms', $sum));
        WP_CLI::log(sprintf('Average:    %.2f ms/post', $sum / max(1, $total)));
        WP_CLI::log(sprintf('First post: %.2f ms (cold field-type map)', $timings[0] ?? 0.0));
        WP_CLI::log(sprintf('Slowest:    %.2f ms', $timings === [] ? 0.0 : max($timings)));

        WP_CLI::success('Audit complete.');
    }
}
