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

    public function ladder(int $limit): string
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

    /**
     * The last few games, as a block that can stand anywhere on the site.
     *
     * `$more` rows are fetched and the ones past `$show` are folded into a `<details>`. A link that
     * reloaded the page would work too, but this is one request either way and the browser does the
     * folding — no JavaScript of ours, and the rows are in the HTML for anything that reads it.
     */
    public function recentGames(int $show, int $more): string
    {
        $wanted = max($show, $more);
        $data = $this->api->games(1, max($wanted, 1));

        if (is_wp_error($data)) {
            return $this->wrap($this->notice($this->t['unavailable']));
        }

        $games = $data['games'] ?? [];
        if ($games === []) {
            return '';
        }

        $html = '<h3 class="bho-group bho-eyebrow">' . esc_html($this->t['latest']) . '</h3>';
        if ($this->api->servedStale()) {
            $html .= $this->notice($this->t['stale']);
        }

        $html .= '<ul class="bho-recent">';
        foreach (array_slice($games, 0, $show) as $game) {
            $html .= $this->recentRow($game);
        }
        $html .= '</ul>';

        $rest = array_slice($games, $show);
        if ($rest !== []) {
            // The summary is ordered *after* the rows it reveals (see the CSS), so the control sits
            // under the list at all times instead of splitting it in two.
            // Both labels are rendered and the CSS shows one, so the text follows the state without
            // a line of JavaScript — the same reason the fold is a <details> at all.
            $html .= '<details class="bho-more"><summary>'
                . '<span class="bho-when-closed">' . esc_html($this->t['show_more']) . '</span>'
                . '<span class="bho-when-open">' . esc_html($this->t['show_less']) . '</span>'
                . '</summary><ul class="bho-recent">';
            foreach ($rest as $game) {
                $html .= $this->recentRow($game);
            }
            $html .= '</ul></details>';
        }

        $html .= $this->allGamesLink();

        return $this->wrap($html);
    }

    /** @param array<string,mixed> $game */
    private function recentRow(array $game): string
    {
        return '<li><span class="bho-round">R' . esc_html((string) $game['round']) . '</span>'
            . '<span class="bho-side">' . $this->flag($game['one']['country'] ?? null) . ' '
            . $this->playerLink($game['one']) . ' ' . $this->change($game['one']['change']) . '</span>'
            . '<span class="bho-score">' . esc_html($game['one']['score'] . '–' . $game['two']['score']) . '</span>'
            . '<span class="bho-side bho-right">' . $this->change($game['two']['change']) . ' '
            . $this->playerLink($game['two']) . ' ' . $this->flag($game['two']['country'] ?? null) . '</span>'
            . '</li>';
    }

    /**
     * Every game of the season, newest first, a page at a time.
     *
     * A table rather than the two-line rows the block uses: this is the page somebody opens to look
     * something up, and a column is what you scan.
     */
    public function allGames(int $perPage, int $page): string
    {
        $data = $this->api->games($page, $perPage);

        if (is_wp_error($data)) {
            return $this->wrap($this->notice($this->t['unavailable'] . ' (' . $data->get_error_message() . ')'));
        }

        $games = $data['games'] ?? [];
        if ($games === []) {
            return $this->wrap($this->notice($this->t['empty']));
        }

        $html = '';
        if ($this->api->servedStale()) {
            $html .= $this->notice($this->t['stale']);
        }

        $html .= '<p class="bho-foot">' . esc_html(sprintf(
            $this->t['games_total'],
            (int) ($data['total'] ?? 0),
            (int) ($data['page'] ?? 1),
            (int) ($data['pages'] ?? 1),
        )) . '</p>';

        $html .= '<table class="bho-table bho-all"><thead><tr>'
            . '<th class="bho-w-day">' . esc_html($this->t['day']) . '</th>'
            . '<th class="bho-wide">' . esc_html($this->t['tournament']) . '</th>'
            . '<th class="bho-w-round bho-num">' . esc_html($this->t['round']) . '</th>'
            . '<th>' . esc_html($this->t['player']) . '</th>'
            . '<th class="bho-num bho-w-score">' . esc_html($this->t['score']) . '</th>'
            . '<th>' . esc_html($this->t['opponent']) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($games as $game) {
            $html .= '<tr' . ($game['excluded'] ? ' class="bho-excluded"' : '') . '>'
                . '<td class="bho-w-day bho-quiet">' . esc_html(self::formatDay((string) $game['startDate'])) . '</td>'
                . '<td class="bho-wide bho-quiet">' . esc_html((string) $game['tournament']) . '</td>'
                . '<td class="bho-w-round bho-num bho-quiet">' . esc_html((string) $game['round']) . '</td>'
                . '<td class="bho-name-cell">' . $this->side($game['one']) . '</td>'
                . '<td class="bho-num bho-w-score">' . esc_html($game['one']['score'] . '–' . $game['two']['score']) . '</td>'
                . '<td class="bho-name-cell">' . $this->side($game['two']) . '</td>'
                . '</tr>';
        }

        return $this->wrap($html . '</tbody></table>' . $this->pager($data));
    }

    /** One side of a game in the wide table: flag, name, and what the game did to the rating. */
    private function side(array $player): string
    {
        return '<span class="bho-cell-side">' . $this->flag($player['country'] ?? null)
            . $this->playerLink($player) . ' ' . $this->change($player['change']) . '</span>';
    }

    /**
     * Previous and next, and nothing else.
     *
     * Numbered pages would need every number to be a link somebody can land on, and with 40 games
     * and no season behind us there is nothing to jump to yet. Two links are honest about that.
     *
     * @param array<string,mixed> $data
     */
    private function pager(array $data): string
    {
        $page = (int) ($data['page'] ?? 1);
        $pages = (int) ($data['pages'] ?? 1);

        if ($pages <= 1) {
            return '';
        }

        $link = static function (int $to, string $label): string {
            return '<a href="' . esc_url(add_query_arg(BHO_LADDER_PAGE_PARAM, $to, get_permalink()))
                . '">' . esc_html($label) . '</a>';
        };

        $html = '<p class="bho-pager">';
        $html .= $page > 1 ? $link($page - 1, $this->t['previous']) : '<span>' . esc_html($this->t['previous']) . '</span>';
        $html .= '<span class="bho-pager-of">' . esc_html(sprintf($this->t['page_of'], $page, $pages)) . '</span>';
        $html .= $page < $pages ? $link($page + 1, $this->t['next']) : '<span>' . esc_html($this->t['next']) . '</span>';

        return $html . '</p>';
    }

    /** Only when a page has been named in the settings — otherwise there is nowhere to send anybody. */
    private function allGamesLink(): string
    {
        $page = BHO_Settings::all()['games_page'];

        if ($page <= 0 || !get_post($page)) {
            return '';
        }

        return '<p class="bho-foot"><a href="' . esc_url((string) get_permalink($page)) . '">'
            . esc_html($this->t['all_games']) . '</a></p>';
    }

    /** The wrapper every entry point needs, so the CSS variables are in scope. */
    private function wrap(string $html): string
    {
        return '<div class="bho-ladder">' . $html . '</div>';
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
