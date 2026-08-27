<?php
/**
 * Tells WordPress where the next version of this plugin comes from.
 *
 * Core's own update check asks wordpress.org about every installed plugin. This one is not there, so
 * without this the site would never hear about a new version and somebody would have to remember to
 * upload a zip. With it, "Version 0.2.0 available" appears on the plugins screen and the button does
 * what that button always does.
 *
 * The mechanism is core's, not a library's: a plugin that names an `Update URI` in its header gets a
 * filter of its own, `update_plugins_<hostname>`, and whatever that filter returns is treated as the
 * answer an update server would have given. WordPress 5.8 and up, and this plugin asks for 6.0.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class BHO_Updates
{
    /** Where the releases are. The repository has to be public for an unauthenticated read. */
    private const REPO = 'fruppel/bho-wordpress';

    /**
     * Six hours, against a rate limit of sixty requests an hour per address for anonymous callers.
     *
     * Core checks for updates about twice a day and on every visit to the plugins screen, and a site
     * with several visitors in wp-admin would otherwise ask GitHub on each of those.
     */
    private const TTL = 6 * HOUR_IN_SECONDS;

    private const CACHE = 'bho_ladder_latest_release';

    public static function boot(): void
    {
        // Named after the host in the Update URI header, which is what core derives the filter from.
        add_filter('update_plugins_github.com', [self::class, 'answer'], 10, 3);
    }

    /**
     * @param  array<string,mixed>|false $update what another filter already decided, if anything
     * @param  array<string,string>      $plugin this plugin's headers
     * @param  string                    $file   its path, relative to the plugins directory
     * @return array<string,mixed>|false
     */
    public static function answer($update, array $plugin, string $file)
    {
        // Every plugin on this site whose Update URI points at github.com reaches this filter, so the
        // first thing to establish is whether the question is about us.
        if ($file !== plugin_basename(BHO_LADDER_FILE)) {
            return $update;
        }

        $release = self::latest();

        if ($release === null) {
            return $update;
        }

        [$version, $package] = $release;

        // Reported whether or not it is newer, because that is what core compares against the header.
        return [
            'slug' => dirname(plugin_basename(BHO_LADDER_FILE)),
            'version' => $version,
            'url' => 'https://github.com/' . self::REPO,
            'package' => $package,
            'requires' => $plugin['RequiresWP'] ?? '',
            'requires_php' => $plugin['RequiresPHP'] ?? '',
        ];
    }

    /**
     * The newest release's version and the zip attached to it.
     *
     * The attached asset and not GitHub's generated source archive: that one holds the repository, so
     * WordPress would install a plugin folder named after the repository and the update would land
     * beside this plugin instead of over it.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function latest(): ?array
    {
        $cached = get_transient(self::CACHE);

        if (is_array($cached) && count($cached) === 2) {
            return $cached;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 8,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'bho-ladder/' . BHO_LADDER_VERSION,
                ],
            ],
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Cached as nothing for a while: a repository that is private, renamed or without a
            // release would otherwise be asked again on every single update check.
            set_transient(self::CACHE, 'none', HOUR_IN_SECONDS);

            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $version = ltrim((string) ($body['tag_name'] ?? ''), 'v');
        $package = '';

        foreach ($body['assets'] ?? [] as $asset) {
            if (str_ends_with((string) ($asset['name'] ?? ''), '.zip')) {
                $package = (string) $asset['browser_download_url'];
                break;
            }
        }

        if ($version === '' || $package === '') {
            set_transient(self::CACHE, 'none', HOUR_IN_SECONDS);

            return null;
        }

        set_transient(self::CACHE, [$version, $package], self::TTL);

        return [$version, $package];
    }
}
