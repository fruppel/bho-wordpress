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

require_once BHO_LADDER_DIR . 'includes/strings.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-api.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-render.php';
require_once BHO_LADDER_DIR . 'includes/nav.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-settings.php';
require_once BHO_LADDER_DIR . 'includes/class-bho-overview.php';

BHO_Settings::boot();
BHO_Overview::boot();

add_shortcode('bho_ladder', 'bho_ladder_shortcode');

/**
 * `[bho_ladder]` — the table, or one player's games when the URL names one.
 *
 * Attributes:
 *   recent="3"  how many of the latest games to list above the table; 0 leaves them out
 *   limit="0"   how many rows of the table to show; 0 is all of them (use 10 for a front-page teaser)
 */
function bho_ladder_shortcode(array|string $atts = []): string
{
    $atts = shortcode_atts(['recent' => '3', 'limit' => '0'], $atts, 'bho_ladder');

    wp_enqueue_style(
        'bho-ladder',
        BHO_LADDER_URL . 'assets/ladder.css',
        [],
        BHO_LADDER_VERSION,
    );

    $api = BHO_Api::fromSettings();
    $render = new BHO_Render($api, bho_ladder_strings());

    $player = isset($_GET[BHO_LADDER_PLAYER_PARAM])
        ? absint(wp_unslash($_GET[BHO_LADDER_PLAYER_PARAM]))
        : 0;

    return $player > 0
        ? $render->player($player)
        : $render->ladder((int) $atts['recent'], (int) $atts['limit']);
}

/**
 * A player's page is a different page, so it needs a different title.
 *
 * Without this, every player sits under the heading "Ladder" and the browser tab says the same for
 * all thirty-two of them — including in a bookmark and in anything that quotes the link.
 */
add_filter('document_title_parts', static function (array $parts): array {
    $player = isset($_GET[BHO_LADDER_PLAYER_PARAM])
        ? absint(wp_unslash($_GET[BHO_LADDER_PLAYER_PARAM]))
        : 0;

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
    $player = isset($_GET[BHO_LADDER_PLAYER_PARAM])
        ? absint(wp_unslash($_GET[BHO_LADDER_PLAYER_PARAM]))
        : 0;

    if ($player > 0) {
        printf(
            '<link rel="canonical" href="%s" />' . "\n",
            esc_url(add_query_arg(BHO_LADDER_PLAYER_PARAM, $player, get_permalink())),
        );
    }
}, 1);
