<?php
/**
 * Enough WordPress to load the plugin, and no more.
 *
 * **The stand-ins do the real thing.** `esc_html()` escapes, `add_query_arg()` builds a query, and
 * `wp_remote_get()` is answered by whatever a test queued — because a stub that returns its argument
 * turns every escaping test into a test of nothing, and this plugin's whole job is putting somebody
 * else's data into HTML. Where a function's behaviour is not what a test is about, the stand-in is
 * still the simplest honest version rather than a shortcut.
 *
 * What is deliberately absent: a database, the plugins screen, hooks. `add_action()` and friends
 * record the call and return, so the plugin file loads to the bottom and hands over its constants and
 * its shortcode functions — taking those from the real file rather than repeating them here is what
 * keeps this from drifting away from the plugin it is testing.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

/** WordPress's own error object, in the shape this plugin uses it. */
class WP_Error
{
    public function __construct(
        private readonly string $code = '',
        private readonly string $message = '',
    ) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

// ---------------------------------------------------------------------------
// What a test steers
// ---------------------------------------------------------------------------

/**
 * The site around the plugin, reset between tests by BHO\Tests\PluginTestCase.
 *
 * One bag rather than a global per function: a test that forgets to clear one of them fails somewhere
 * else, and a single reset cannot be half done.
 */
final class BHO_Test_Site
{
    /** @var array<string,mixed> */
    public static array $transients = [];

    /** @var list<array{url: string, args: array<string,mixed>}> */
    public static array $requests = [];

    /** @var list<array<string,mixed>|WP_Error> */
    public static array $answers = [];

    /** @var array<string,string> */
    public static array $options = [];

    /** @var list<array{hook: string, callback: mixed}> */
    public static array $hooks = [];

    public static string $permalink = 'https://club.example/ladder/';
    public static string $home = 'https://club.example/';
    public static string $locale = 'de_DE';

    /** @var array<string,string> */
    public static array $query = [];

    public static function reset(): void
    {
        self::$transients = [];
        self::$requests = [];
        self::$answers = [];
        self::$options = [];
        self::$hooks = [];
        self::$permalink = 'https://club.example/ladder/';
        self::$home = 'https://club.example/';
        self::$locale = 'de_DE';
        self::$query = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/ladder/';
    }
}

// ---------------------------------------------------------------------------
// Escaping — the reason these are real and not identity functions
// ---------------------------------------------------------------------------

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url(string $url): string
{
    // WordPress drops anything that is not an allowed scheme and then entity-encodes. Close enough
    // to catch a javascript: URL reaching an href, which is the thing worth catching.
    $url = trim($url);
    if ($url !== '' && preg_match('#^(https?:|/|\#|\?)#i', $url) !== 1) {
        return '';
    }

    return str_replace(['&', '"', "'", '<', '>'], ['&#038;', '&quot;', '&#039;', '&lt;', '&gt;'], $url);
}

function esc_url_raw(string $url): string
{
    return trim($url);
}

function wp_kses_post(string $html): string
{
    return $html;
}

// ---------------------------------------------------------------------------
// Translation, which this plugin does itself in strings.php
// ---------------------------------------------------------------------------

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function _e(string $text, string $domain = 'default'): void
{
    echo $text;
}

function esc_html_e(string $text, string $domain = 'default'): void
{
    echo esc_html($text);
}

function get_locale(): string
{
    return BHO_Test_Site::$locale;
}

// ---------------------------------------------------------------------------
// URLs
// ---------------------------------------------------------------------------

function home_url(string $path = '/'): string
{
    return rtrim(BHO_Test_Site::$home, '/') . $path;
}

function get_permalink(): string
{
    return BHO_Test_Site::$permalink;
}

function admin_url(string $path = ''): string
{
    return rtrim(BHO_Test_Site::$home, '/') . '/wp-admin/' . ltrim($path, '/');
}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/') . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://club.example/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return plugin_dir_url($plugin) . ltrim($path, '/');
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/\\');
}

function trailingslashit(string $value): string
{
    return untrailingslashit($value) . '/';
}

/**
 * The real behaviour, because half this plugin's links are built with it: keys are added to whatever
 * query the URL already has, and a null value takes one out.
 */
function add_query_arg(mixed ...$args): string
{
    if (is_array($args[0])) {
        [$pairs, $url] = [$args[0], $args[1] ?? current_url()];
    } else {
        [$key, $value] = [$args[0], $args[1] ?? ''];
        $pairs = [$key => $value];
        $url = $args[2] ?? current_url();
    }

    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    foreach ($pairs as $key => $value) {
        if ($value === null || $value === false) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $base = ($parts['scheme'] ?? '') !== ''
        ? $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '')
        : ($parts['path'] ?? '');

    return $query === [] ? $base : $base . '?' . http_build_query($query);
}

function remove_query_arg(mixed $key, ?string $url = null): string
{
    $keys = is_array($key) ? $key : [$key];

    return add_query_arg(array_fill_keys($keys, null), $url ?? current_url());
}

function current_url(): string
{
    return rtrim(BHO_Test_Site::$home, '/') . ($_SERVER['REQUEST_URI'] ?? '/');
}

// ---------------------------------------------------------------------------
// Transients, options, hooks
// ---------------------------------------------------------------------------

function get_transient(string $key): mixed
{
    return BHO_Test_Site::$transients[$key] ?? false;
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    BHO_Test_Site::$transients[$key] = $value;

    return true;
}

function delete_transient(string $key): bool
{
    unset(BHO_Test_Site::$transients[$key]);

    return true;
}

function get_option(string $name, mixed $default = false): mixed
{
    return BHO_Test_Site::$options[$name] ?? $default;
}

function update_option(string $name, mixed $value): bool
{
    BHO_Test_Site::$options[$name] = $value;

    return true;
}

function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool
{
    BHO_Test_Site::$hooks[] = ['hook' => $hook, 'callback' => $callback];

    return true;
}

function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool
{
    BHO_Test_Site::$hooks[] = ['hook' => $hook, 'callback' => $callback];

    return true;
}

function add_shortcode(string $tag, mixed $callback): void
{
    BHO_Test_Site::$hooks[] = ['hook' => 'shortcode:' . $tag, 'callback' => $callback];
}

/** Runs what a test registered, so a filter the plugin offers can be shown to actually fire. */
function apply_filters(string $hook, mixed $value, mixed ...$rest): mixed
{
    foreach (BHO_Test_Site::$hooks as $registered) {
        if ($registered['hook'] === $hook) {
            $value = ($registered['callback'])($value, ...$rest);
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void {}

function register_setting(string $group, string $name, array $args = []): void {}

function add_options_page(string ...$args): string
{
    return 'options-page';
}

function add_rewrite_rule(string ...$args): void {}

function current_user_can(string $capability): bool
{
    return true;
}

function wp_enqueue_style(string ...$args): void {}

function wp_clean_plugins_cache(bool $clear = true): void {}

function wp_update_plugins(): void {}

function wp_safe_redirect(string $url, int $status = 302): bool
{
    return true;
}

function wp_die(string $message = ''): never
{
    throw new RuntimeException('wp_die: ' . $message);
}

function wp_nonce_url(string $url, string $action = '-1'): string
{
    return add_query_arg('_wpnonce', 'test-nonce', $url);
}

function check_admin_referer(string $action = '-1'): bool
{
    return true;
}

function selected(mixed $one, mixed $two = true, bool $echo = true): string
{
    $html = (string) $one === (string) $two ? ' selected="selected"' : '';
    if ($echo) {
        echo $html;
    }

    return $html;
}

function shortcode_atts(array $pairs, mixed $atts, string $shortcode = ''): array
{
    $atts = (array) $atts;
    $out = [];
    foreach ($pairs as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------

function wp_unslash(mixed $value): mixed
{
    return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_date(string $format, ?int $timestamp = null): string
{
    return gmdate($format, $timestamp ?? time());
}

function number_format_i18n(float $number, int $decimals = 0): string
{
    return number_format($number, $decimals);
}

// ---------------------------------------------------------------------------
// HTTP — answered from the queue a test filled
// ---------------------------------------------------------------------------

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    BHO_Test_Site::$requests[] = ['url' => $url, 'args' => $args];

    $answer = array_shift(BHO_Test_Site::$answers);
    if ($answer === null) {
        return new WP_Error('bho_test_unstubbed', 'No answer queued for ' . $url);
    }

    return $answer;
}

function wp_remote_retrieve_response_code(mixed $response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(mixed $response): string
{
    return (string) ($response['body'] ?? '');
}

// The plugin itself, loaded from source so its constants and shortcodes cannot drift from these tests.
require_once dirname(__DIR__) . '/bho-ladder/bho-ladder.php';
