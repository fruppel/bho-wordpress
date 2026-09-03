<?php

namespace BHO\Tests;

use BHO_Api;
use BHO_Render;
use BHO_Test_Site;

/**
 * The standings block: the table, its sorting and what a failing ladder leaves on the page.
 *
 * The sorting is the part with real logic in it. It runs on an array the page already holds, in the
 * URL rather than in a script, so a sorted table is a link somebody can send — and the URL is
 * somebody else's input, which is why the whitelist matters more than the order does.
 */
final class LadderPageTest extends PluginTestCase
{
    public function testTheStandingsAreDrawnInTheOrderTheyArrive(): void
    {
        $this->standings([
            $this->entry(1, 'Fenrisson', rating: 1410),
            $this->entry(2, 'Lumen', rating: 1320),
        ]);

        self::assertSame(['Fenrisson', 'Lumen'], $this->names($this->render()->ladder(0)));
    }

    public function testAColumnInTheUrlSortsTheTable(): void
    {
        $this->standings([
            $this->entry(1, 'Fenrisson', games: 3),
            $this->entry(2, 'Lumen', games: 9),
        ]);

        self::assertSame(['Lumen', 'Fenrisson'], $this->names($this->render()->ladder(0, sort: '-games')));
    }

    public function testTheLeadingMinusIsTheOtherDirection(): void
    {
        $this->standings([
            $this->entry(1, 'Fenrisson', games: 3),
            $this->entry(2, 'Lumen', games: 9),
        ]);

        self::assertSame(['Fenrisson', 'Lumen'], $this->names($this->render()->ladder(0, sort: 'games')));
    }

    public function testNamesSortWithoutRegardToCase(): void
    {
        // Otherwise every lowercase handle lands after every uppercase one, which reads as no sorting
        // at all to somebody who clicked "Player".
        $this->standings([
            $this->entry(1, 'ashvale'),
            $this->entry(2, 'Bruchpilot'),
            $this->entry(3, 'Cinderhawk'),
        ]);

        self::assertSame(
            ['ashvale', 'Bruchpilot', 'Cinderhawk'],
            $this->names($this->render()->ladder(0, sort: 'name')),
        );
    }

    public function testEqualNumbersKeepTheOrderTheReaderAlreadyKnows(): void
    {
        // A column of equal values must not shuffle: the standings' own order is the tie-break, so a
        // table sorted by games looks like the table with one column reordered, not like a new one.
        $this->standings([
            $this->entry(1, 'Fenrisson', games: 7),
            $this->entry(2, 'Lumen', games: 7),
            $this->entry(3, 'Eisregen', games: 7),
        ]);

        self::assertSame(
            ['Fenrisson', 'Lumen', 'Eisregen'],
            $this->names($this->render()->ladder(0, sort: '-games')),
        );
    }

    public function testAColumnNobodyOffersIsIgnored(): void
    {
        // The sort key is a query parameter, so it is whatever somebody put in the URL. An unknown
        // one leaves the table alone rather than reaching for a field that is not there.
        $this->standings([$this->entry(1, 'Fenrisson'), $this->entry(2, 'Lumen')]);

        self::assertSame(
            ['Fenrisson', 'Lumen'],
            $this->names($this->render()->ladder(0, sort: '-passwordHash')),
        );
    }

    public function testAColumnHeadLinksToTheSortingItWouldApply(): void
    {
        $this->standings([$this->entry(1, 'Fenrisson')]);

        $html = $this->render()->ladder(0);

        // Ratings are read from the top, so the first click on that column is descending.
        self::assertStringContainsString('bho_sort=-rating', $html);
    }

    public function testClickingTheColumnYouAreSortedByTurnsItRound(): void
    {
        $this->standings([$this->entry(1, 'Fenrisson')]);

        $html = $this->render()->ladder(0, sort: '-rating');

        self::assertStringContainsString('bho_sort=rating', $html);
        self::assertStringContainsString('aria-sort="descending"', $html);
    }

    public function testTheLimitCutsTheTableAndNothingElse(): void
    {
        $this->standings([
            $this->entry(1, 'Fenrisson'),
            $this->entry(2, 'Lumen'),
            $this->entry(3, 'Eisregen'),
        ]);

        self::assertCount(2, $this->names($this->render()->ladder(2)));
    }

    public function testAnEmptyLadderSaysSoRatherThanDrawingAnEmptyTable(): void
    {
        $this->standings([]);

        self::assertStringContainsString('Nothing has been imported so far.', $this->render()->ladder(0));
    }

    public function testAnUnreachableLadderLeavesAnExplanationWhereTheTableWouldBe(): void
    {
        // The one thing that must not happen is a blank space where the club's ladder belongs.
        $this->unreachable('Connection timed out');

        $html = $this->render()->ladder(0);

        self::assertStringContainsString('bho-notice', $html);
        self::assertStringContainsString('Connection timed out', $html);
    }

    public function testAPlayersNameIsEscapedInTheTableToo(): void
    {
        $this->standings([$this->entry(1, '<img src=x onerror=alert(1)>')]);

        $html = $this->render()->ladder(0);

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testTheVersionIsPrintedOnceHoweverManyBlocksAPageHolds(): void
    {
        // It is a footnote, and two of them under one page is a bug somebody has to look at twice.
        $first = bho_ladder_version_line();
        $second = bho_ladder_version_line();

        self::assertStringContainsString('v' . BHO_LADDER_VERSION, $first);
        self::assertSame('', $second);
    }

    public function testASeasonNamedByTheShortcodeIsWhatTheBlockAsksFor(): void
    {
        self::assertSame(12, bho_ladder_season_in(['season' => '12']));
        self::assertNull(bho_ladder_season_in(['season' => '']), 'no season means the default one');
        self::assertNull(bho_ladder_season_in([]), 'and so does leaving the attribute out');
    }

    public function testASeasonThatIsNotANumberIsTreatedAsNone(): void
    {
        // The attribute is typed into a page by a person. `season="Kill Team"` asking for season 0
        // would be an empty table with nothing to explain it.
        self::assertNull(bho_ladder_season_in(['season' => 'Kill Team']));
        self::assertNull(bho_ladder_season_in(['season' => '0']));
    }

    public function testAMinusOnTheSeasonIsDroppedRatherThanRefused(): void
    {
        // `absint()`, so `season="-3"` is season 3 rather than the default one. Recorded rather than
        // corrected: it is a typo either way, and both readings show a table nobody asked for.
        self::assertSame(3, bho_ladder_season_in(['season' => '-3']));
    }

    // -----------------------------------------------------------------------

    private function render(?int $season = null): BHO_Render
    {
        return new BHO_Render(new BHO_Api('https://ladder.example'), bho_ladder_strings(), $season);
    }

    /** @param list<array<string,mixed>> $entries */
    private function standings(array $entries): void
    {
        $this->answer([
            'entries' => $entries,
            'tournaments' => [],
            'games' => [],
            'notes' => [],
            'ranks' => [],
            'rules' => ['startingRating' => 1100, 'points' => []],
            'updatedAt' => '2026-09-02T13:30:00+00:00',
        ]);
        // The rules panel and the notices are their own reads; nothing here is about them.
        BHO_Test_Site::$answers[] = ['response' => ['code' => 200], 'body' => json_encode(['notices' => []])];
    }

    /** @return array<string,mixed> */
    private function entry(
        int $position,
        string $name,
        int $rating = 1200,
        int $games = 5,
        int $tournaments = 2,
    ): array {
        return [
            'position' => $position,
            'id' => 100 + $position,
            'name' => $name,
            'country' => 'DE',
            'rating' => $rating,
            'rank' => 'C',
            'wins' => 3,
            'draws' => 1,
            'losses' => 1,
            'games' => $games,
            'tournaments' => $tournaments,
        ];
    }

    /**
     * The names in the table, in the order they are drawn.
     *
     * @return list<string>
     */
    private function names(string $html): array
    {
        preg_match_all('/<td class="bho-name">.*?<span>(.*?)<\/span>/s', $html, $found);

        return $found[1];
    }
}
