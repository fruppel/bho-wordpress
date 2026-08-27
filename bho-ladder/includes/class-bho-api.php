<?php
/**
 * The BHO API, cached.
 *
 * Three endpoints, all public and all answering without a token: the whole table, one player's games,
 * and which tournaments count towards which season. Nothing here writes; the application on the other
 * end owns the data and has its own admin area for it.
 *
 * They live under `/api/v1/`, and that prefix exists because of this file: the application can change
 * the shape of anything its own screens read in the same commit, but not of what a site it does not
 * deploy is reading. A breaking change there means `/api/v2/` and a plugin update, in that order.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class BHO_Api
{
    /**
     * How long an answer is served without asking again.
     *
     * The ladder is recomputed from every game on each request over there, so a page that asked per
     * visitor would put real work on that server for a table that only changes when somebody presses
     * Import. Minutes, not seconds.
     */
    private const DEFAULT_TTL_MINUTES = 5;

    /**
     * How long the last good answer is kept beyond that.
     *
     * When the API is unreachable, a day-old table with a note under it is better than an error
     * message where the ladder should be — the numbers were true this morning and say so.
     */
    private const STALE_SECONDS = DAY_IN_SECONDS;

    private bool $servedStale = false;

    public function __construct(
        private readonly string $base,
        private readonly int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ) {}

    public static function fromSettings(): self
    {
        $settings = BHO_Settings::all();

        return new self($settings['api'], (int) $settings['ttl']);
    }

    /** @return array<string,mixed>|WP_Error */
    public function ladder(?int $season = null): array|WP_Error
    {
        return $this->get($season === null ? '/api/v1/ladder' : '/api/v1/ladder?season=' . $season);
    }

    /**
     * Every season and what counts towards it. Read-only, like everything here.
     *
     * Changing the assignment stays in the ladder's own admin area: it would need a credential with
     * write access sitting in this database, and this is a WordPress on shared hosting — the most
     * attacked software in the whole arrangement — for a job somebody does five times a year.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function seasons(): array|WP_Error
    {
        return $this->get('/api/v1/seasons');
    }

    /** @return array<string,mixed>|WP_Error */
    public function player(int $id): array|WP_Error
    {
        return $this->get('/api/v1/players/' . $id);
    }

    /** Whether the last answer came out of the stale copy rather than from the API. */
    public function servedStale(): bool
    {
        return $this->servedStale;
    }

    /** The application's own page for this player, for the "see it over there" link. */
    public function appUrl(string $path = ''): string
    {
        return untrailingslashit($this->base) . $path;
    }

    /** @return array<string,mixed>|WP_Error */
    private function get(string $path): array|WP_Error
    {
        $this->servedStale = false;

        if ($this->base === '') {
            return new WP_Error('bho_no_api', __('No ladder address is configured.', 'bho-ladder'));
        }

        $key = 'bho_ladder_' . md5($this->base . $path);
        $fresh = get_transient($key);
        if (is_array($fresh)) {
            return $fresh;
        }

        $response = wp_remote_get(untrailingslashit($this->base) . $path, [
            'timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
            // Named so the other end's logs say who is asking, which is the difference between a
            // known reader and unexplained traffic when somebody looks.
            'user-agent' => 'BHO-Ladder-WordPress/' . BHO_LADDER_VERSION . '; ' . home_url('/'),
        ]);

        $data = $this->decode($response);

        if (is_wp_error($data)) {
            $stale = get_transient($key . '_stale');
            if (is_array($stale)) {
                $this->servedStale = true;
                return $stale;
            }

            return $data;
        }

        set_transient($key, $data, max(1, $this->ttlMinutes) * MINUTE_IN_SECONDS);
        set_transient($key . '_stale', $data, self::STALE_SECONDS);

        return $data;
    }

    /** @return array<string,mixed>|WP_Error */
    private function decode(array|WP_Error $response): array|WP_Error
    {
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error(
                'bho_http',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('The ladder answered %d.', 'bho-ladder'),
                    $status,
                ),
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data)
            ? $data
            : new WP_Error('bho_json', __('The ladder did not answer with JSON.', 'bho-ladder'));
    }
}
