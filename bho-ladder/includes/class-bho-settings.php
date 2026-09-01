<?php
/**
 * Where the ladder lives, and how long an answer is kept.
 *
 * Two fields, on the Settings menu. A constant in wp-config would have done, but then the address of
 * the ladder is something only whoever has FTP can change — and the person who runs this site is not
 * necessarily that person.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class BHO_Settings
{
    private const OPTION = 'bho_ladder_settings';

    public static function boot(): void
    {
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_menu', [self::class, 'menu']);
        add_filter(
            'plugin_action_links_' . plugin_basename(BHO_LADDER_FILE),
            [self::class, 'actionLink'],
        );
    }

    /** The wording the blocks are rendered in. `site` follows the WordPress locale. */
    public const LANGUAGES = ['en' => 'English', 'de' => 'Deutsch', 'es' => 'Español', 'site' => 'Wie die Seite'];

    /** @return array{api: string, ttl: int, games_page: int, language: string} */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return [
            'api' => untrailingslashit((string) ($stored['api'] ?? '')),
            'ttl' => max(1, (int) ($stored['ttl'] ?? 5)),
            // Which page holds `[bho_all_games]`, so the latest-games block can link to it. Zero
            // means no link rather than a link to nowhere.
            'games_page' => max(0, (int) ($stored['games_page'] ?? 0)),
            // English by default, and not the site's locale: blackhydra.org is an English page, and a
            // German WordPress behind it would otherwise put a German table on it.
            'language' => self::language($stored['language'] ?? null),
        ];
    }

    /** @param mixed $value */
    private static function language($value): string
    {
        $value = is_string($value) ? $value : '';

        return isset(self::LANGUAGES[$value]) ? $value : 'en';
    }

    public static function register(): void
    {
        register_setting('bho_ladder', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => ['api' => '', 'ttl' => 5, 'games_page' => 0, 'language' => 'en'],
        ]);
    }

    /**
     * @param mixed $input
     * @return array{api: string, ttl: int, games_page: int, language: string}
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            // esc_url_raw and not sanitize_text_field: this value is used to build an outgoing
            // request, so it has to be a URL or nothing.
            'api' => untrailingslashit(esc_url_raw((string) ($input['api'] ?? ''))),
            'ttl' => min(1440, max(1, (int) ($input['ttl'] ?? 5))),
            'games_page' => max(0, (int) ($input['games_page'] ?? 0)),
            'language' => self::language($input['language'] ?? null),
        ];
    }

    public static function menu(): void
    {
        add_options_page('BHO Ladder', 'BHO Ladder', 'manage_options', 'bho-ladder', [self::class, 'page']);
    }

    /**
     * The plugin's own links in its row on the plugins screen.
     *
     * The update check is second because it is the rarer of the two, and it is here rather than on the
     * settings screen because this is where somebody is standing when they wonder why no update is
     * being offered. `BHO_Updates::link()` returns nothing for a user who may not update plugins.
     *
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public static function actionLink(array $links): array
    {
        $own = [
            '<a href="' . esc_url(admin_url('options-general.php?page=bho-ladder')) . '">'
            . esc_html__('Settings', 'bho-ladder') . '</a>',
        ];

        $check = BHO_Updates::link();
        if ($check !== '') {
            $own[] = $check;
        }

        return array_merge($own, $links);
    }

    public static function page(): void
    {
        $settings = self::all();
        ?>
        <div class="wrap">
            <h1>BHO Ladder</h1>

            <?php bho_ladder_tabs('bho-ladder'); ?>

            <form method="post" action="options.php">
                <?php settings_fields('bho_ladder'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bho-api">Ladder-API</label></th>
                        <td>
                            <input id="bho-api" name="<?php echo esc_attr(self::OPTION); ?>[api]"
                                   type="url" class="regular-text" placeholder="https://bho.fruppel.de"
                                   value="<?php echo esc_attr($settings['api']); ?>" />
                            <p class="description">
                                Adresse der Ladder-Anwendung, ohne Pfad. Gelesen wird die
                                öffentliche API unter <code>/api/v1/</code>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bho-ttl">Zwischenspeicher</label></th>
                        <td>
                            <input id="bho-ttl" name="<?php echo esc_attr(self::OPTION); ?>[ttl]"
                                   type="number" min="1" max="1440" class="small-text"
                                   value="<?php echo esc_attr((string) $settings['ttl']); ?>" /> Minuten
                            <p class="description">
                                Wie lange eine Antwort ohne Rückfrage ausgeliefert wird. Die Tabelle
                                ändert sich nur beim Import, fünf Minuten sind reichlich.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bho-language">Sprache</label></th>
                        <td>
                            <select id="bho-language" name="<?php echo esc_attr(self::OPTION); ?>[language]">
                                <?php foreach (self::LANGUAGES as $code => $label) : ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($settings['language'], $code); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                Die Sprache der Blöcke. Standard ist Englisch, wie blackhydra.org;
                                „Wie die Seite" folgt der WordPress-Sprache.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bho-games-page">Seite „Alle Spiele"</label></th>
                        <td>
                            <?php
                            wp_dropdown_pages([
                                'id' => 'bho-games-page',
                                'name' => esc_attr(self::OPTION) . '[games_page]',
                                'selected' => $settings['games_page'],
                                'show_option_none' => '— keine —',
                                'option_none_value' => '0',
                            ]);
                            ?>
                            <p class="description">
                                Die Seite mit <code>[bho_all_games]</code>. Der Block mit den letzten
                                Spielen verlinkt dorthin; ohne Auswahl bleibt der Link weg.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Einbinden</h2>
            <p>
                <code>[bho_ladder]</code> — die Seite: Tabelle, die letzten Spiele und die Regeln.
                Ab 960 Pixeln stehen Spiele und Regeln nebeneinander unter der Tabelle, darunter
                untereinander. Ein Klick auf einen Spieler bleibt auf derselben Seite und hängt
                <code>?<?php echo esc_html(BHO_LADDER_PLAYER_PARAM); ?>=…</code> an; es braucht also
                keine zweite Seite und keine Permalink-Umstellung.
            </p>
            <p>
                Ein Klick auf eine Spaltenüberschrift sortiert die Tabelle und hängt
                <code>?<?php echo esc_html(BHO_LADDER_SORT_PARAM); ?>=games</code> an, ein zweiter
                dreht die Richtung um. Die sortierte Ansicht ist damit ein Link, den man verschicken kann.
            </p>
            <p>
                <code>[bho_all_games]</code> — alle Spiele der Saison, seitenweise, in denselben
                Zeilen wie der Block oben. Gehört auf die oben gewählte Seite.
            </p>
            <p>
                Weniger auf einmal, etwa für die Startseite:
                <code>[bho_ladder limit="10" games="3" rules="0"]</code>.
            </p>

            <h2>Mehrere Ladders auf einer Seite</h2>
            <p>
                Ohne Angabe zeigen beide Shortcodes die Saison, die in der Ladder-Anwendung als
                <em>Standard</em> markiert ist. Läuft mehr als ein Spiel parallel, sagt die Seite,
                welche Saison sie meint: <code>[bho_ladder season="12"]</code> und
                <code>[bho_all_games season="12"]</code>. Die Nummern stehen nebenan unter
                <a href="<?php echo esc_url(admin_url('options-general.php?page=bho-seasons')); ?>">Saisons</a>.
            </p>
            <p>
                Die Angabe zieht sich durch: Tabelle, letzte Spiele und die Spielerseite dahinter
                zeigen dieselbe Saison. Ohne sie listet die Spielerseite alle Spiele einer Person
                über alle Spiele hinweg — was auf einer Seite über eine einzelne Ladder falsch wäre.
            </p>
        </div>
        <?php
    }
}
