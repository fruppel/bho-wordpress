<?php
/**
 * Which tournaments count towards which season, in the WordPress admin.
 *
 * Read-only on purpose. The ladder's own admin area is where an organiser assigns a tournament, and
 * two write paths to one dataset means the rules that hold it together — exactly one current season,
 * one season per tournament, a PUT that replaces the whole set — either live in two places or drift
 * apart. This screen answers "what is where" and hands over for "change it".
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class BHO_Overview
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'options-general.php',
            'BHO Saisons',
            'BHO Saisons',
            // Reading is not administering: an editor who maintains the ladder page should be able to
            // see which events feed it without holding the keys to the site.
            'edit_pages',
            'bho-seasons',
            [self::class, 'page'],
        );
    }

    public static function page(): void
    {
        $api = BHO_Api::fromSettings();
        $data = $api->seasons();
        $admin = $api->appUrl('/admin/seasons');
        ?>
        <div class="wrap">
            <h1>Saisons</h1>

            <?php if (is_wp_error($data)) : ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($data->get_error_message()); ?></p>
                    <p>
                        Adresse prüfen unter
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=bho-ladder')); ?>">
                            Einstellungen → BHO Ladder</a>.
                    </p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <p>
                Gelesen aus der Ladder, hier nur zum Nachsehen.
                <a href="<?php echo esc_url($admin); ?>" target="_blank" rel="noopener" class="button button-primary">
                    Im Ladder-Adminbereich ändern ↗
                </a>
            </p>

            <?php if ($api->servedStale()) : ?>
                <div class="notice notice-warning inline">
                    <p>Zuletzt bekannter Stand — die Ladder war beim Aktualisieren nicht erreichbar.</p>
                </div>
            <?php endif; ?>

            <?php if ($data['seasons'] === []) : ?>
                <p>Noch keine Saison angelegt. Solange keine existiert, zählt die Ladder alles Importierte.</p>
            <?php endif; ?>

            <?php foreach ($data['seasons'] as $season) : ?>
                <h2>
                    <?php echo esc_html((string) $season['name']); ?>
                    <?php if ($season['isCurrent']) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span>
                        <span style="font-size:13px;font-weight:400">aktuelle Saison — diese zeigt die öffentliche Ladder</span>
                    <?php endif; ?>
                </h2>
                <?php self::table($season['tournaments']); ?>
            <?php endforeach; ?>

            <?php if ($data['unassigned'] !== []) : ?>
                <h2>Keiner Saison zugeordnet</h2>
                <div class="notice notice-warning inline">
                    <p>
                        Diese Turniere zählen zu nichts. Ihre Ergebnisse sind importiert, gehen aber in
                        keine Wertung ein, bis sie im Ladder-Adminbereich einer Saison zugeordnet werden.
                    </p>
                </div>
                <?php self::table($data['unassigned']); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param array<int,array<string,mixed>> $tournaments */
    private static function table(array $tournaments): void
    {
        if ($tournaments === []) {
            echo '<p><em>Kein Turnier zugeordnet.</em></p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat striped" style="max-width:52rem">
            <thead>
                <tr>
                    <th>Turnier</th>
                    <th style="width:12rem">Zeitraum</th>
                    <th style="width:9rem">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tournaments as $tournament) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url((string) $tournament['url']); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html((string) $tournament['name']); ?>
                            </a>
                        </td>
                        <td>
                            <?php echo esc_html(
                                BHO_Render::formatDay((string) $tournament['startDate'])
                                . ' – ' . BHO_Render::formatDay((string) $tournament['endDate'])
                            ); ?>
                        </td>
                        <td>
                            <?php echo $tournament['hasConcluded']
                                ? 'beendet'
                                : '<strong>läuft noch</strong>'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
