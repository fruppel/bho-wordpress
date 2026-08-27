<?php
/**
 * The tab bar the plugin's two screens share.
 *
 * Until it existed each screen was reachable only by knowing its URL — neither mentioned the other,
 * and both sit at the bottom of a long Settings submenu. Tabs rather than a top-level menu entry,
 * because these two addresses have already been written down elsewhere and still work.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @param 'bho-ladder'|'bho-seasons' $current */
function bho_ladder_tabs(string $current): void
{
    $tabs = [
        'bho-ladder' => 'Einstellungen',
        'bho-seasons' => 'Saisons',
    ];

    echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="BHO Ladder">';

    foreach ($tabs as $page => $label) {
        printf(
            '<a href="%s" class="nav-tab%s"%s>%s</a>',
            esc_url(admin_url('options-general.php?page=' . $page)),
            $page === $current ? ' nav-tab-active' : '',
            $page === $current ? ' aria-current="page"' : '',
            esc_html($label),
        );
    }

    echo '</nav>';
}
