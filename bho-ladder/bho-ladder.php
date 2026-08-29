<?php
/**
 * Plugin Name:       BHO Ladder
 * Plugin URI:        https://github.com/fruppel/bho-wordpress
 * Description:       Draws the Black Hydra Open ladder on a WordPress page, with a detail page per player. Reads the BHO API server-side and caches it.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Black Hydra Open
 * License:           MIT
 * Update URI:        https://github.com/fruppel/bho-wordpress
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

define('BHO_LADDER_VERSION', '0.4.0');
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
require_once BHO_LADDER_DIR . 'includes/class-bho-updates.php';

BHO_Settings::boot();
BHO_Overview::boot();
BHO_Updates::boot();

add_shortcode('bho_ladder', 'bho_ladder_shortcode');
add_shortcode('bho_all_games', 'bho_all_games_shortcode');

/**
 * `[bho_ladder]` — the page: the standings, the last few games and the rules.
 *
 * One shortcode and not three, because they are read together: the table is what the page is for, the
 * games say what just happened, and the rules are what a reader checks a row against. Below 960 pixels
 * the three stack; above it the games and the rules stand beside each other under the table.
 *
 * When the URL names a player it renders that player's games instead — same page, no second one to
 * create and no permalink setting to change.
 *
 * Attributes:
 *   limit="0"   rows in the table; 0 is all of them (use 10 for a front-page teaser)
 *   games="8"   how many recent games under it; 0 leaves them out
 *   rules="1"   the rules panel; "0" leaves it out
 */
function bho_ladder_shortcode(array|string $atts = []): string
{
    $atts = shortcode_atts(['limit' => '0', 'games' => '8', 'rules' => '1'], $atts, 'bho_ladder');
    $render = bho_ladder_renderer();

    $player = bho_ladder_player_in_url();

    return $player > 0
        ? $render->player($player)
        : $render->ladder(
            (int) $atts['limit'],
            $atts['rules'] !== '0',
            bho_ladder_sort_in_url(),
            max((int) $atts['games'], 0),
        );
}

/**
 * `[bho_all_games]` — every game of the season, a page at a time.
 *
 * Its own page because it is a lookup, not a summary: the block above lists the last few and links
 * here for the rest.
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
