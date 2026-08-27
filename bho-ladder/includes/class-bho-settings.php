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

    /** @return array{api: string, ttl: int} */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return [
            'api' => untrailingslashit((string) ($stored['api'] ?? '')),
            'ttl' => max(1, (int) ($stored['ttl'] ?? 5)),
        ];
    }

    public static function register(): void
    {
        register_setting('bho_ladder', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => ['api' => '', 'ttl' => 5],
        ]);
    }

    /**
     * @param mixed $input
     * @return array{api: string, ttl: int}
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        return [
            // esc_url_raw and not sanitize_text_field: this value is used to build an outgoing
            // request, so it has to be a URL or nothing.
            'api' => untrailingslashit(esc_url_raw((string) ($input['api'] ?? ''))),
            'ttl' => min(1440, max(1, (int) ($input['ttl'] ?? 5))),
        ];
    }

    public static function menu(): void
    {
        add_options_page('BHO Ladder', 'BHO Ladder', 'manage_options', 'bho-ladder', [self::class, 'page']);
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public static function actionLink(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('options-general.php?page=bho-ladder')) . '">'
            . esc_html__('Settings', 'bho-ladder') . '</a>',
        );

        return $links;
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
                                Adresse der Ladder-Anwendung, ohne Pfad. Gelesen werden
                                <code>/api/ladder</code> und <code>/api/players/{id}</code>.
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
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Einbinden</h2>
            <p>
                Shortcode <code>[bho_ladder]</code> auf einer Seite. Ein Klick auf einen Spieler bleibt
                auf derselben Seite und hängt <code>?<?php echo esc_html(BHO_LADDER_PLAYER_PARAM); ?>=…</code>
                an — es braucht also keine zweite Seite und keine Permalink-Umstellung.
            </p>
            <p>
                Für einen Teaser auf der Startseite: <code>[bho_ladder limit="10" recent="0"]</code>.
            </p>
        </div>
        <?php
    }
}
