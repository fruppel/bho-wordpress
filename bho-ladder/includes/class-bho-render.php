<?php
/**
 * The HTML.
 *
 * Everything that reaches this file came from an HTTP response, so every value is escaped on the way
 * out — a player's handle is somebody else's text and this plugin is a guest on somebody's site.
 *
 * The markup is deliberately plain: a table, a list, a couple of spans. It inherits the theme's font
 * and colours, and `assets/ladder.css` only adds what a theme cannot know about — the rank tints, the
 * podium rows, the flag frame.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class BHO_Render
{
    /** @param array<string,string> $t */
    public function __construct(
        private readonly BHO_Api $api,
        private readonly array $t,
    ) {}

    public function ladder(int $recent, int $limit): string
    {
        $data = $this->api->ladder();

        if (is_wp_error($data)) {
            return $this->notice($this->t['unavailable'] . ' (' . $data->get_error_message() . ')');
        }

        $entries = $data['entries'] ?? [];
        $html = '<div class="bho-ladder">';

        if ($this->api->servedStale()) {
            $html .= $this->notice($this->t['stale']);
        }

        foreach ($data['notes'] ?? [] as $note) {
            $html .= $this->note($note);
        }

        $html .= $this->running($data['tournaments'] ?? []);

        if ($recent > 0) {
            $html .= $this->recent(array_slice($data['recent'] ?? [], 0, $recent));
        }

        if ($entries === []) {
            return $html . $this->notice($this->t['empty']) . '</div>';
        }

        $html .= $this->table($limit > 0 ? array_slice($entries, 0, $limit) : $entries);
        $html .= $this->legend($data['ranks'] ?? []);
        $html .= '<p class="bho-foot">' . esc_html(sprintf(
            '%s %s',
            $this->t['starts_at'],
            sprintf($this->t['players_count'], count($entries)),
        )) . '</p>';

        return $html . '</div>';
    }

    public function player(int $id): string
    {
        $data = $this->api->player($id);

        if (is_wp_error($data)) {
            return $this->notice($this->t['no_player']);
        }

        $games = $data['games'] ?? [];
        $rated = array_values(array_filter($games, static fn(array $g): bool => $g['ratingAfter'] !== null));
        $rating = $rated === [] ? null : end($rated)['ratingAfter'];
        $swing = array_sum(array_map(static fn(array $g): int => (int) ($g['ratingChange'] ?? 0), $games));

        $record = [
            'WIN' => 0,
            'DRAW' => 0,
            'LOSS' => 0,
        ];
        foreach ($games as $game) {
            ++$record[$game['result']];
        }

        $html = '<div class="bho-ladder bho-player">';
        $html .= '<p class="bho-back"><a href="' . esc_url(remove_query_arg(BHO_LADDER_PLAYER_PARAM)) . '">'
            . esc_html($this->t['back']) . '</a></p>';

        $html .= '<div class="bho-player-head">';
        $html .= '<h2>' . $this->flag($data['player']['country'] ?? null)
            . '<span>' . esc_html((string) $data['player']['name']) . '</span></h2>';

        if ($rating !== null) {
            $html .= '<p class="bho-player-rating"><strong>' . esc_html((string) $rating) . '</strong>'
                . '<span>' . esc_html($this->t['from_1100']) . ' · ' . $this->change($swing) . ' '
                . esc_html($this->t['here']) . '</span></p>';
        }
        $html .= '</div>';

        $html .= '<p class="bho-foot">' . esc_html(sprintf(
            $this->t['games_count'],
            sprintf('%d–%d–%d', $record['WIN'], $record['DRAW'], $record['LOSS']),
            count($games),
        )) . '</p>';

        if ($games === []) {
            return $html . $this->notice($this->t['no_games']) . '</div>';
        }

        // Grouped by tournament, because the name repeats down every round of an event and reads as
        // noise once somebody has two seasons behind them.
        $tournament = null;
        foreach ($games as $game) {
            if ($game['tournament'] !== $tournament) {
                $html .= $tournament === null ? '' : '</ul>';
                $tournament = $game['tournament'];
                $html .= '<h3 class="bho-group">' . esc_html((string) $tournament)
                    . ' <span>' . esc_html(self::formatDay((string) $game['startDate'])) . '</span></h3><ul class="bho-games">';
            }

            $html .= $this->game($game);
        }

        return $html . '</ul></div>';
    }

    /** @param array<int,array<string,mixed>> $entries */
    private function table(array $entries): string
    {
        $html = '<table class="bho-table"><thead><tr>'
            . '<th class="bho-pos">#</th>'
            . '<th>' . esc_html($this->t['player']) . '</th>'
            . '<th class="bho-num bho-w-rating">' . esc_html($this->t['rating']) . '</th>'
            . '<th class="bho-w-rank">' . esc_html($this->t['rank']) . '</th>'
            . '<th class="bho-num bho-w-record">' . esc_html($this->t['record']) . '</th>'
            . '<th class="bho-num bho-w-events bho-wide">' . esc_html($this->t['events']) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $position = (int) $entry['position'];
            $html .= '<tr class="bho-row bho-place-' . esc_attr((string) min($position, 4)) . '">'
                . '<td class="bho-pos">' . $this->placement($position) . '</td>'
                . '<td class="bho-name"><a href="'
                . esc_url(add_query_arg(BHO_LADDER_PLAYER_PARAM, (int) $entry['id'], get_permalink()))
                . '">' . $this->flag($entry['country'] ?? null)
                . '<span>' . esc_html((string) $entry['name']) . '</span></a></td>'
                . '<td class="bho-num bho-w-rating bho-rating">' . esc_html((string) $entry['rating']) . '</td>'
                . '<td class="bho-w-rank">' . $this->rank((string) $entry['rank']) . '</td>'
                . '<td class="bho-num bho-w-record">' . esc_html(sprintf(
                    '%d–%d–%d',
                    $entry['wins'],
                    $entry['draws'],
                    $entry['losses'],
                )) . '</td>'
                . '<td class="bho-num bho-w-events bho-wide">' . esc_html((string) $entry['tournaments']) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<int,array<string,mixed>> $games */
    private function recent(array $games): string
    {
        if ($games === []) {
            return '';
        }

        $html = '<h3 class="bho-group bho-eyebrow">' . esc_html($this->t['latest']) . '</h3>'
            . '<ul class="bho-recent">';

        foreach ($games as $game) {
            $html .= '<li><span class="bho-round">R' . esc_html((string) $game['round']) . '</span>'
                . '<span class="bho-side">' . $this->playerLink($game['one']) . ' ' . $this->change((int) $game['one']['change']) . '</span>'
                . '<span class="bho-score">' . esc_html($game['one']['score'] . '–' . $game['two']['score']) . '</span>'
                . '<span class="bho-side bho-right">' . $this->change((int) $game['two']['change']) . ' ' . $this->playerLink($game['two']) . '</span>'
                . '</li>';
        }

        return $html . '</ul>';
    }

    /** @param array<string,mixed> $side */
    private function playerLink(array $side): string
    {
        return '<a href="' . esc_url(add_query_arg(BHO_LADDER_PLAYER_PARAM, (int) $side['id'], get_permalink()))
            . '">' . esc_html((string) $side['name']) . '</a>';
    }

    /** @param array<string,mixed> $game */
    private function game(array $game): string
    {
        $result = strtolower((string) $game['result']);

        return '<li>'
            . '<span class="bho-round">R' . esc_html((string) $game['round']) . '</span>'
            . '<a class="bho-opponent" href="'
            . esc_url(add_query_arg(BHO_LADDER_PLAYER_PARAM, (int) $game['opponent']['id'], get_permalink()))
            . '">' . $this->flag($game['opponent']['country'] ?? null)
            . '<span>' . esc_html((string) $game['opponent']['name']) . '</span></a>'
            . '<span class="bho-score">' . esc_html($game['score'] . '–' . $game['opponentScore']) . '</span>'
            . '<span class="bho-result bho-' . esc_attr($result) . '">' . esc_html($this->t[$result]) . '</span>'
            . '<span class="bho-delta">' . $this->change($game['ratingChange']) . '</span>'
            . '<span class="bho-after">' . esc_html((string) ($game['ratingAfter'] ?? '')) . '</span>'
            . '<span class="bho-teams">' . esc_html((string) ($game['killTeam'] ?? '—'))
            . ' <em>' . esc_html($this->t['versus']) . '</em> '
            . esc_html((string) ($game['opponentKillTeam'] ?? '—')) . '</span>'
            . '</li>';
    }

    /** @param array<int,array<string,mixed>> $tournaments */
    private function running(array $tournaments): string
    {
        $running = array_values(array_filter($tournaments, static fn(array $t): bool => !$t['hasConcluded']));

        if ($running === []) {
            return '';
        }

        $html = '<div class="bho-running"><h3><span class="bho-dot" aria-hidden="true"></span>'
            . esc_html(count($running) === 1 ? $this->t['running_one'] : $this->t['running_many']) . '</h3>';

        foreach ($running as $tournament) {
            $html .= '<p class="bho-running-name">' . esc_html((string) $tournament['name'])
                . ' <span>' . esc_html(self::formatDay((string) $tournament['startDate'])
                . ' – ' . self::formatDay((string) $tournament['endDate'])) . '</span>'
                . ' <a href="' . esc_url((string) $tournament['url']) . '" target="_blank" rel="noopener">'
                . esc_html($this->t['herald']) . '</a></p>';
        }

        return $html . '<p class="bho-foot">' . esc_html($this->t['running_note']) . '</p></div>';
    }

    /** @param array<int,array<string,mixed>> $brackets */
    private function legend(array $brackets): string
    {
        if ($brackets === []) {
            return '';
        }

        $html = '<p class="bho-legend">';

        foreach ($brackets as $bracket) {
            $span = match (true) {
                $bracket['from'] === null => sprintf($this->t['below'], ((int) $bracket['to']) + 1),
                $bracket['to'] === null => sprintf($this->t['upwards'], (int) $bracket['from']),
                default => $bracket['from'] . '–' . $bracket['to'],
            };

            $html .= '<span class="bho-legend-item">' . $this->rank((string) $bracket['name'])
                . ' <span>' . esc_html($span) . '</span></span>';
        }

        return $html . '</p>';
    }

    /** @param array<string,mixed> $note */
    private function note(array $note): string
    {
        $params = $note['params'] ?? [];

        $text = match ($note['code'] ?? '') {
            'provisionalPlacings' => sprintf(
                $this->t['note_provisional'],
                $params['tournament'] ?? '',
                (int) ($params['counted'] ?? 0),
                (int) ($params['expected'] ?? 0),
            ),
            // A code this plugin has no sentence for is skipped rather than printed: the application
            // can add one before the plugin is updated, and a raw key on the page helps nobody.
            default => '',
        };

        return $text === '' ? '' : '<p class="bho-note">' . esc_html($text) . '</p>';
    }

    /**
     * A trophy for the first three places, the number for everyone else.
     *
     * Places are shared and the count after them jumps, so a table can hold three firsts and no
     * second at all — three gold trophies, which is what sharing a place means. It can also hold a
     * third with no second above it, and 4 to 11 all reading "5".
     */
    private function placement(int $position): string
    {
        if ($position > 3) {
            return esc_html((string) $position);
        }

        $label = [1 => $this->t['first'], 2 => $this->t['second'], 3 => $this->t['third']][$position];

        // The same drawing the application uses, for the same reason: 🥇 is a platform font and looks
        // like a different object on every one of them.
        return '<svg class="bho-trophy" viewBox="0 0 24 24" role="img" aria-label="' . esc_attr($label) . '">'
            . '<title>' . esc_html($label) . '</title>'
            . '<rect x="6" y="2.6" width="12" height="2.1" rx=".7" fill="currentColor"/>'
            . '<path d="M7.1 5.4h9.8l-.75 4.5A4.35 4.35 0 0 1 12 13.6a4.35 4.35 0 0 1-4.15-3.7L7.1 5.4Z" fill="currentColor"/>'
            . '<path d="M7 6.6H5.3A1.3 1.3 0 0 0 4 7.9c0 2.05 1.5 3.75 3.5 4.05" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round"/>'
            . '<path d="M17 6.6h1.7A1.3 1.3 0 0 1 20 7.9c0 2.05-1.5 3.75-3.5 4.05" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round"/>'
            . '<rect x="11" y="13.2" width="2" height="3.6" fill="currentColor"/>'
            . '<path d="M9 16.6h6l.9 2.2H8.1z" fill="currentColor"/>'
            . '<rect x="6.6" y="18.6" width="10.8" height="2.2" rx=".8" fill="currentColor"/>'
            . '</svg>';
    }

    private function rank(string $rank): string
    {
        $slug = strtolower(str_replace(['+', '-'], ['plus', 'minus'], $rank));

        return '<span class="bho-rank bho-rank-' . esc_attr($slug) . '">' . esc_html($rank) . '</span>';
    }

    /**
     * The flags ship with this plugin rather than being fetched from the ladder's own server.
     *
     * A country is two letters, so the file name is derivable — and every page view would otherwise
     * put a handful of requests on a server that is somebody's private machine today.
     */
    private function flag(?string $country): string
    {
        if (!is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            return '';
        }

        $code = strtolower($country);
        if (!is_file(BHO_LADDER_DIR . 'assets/flags/' . $code . '.svg')) {
            return '';
        }

        return '<img class="bho-flag" src="' . esc_url(BHO_LADDER_URL . 'assets/flags/' . $code . '.svg')
            . '" alt="' . esc_attr(strtoupper($code)) . '" width="16" height="12" loading="lazy" />';
    }

    private function change(?int $change): string
    {
        if ($change === null) {
            return '<span class="bho-change bho-none">—</span>';
        }

        $tone = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');

        return '<span class="bho-change bho-' . $tone . '">'
            . esc_html(($change > 0 ? '+' : '') . $change) . '</span>';
    }

    /** dd.mm.yy, the way the application prints it — one club, one way of writing a date. */
    public static function formatDay(string $ymd): string
    {
        $parts = explode('-', $ymd);

        return count($parts) === 3
            ? sprintf('%s.%s.%s', $parts[2], $parts[1], substr($parts[0], 2))
            : $ymd;
    }

    private function notice(string $text): string
    {
        return '<p class="bho-notice">' . esc_html($text) . '</p>';
    }
}
