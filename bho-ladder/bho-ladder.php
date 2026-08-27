<?php
/**
 * Plugin Name:       BHO Ladder
 * Plugin URI:        https://github.com/fruppel/bho-wordpress
 * Description:       Draws the Black Hydra Open ladder on a WordPress page, with a detail page per player. Reads the BHO API server-side and caches it.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Black Hydra Open
 * License:           MIT
 * Text Domain:       bho-ladder
 *
 * The ladder itself lives in a separate application (Symfony + two Vue apps). This plugin does not
 * reimplement it: it asks that application's public API for the same numbers the app draws, and
 * renders them into whatever theme this site wears.
 *
 * Read server-side rather than by JavaScript in the visitor's browser. Three reasons, and each one
 * would be enough: no CORS to arrange, the table is in the HTML so search engines and people with
 * script blockers see it, and the answer can be cached once for everybody instead of fetched once
 * per visitor.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('BHO_LADDER_VERSION', '0.1.0');
define('BHO_LADDER_FILE', __FILE__);
define('BHO_LADDER_DIR', plugin_dir_path(__FILE__));
define('BHO_LADDER_URL', plugin_dir_url(__FILE__));

/**
 * Which query parameter carries the player being looked at.
 *
 * A parameter and not a pretty path on purpose: blackhydra.org runs on plain permalinks, where
 * `add_rewrite_rule()` has nothing to hook into. This works whatever the permalink setting is, and
 * `/ladder/spieler/name` can be added later without changing anything else here.
 */
define('BHO_LADDER_PLAYER_PARAM', 'bho_player');

/** Which page of the all-games table is being looked at. Same reasoning as the player parameter. */
define('BHO_LADDER_PAGE_PARAM', 'bho_page');

/**
 * Which column the standings are sorted by, `-` for the other direction: `?bho_sort=-games`.
 *
 * In the URL rather than in a script, so a sorted table is a link somebody can send. It costs nothing
 * either: the standings arrive complete in one answer, so the sorting is a PHP function on an array
 * the page already holds.
 */
define('BHO_LADDER_SORT_PARAM', 'bho_sort');

require_once BHO_LADDER_DIR . 'includes/strings.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-api.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-render.php';
require_once BHO_LADDER_DIR . 'includes/nav.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-settings.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-overview.php';

BHO_Settings::boot();
BHO_Overview::boot();

add_shortcode('bho_ladder', 'bho_ladder_shortcode');
add_shortcode('bho_recent_games', 'bho_recent_games_shortcode');
add_shortcode('bho_all_games', 'bho_all_games_shortcode');
add_shortcode('bho_rules', 'bho_rules_shortcode');

/**
 * `[bho_ladder]` — the table, or one player's games when the URL names one.
 *
 * The latest games used to be part of this and are `[bho_recent_games]` now, so the club can put them
 * where it wants rather than where the table happens to be.
 *
 * Attributes:
 *   limit="0"   how many rows to show; 0 is all of them (use 10 for a front-page teaser)
 *   rules="1"   the rules panel under the table; "0" frees it for `[bho_rules]` elsewhere
 */
function bho_ladder_shortcode(array|string $atts = []): string
{
    $atts = shortcode_atts(['limit' => '0', 'rules' => '1'], $atts, 'bho_ladder');
    $render = bho_ladder_renderer();

    $player = bho_ladder_player_in_url();

    return $player > 0
        ? $render->player($player)
        : $render->ladder((int) $atts['limit'], $atts['rules'] !== '0', bho_ladder_sort_in_url());
}

/**
 * `[bho_rules]` — the rules and the rank classes, wherever they fit best.
 *
 * The same panel `[bho_ladder]` prints under the table, so a page that wants it beside the latest
 * games instead sets `rules="0"` there and places this one. It reads the same cached answer, so a
 * page holding both is still one request.
 */
function bho_rules_shortcode(array|string $atts = []): string
{
    shortcode_atts([], $atts, 'bho_rules');

    return bho_ladder_renderer()->rulesBlock();
}

/**
 * `[bho_recent_games]` — the last few games, wherever it is placed.
 *
 * Attributes:
 *   show="8"   how many to list; the link under them goes to every game there is
 */
function bho_recent_games_shortcode(array|string $atts = []): string
{
    // Nothing on a player's own page: it is already a list of games, and the block underneath it
    // would be the same rows a second time, most of them about somebody else.
    if (bho_ladder_player_in_url() > 0) {
        return '';
    }

    $atts = shortcode_atts(['show' => '8'], $atts, 'bho_recent_games');

    return bho_ladder_renderer()->recentGames((int) $atts['show']);
}

/**
 * `[bho_all_games]` — every game of the season in one table, a page at a time.
 *
 * Attributes:
 *   per="25"   rows per page
 */
function bho_all_games_shortcode(array|string $atts = []): string
{
    $atts = shortcode_atts(['per' => '25'], $atts, 'bho_all_games');

    return bho_ladder_renderer()->allGames((int) $atts['per'], bho_ladder_page_in_url());
}

/** The stylesheet is loaded here rather than per shortcode: a page may hold several of them. */
function bho_ladder_renderer(): BHO_Render
{
    wp_enqueue_style('bho-ladder', BHO_LADDER_URL . 'assets/ladder.css', [], bho_ladder_style_version());

    return new BHO_Render(BHO_Api::fromSettings(), bho_ladder_strings());
}

/**
 * The stylesheet's own modification time, not the plugin version.
 *
 * With the plugin version, an edit to the CSS keeps the same `?ver=` and every browser that has been
 * on the page keeps the file it already has — which is exactly what happened, and looked like the
 * change had not been made. The plugin version only moves on a release; the file moves whenever it
 * is edited, which is when the cache has to be dropped.
 */
function bho_ladder_style_version(): string
{
    $time = @filemtime(BHO_LADDER_DIR . 'assets/ladder.css');

    return $time ? (string) $time : BHO_LADDER_VERSION;
}

function bho_ladder_player_in_url(): int
{
    return isset($_GET[BHO_LADDER_PLAYER_PARAM])
        ? absint(wp_unslash($_GET[BHO_LADDER_PLAYER_PARAM]))
        : 0;
}

function bho_ladder_page_in_url(): int
{
    return max(isset($_GET[BHO_LADDER_PAGE_PARAM]) ? absint(wp_unslash($_GET[BHO_LADDER_PAGE_PARAM])) : 1, 1);
}

/** The raw value; BHO_Render decides which columns it knows and ignores the rest. */
function bho_ladder_sort_in_url(): string
{
    return isset($_GET[BHO_LADDER_SORT_PARAM])
        ? sanitize_text_field(wp_unslash($_GET[BHO_LADDER_SORT_PARAM]))
        : '';
}

/**
 * A player's page is a different page, so it needs a different title.
 *
 * Without this, every player sits under the heading "Ladder" and the browser tab says the same for
 * all thirty-two of them — including in a bookmark and in anything that quotes the link.
 */
add_filter('document_title_parts', static function (array $parts): array {
    $player = bho_ladder_player_in_url();

    if ($player > 0) {
        $history = BHO_Api::fromSettings()->player($player);
        if (!is_wp_error($history) && isset($history['player']['name'])) {
            $parts['title'] = (string) $history['player']['name'];
        }
    }

    return $parts;
});

/**
 * A player's page is one page with a parameter, so tell search engines it is its own address.
 *
 * Otherwise thirty-two URLs claim to be the same page and only one of them is kept.
 */
add_action('wp_head', static function (): void {
    $player = bho_ladder_player_in_url();

    if ($player > 0) {
        printf(
            '<link rel="canonical" href="%s" />' . "\n",
            esc_url(add_query_arg(BHO_LADDER_PLAYER_PARAM, $player, get_permalink())),
        );
    }
}, 1);
