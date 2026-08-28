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

    public function ladder(int $limit, bool $withRules = true, string $sort = '', int $games = 8): string
    {
        $data = $this->api->ladder(null, $games);

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

        $html .= $this->meta($data['tournaments'] ?? [], $data['updatedAt'] ?? null);

        if ($entries === []) {
            return $html . $this->notice($this->t['empty']) . '</div>';
        }

        // Sorted before the slice, so a teaser of ten is the top ten of whatever is being sorted by
        // rather than the first ten of the standings, re-ordered among themselves.
        $entries = $this->sorted($entries, $sort);
        $html .= $this->table($limit > 0 ? array_slice($entries, 0, $limit) : $entries, $sort);

        $latest = $games > 0 ? $this->recentGames($data['games'] ?? []) : '';
        $rules = $withRules ? $this->rules($data['rules'] ?? [], $data['ranks'] ?? []) : '';

        // Side by side when both are there, and the survivor takes the width when only one is: a grid
        // with an empty second column leaves the first one at three fifths for no reason.
        if ($latest !== '' && $rules !== '') {
            $html .= '<div class="bho-columns"><div>' . $latest . '</div><div>' . $rules . '</div></div>';
        } else {
            $html .= $latest . $rules;
        }

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

        // Only the games that counted for this player, so this line agrees with their row in the
        // table: a result taken out of the rating is out of the record with it.
        $counted = array_values(array_filter(
            $games,
            static fn(array $g): bool => !(bool) ($g['excluded'] ?? false),
        ));

        $record = [
            'WIN' => 0,
            'DRAW' => 0,
            'LOSS' => 0,
        ];
        foreach ($counted as $game) {
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
            count($counted),
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
    private function table(array $entries, string $sort = ''): string
    {
        $html = '<table class="bho-table"><thead><tr>'
            . $this->head('place', '#', 'bho-pos', $sort)
            . $this->head('name', $this->t['player'], '', $sort)
            . $this->head('rating', $this->t['rating'], 'bho-num bho-w-rating', $sort)
            . '<th class="bho-w-rank">' . esc_html($this->t['rank']) . '</th>'
            . '<th class="bho-num bho-w-record">' . esc_html($this->t['record']) . '</th>'
            . $this->head('games', $this->t['games'], 'bho-num bho-w-games bho-wide', $sort)
            . $this->head('events', $this->t['events'], 'bho-num bho-w-events bho-wide', $sort)
            . '</tr></thead><tbody>';

        // Carried into the player's link and back out of it again, so a reader who sorted by games,
        // looked somebody up and came back is still looking at the table they left.
        [$key, $descending] = $this->readSort($sort);
        $keep = $key === null || $key === 'place'
            ? []
            : [BHO_LADDER_SORT_PARAM => ($descending ? '-' : '') . $key];

        foreach ($entries as $entry) {
            $position = (int) $entry['position'];
            $html .= '<tr class="bho-row bho-place-' . esc_attr((string) min($position, 4)) . '">'
                . '<td class="bho-pos">' . $this->placement($position) . '</td>'
                . '<td class="bho-name"><a href="'
                . esc_url(add_query_arg(
                    array_merge([BHO_LADDER_PLAYER_PARAM => (int) $entry['id']], $keep),
                    get_permalink(),
                ))
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
                . '<td class="bho-num bho-w-games bho-wide">' . esc_html((string) $entry['games']) . '</td>'
                . '<td class="bho-num bho-w-events bho-wide">' . esc_html((string) $entry['tournaments']) . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * The columns a reader can sort by, and which way round each one starts.
     *
     * Rank is not among them: it is the rating in a badge, so a column of its own would be a second
     * button doing the same thing. Nor is the record, where "best" is a question the club has an
     * answer to — the Turnier Score — and a column head is the wrong place to argue it.
     */
    private const SORTABLE = [
        'place' => 'asc',
        'name' => 'asc',
        'rating' => 'desc',
        'games' => 'desc',
        'events' => 'desc',
    ];

    /**
     * @param array<int,array<string,mixed>> $entries
     * @return array<int,array<string,mixed>>
     */
    private function sorted(array $entries, string $sort): array
    {
        [$key, $descending] = $this->readSort($sort);

        if ($key === null || $key === 'place') {
            return $entries;
        }

        $of = static fn(array $entry): string|int => match ($key) {
            // Case-folded, or every lowercase handle sorts after every uppercase one.
            'name' => mb_strtolower((string) $entry['name']),
            'events' => (int) $entry['tournaments'],
            default => (int) $entry[$key],
        };

        usort($entries, static function (array $a, array $b) use ($of, $descending): int {
            // The standings' own order breaks every tie, so a column of equal numbers stays in the
            // order the reader already knows rather than in whatever order the sort happened to make.
            return ($descending ? $of($b) <=> $of($a) : $of($a) <=> $of($b))
                ?: (int) $a['position'] <=> (int) $b['position'];
        });

        return $entries;
    }

    /**
     * @return array{0: string|null, 1: bool} the column and whether it is descending
     */
    private function readSort(string $sort): array
    {
        $descending = str_starts_with($sort, '-');
        $key = ltrim($sort, '-');

        if (!isset(self::SORTABLE[$key])) {
            return [null, false];
        }

        return [$key, $descending];
    }

    /** A column head that is a link to its own sorting, with the arrow of the state it is in. */
    private function head(string $key, string $label, string $class, string $sort): string
    {
        [$active, $descending] = $this->readSort($sort);
        $isActive = $active === $key || ($active === null && $key === 'place');

        // Clicking the column you are already sorted by turns it round; a fresh column starts the way
        // that column is usually read — names from A, numbers from the top.
        $next = $isActive
            ? ($descending ? $key : '-' . $key)
            : (self::SORTABLE[$key] === 'desc' ? '-' . $key : $key);

        $url = add_query_arg(BHO_LADDER_SORT_PARAM, $next, get_permalink());
        $arrow = $isActive ? ($descending ? '↓' : '↑') : '';

        return '<th class="' . esc_attr(trim($class . ($isActive ? ' bho-sorted' : ''))) . '"'
            . ($isActive ? ' aria-sort="' . ($descending ? 'descending' : 'ascending') . '"' : '')
            . '><a href="' . esc_url($url) . '" title="'
            . esc_attr(sprintf($this->t['sort_by'], $label)) . '">' . esc_html($label)
            . ($arrow !== '' ? '<span class="bho-arrow" aria-hidden="true">' . $arrow . '</span>' : '')
            . '</a></th>';
    }

    /**
     * The last few games, from the rows the standings came with.
     *
     * What is shown is all there is: no fold, and the link under the list goes to every game there is
     * instead. Two ways of seeing more of the same list, one of them ending in a page that shows all
     * of it anyway, was one too many.
     *
     * @param array<int,array<string,mixed>> $games
     */
    private function recentGames(array $games): string
    {
        if ($games === []) {
            return '';
        }

        $html = '<h3 class="bho-group bho-eyebrow">' . esc_html($this->t['latest']) . '</h3>'
            . '<ul class="bho-recent">';

        foreach ($games as $game) {
            $html .= $this->recentRow($game);
        }

        return $html . '</ul>' . $this->allGamesLink();
    }

    /**
     * One game as a row: round, day, the two sides with the class each brought and what the game did
     * to them, and the score between.
     *
     * The class is the one the player held when this game was scored, which is where the number
     * beside it comes from — the step is chosen by the two classes and by nothing else. It is not
     * their class today: that is in the standings, and printing it here would label a game from July
     * with a fact from this morning.
     *
     * @param array<string,mixed> $game
     */
    private function recentRow(array $game, bool $withEvent = false): string
    {
        $one = (bool) ($game['one']['excluded'] ?? false) ? ' bho-excluded' : '';
        $two = (bool) ($game['two']['excluded'] ?? false) ? ' bho-excluded' : '';

        // Each side carries its own half of the score. Between the names on a wide screen it reads as
        // one score with the dash in the middle, and on a phone, where the two players go on separate
        // lines, each keeps the number that is his — a score stranded on a third line belonged to
        // neither of them.
        return '<li><span class="bho-round">R' . esc_html((string) $game['round']) . '</span>'
            . $this->day($game['playedOn'] ?? null)
            . '<span class="bho-side' . $one . '">' . $this->flag($game['one']['country'] ?? null) . ' '
            . $this->playedAt($game['one']['rank'] ?? null) . ' '
            . $this->playerLink($game['one']) . ' ' . $this->change($game['one']['change']) . ' '
            . $this->scoreBox($game['one']['score']) . '</span>'
            . '<span class="bho-score-sep" aria-hidden="true">–</span>'
            . '<span class="bho-side bho-right' . $two . '">' . $this->scoreBox($game['two']['score'])
            . ' ' . $this->change($game['two']['change']) . ' '
            . $this->playerLink($game['two']) . ' ' . $this->playedAt($game['two']['rank'] ?? null)
            . ' ' . $this->flag($game['two']['country'] ?? null) . '</span>'
            . ($withEvent ? '<span class="bho-event">' . esc_html((string) $game['tournament']) . '</span>' : '')
            . '</li>';
    }

    /**
     * Every game of the season, newest first, a page at a time.
     *
     * The same rows as the block, because it is the same list — one page of it at a time. A table of
     * its own drifted from the block in spacing, in what it showed and in how a score was drawn, and
     * two ways of printing one thing is one too many.
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

        // The event is only information when it varies, and one season has held one so far: a column
        // repeating the same name down twenty-five rows is what the block leaves out on purpose.
        $several = count(array_unique(array_column($games, 'tournament'))) > 1;

        $html .= '<ul class="bho-recent">';
        foreach ($games as $game) {
            $html .= $this->recentRow($game, $several);
        }

        return $this->wrap($html . '</ul>' . $this->pager($data));
    }

    /**
     * A score as two boxes rather than one string.
     *
     * `18–4` and `6–16` are different shapes, so a column of them read as a jumble however the parts
     * were aligned. Two boxes of one width — two digits wide, whether the score needs them or not —
     * give the column a grid, and unlike a padded `04` they invent no score nobody played.
     */
    private function score(int|string $one, int|string $two): string
    {
        return '<span class="bho-score">' . $this->scoreBox($one)
            . '<span class="bho-score-sep">–</span>' . $this->scoreBox($two) . '</span>';
    }

    /** Two digits wide whichever number goes in it, which is what lines a column of them up. */
    private function scoreBox(int|string $value): string
    {
        return '<span class="bho-score-box">' . esc_html((string) $value) . '</span>';
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
        // Shown and marked rather than left out: this player did not get the points, and a game that
        // vanished from their own list would look like one nobody can find again.
        $excluded = (bool) ($game['excluded'] ?? false);

        return '<li' . ($excluded ? ' class="bho-excluded"' : '') . '>'
            . '<span class="bho-round">R' . esc_html((string) $game['round']) . '</span>'
            . $this->day($game['playedOn'] ?? null)
            . '<a class="bho-opponent" href="'
            . esc_url(add_query_arg(BHO_LADDER_PLAYER_PARAM, (int) $game['opponent']['id'], get_permalink()))
            . '">' . $this->flag($game['opponent']['country'] ?? null)
            . '<span>' . esc_html((string) $game['opponent']['name']) . '</span></a>'
            . $this->score($game['score'], $game['opponentScore'])
            . '<span class="bho-result bho-' . esc_attr($result) . '">' . esc_html($this->t[$result]) . '</span>'
            . '<span class="bho-delta">' . $this->change($game['ratingChange']) . '</span>'
            . '<span class="bho-after">' . esc_html((string) ($game['ratingAfter'] ?? '')) . '</span>'
            . '<span class="bho-teams">' . esc_html((string) ($game['killTeam'] ?? '—'))
            . ' <em>' . esc_html($this->t['versus']) . '</em> '
            . esc_html((string) ($game['opponentKillTeam'] ?? '—'))
            . ($excluded ? ' <em class="bho-tag">' . esc_html($this->t['not_counted']) . '</em>' : '')
            . '</span>'
            . '</li>';
    }

    /**
     * One line above the table: what is being played, and when the results were last read.
     *
     * A line rather than the panel this replaced. Both are asides — you look once and then read the
     * table — and two stacked boxes above the standings pushed the standings themselves off the top
     * of a phone.
     *
     * @param array<int,array<string,mixed>> $tournaments
     * @param mixed $updatedAt
     */
    private function meta(array $tournaments, $updatedAt): string
    {
        $running = array_values(array_filter($tournaments, static fn(array $t): bool => !$t['hasConcluded']));
        $updated = $this->updated($updatedAt);

        if ($running === [] && $updated === '') {
            return '';
        }

        $html = '<div class="bho-meta"><div class="bho-live">';

        if ($running !== []) {
            $html .= '<p class="bho-eyebrow">'
                . esc_html(count($running) === 1 ? $this->t['running_one'] : $this->t['running_many'])
                . '</p>';
        }

        foreach ($running as $tournament) {
            $html .= '<p><span class="bho-dot" aria-hidden="true"></span>'
                . '<strong>' . esc_html((string) $tournament['name']) . '</strong>'
                . '<span>' . esc_html(self::formatDay((string) $tournament['startDate'])
                . ' – ' . self::formatDay((string) $tournament['endDate'])) . '</span>'
                . '<a href="' . esc_url((string) $tournament['url'])
                . '" target="_blank" rel="noopener">' . esc_html($this->t['herald']) . '</a></p>';
        }

        return $html . '</div>' . $updated . '</div>';
    }

    /**
     * When the results behind the table were last read from Herald.
     *
     * The application's own timestamp, not this plugin's: the cached copy here is refreshed every few
     * minutes whether anything changed or not, and "updated 2 minutes ago" over a table that has not
     * moved since Sunday would be a lie the reader cannot check.
     *
     * @param mixed $updatedAt
     */
    private function updated($updatedAt): string
    {
        if (!is_string($updatedAt) || $updatedAt === '') {
            return '';
        }

        $when = date_create_immutable($updatedAt);

        if ($when === false) {
            return '';
        }

        return '<p class="bho-updated">' . esc_html(sprintf(
            $this->t['updated'],
            wp_date('d.m.y, H:i', $when->getTimestamp()) ?: '',
        )) . '</p>';
    }

    /**
     * What a reader needs to check a row of the table: where everyone starts, what a game is worth,
     * what a tournament is worth, and where the classes begin.
     *
     * Every number comes from the payload. Printing the booklet's table from memory here would mean
     * a page that keeps claiming +60 for weeks after the rule behind it changed.
     *
     * @param array<string,mixed> $rules
     * @param array<int,array<string,mixed>> $brackets
     */
    private function rules(array $rules, array $brackets): string
    {
        if ($rules === [] && $brackets === []) {
            return '';
        }

        $html = '<section class="bho-rules"><h3 class="bho-eyebrow">'
            . esc_html($this->t['rules']) . '</h3>';

        if (isset($rules['startingRating'])) {
            $html .= '<p class="bho-rules-line">' . esc_html($this->t['start_rating'])
                . ' <strong>' . esc_html((string) (int) $rules['startingRating']) . '</strong></p>';
        }

        $html .= $this->points(is_array($rules['points'] ?? null) ? $rules['points'] : []);
        $html .= $this->bonus(is_array($rules['tournamentBonus'] ?? null) ? $rules['tournamentBonus'] : []);

        if ($brackets !== []) {
            $html .= '<p class="bho-rules-label">' . esc_html($this->t['rank_classes']) . '</p>'
                . $this->legend($brackets);
        }

        return $html . '</section>';
    }

    /**
     * The nine cells, as three rows against the opponent's class.
     *
     * @param array<string,mixed> $points
     */
    private function points(array $points): string
    {
        $rows = ['win', 'draw', 'loss'];
        $columns = ['higher', 'same', 'lower'];

        foreach ($rows as $row) {
            if (!is_array($points[$row] ?? null)) {
                return '';
            }
        }

        $html = '<p class="bho-rules-label">' . esc_html($this->t['per_game']) . '</p>'
            . '<table class="bho-rules-points"><thead><tr><th></th>';

        foreach ($columns as $column) {
            $html .= '<th>' . esc_html($this->t['vs_' . $column]) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><th>' . esc_html($this->t[$row]) . '</th>';

            foreach ($columns as $column) {
                $html .= '<td>' . $this->change((int) $points[$row][$column]) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * The bonus for finishing a tournament in the top three, once all of its rounds are played.
     *
     * @param array<int,array<string,mixed>> $bonus
     */
    private function bonus(array $bonus): string
    {
        if ($bonus === []) {
            return '';
        }

        $places = [1 => 'first', 2 => 'second', 3 => 'third'];
        $parts = [];

        foreach ($bonus as $awarded) {
            $place = (int) ($awarded['place'] ?? 0);
            $label = isset($places[$place]) ? $this->t[$places[$place]] : (string) $place;

            $parts[] = '<span class="bho-rules-place">' . esc_html($label) . ' '
                . $this->change((int) ($awarded['points'] ?? 0)) . '</span>';
        }

        return '<p class="bho-rules-label">' . esc_html($this->t['bonus'])
            . ' <span>' . esc_html($this->t['bonus_note']) . '</span></p>'
            . '<p class="bho-rules-bonus">' . implode('', $parts) . '</p>';
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
                . ' <span class="bho-legend-range">' . esc_html($span) . '</span></span>';
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
     * The class a player held at one game, or nothing at all.
     *
     * Nothing where the ladder sent no class: a tournament belonging to no season is never replayed,
     * so there is no rating to read one off — and nothing, too, against a ladder from before this
     * field existed, which is a plugin that updates on its own schedule from an application that
     * does not. A placeholder would be a claim; a gap is the truth.
     *
     * The title is the only thing naming what the badge is. Beside the table there is a column head
     * saying Rang, and in a row of games there is nowhere to put one.
     */
    private function playedAt(?string $rank): string
    {
        if (!is_string($rank) || $rank === '') {
            return '';
        }

        return '<span class="bho-rank-then" title="'
            . esc_attr(sprintf($this->t['rank_then'], $rank)) . '">' . $this->rank($rank) . '</span>';
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

    /**
     * The day a game was played.
     *
     * Blank rather than the tournament's start date where nobody knows — everything imported before
     * the day was recorded has none, and borrowing the event's first day would state a date the game
     * does not have. The column is kept either way, so the names below it stay in line.
     *
     * @param mixed $playedOn
     */
    private function day($playedOn): string
    {
        $known = is_string($playedOn) && $playedOn !== '';

        return '<span class="bho-day">' . ($known ? esc_html(self::formatDay($playedOn)) : '') . '</span>';
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
