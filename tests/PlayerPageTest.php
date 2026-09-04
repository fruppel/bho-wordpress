<?php

namespace BHO\Tests;

use BHO_Api;
use BHO_Render;

/**
 * A player's season, drawn as one table.
 *
 * The words here are English because that is the plugin's default and not the site's locale — see
 * StringsTest, which is where the choosing is pinned.
 *
 * The page's whole claim is that the last column can be followed from the first row to the last, so
 * what is worth pinning is the *order* and what happens when the ladder does not supply one — this
 * plugin is deployed separately from the application it reads, and has to survive an older answer.
 */
final class PlayerPageTest extends PluginTestCase
{
    public function testGamesAndBonusesAreOneTableInTheOrderTheyWereReplayed(): void
    {
        // Interleaved on purpose: the API sends two lists, and appending one after the other puts a
        // bonus for the first event under the last one.
        $this->answerWith([
            'games' => [
                $this->game(sequence: 0, ratingAfter: 1150),
                $this->game(sequence: 2, ratingAfter: 1275),
            ],
            'awards' => [$this->award(sequence: 1, points: 75, ratingAfter: 1225)],
        ]);

        $html = $this->render()->player(31);

        self::assertSame(
            ['1150', '1225', '1275'],
            $this->column($html, 'bho-after'),
            'the rating column has to read down the page',
        );
    }

    public function testAnAnswerWithoutSequenceStillRenders(): void
    {
        // The documented fallback: against a ladder that does not send `sequence` the page falls back
        // to games first and bonuses after, which is wrong in order and right in content — and is
        // what an older application answers with.
        $this->answerWith([
            'games' => [$this->game(ratingAfter: 1150), $this->game(ratingAfter: 1200)],
            'awards' => [$this->award(points: 75, ratingAfter: 1275)],
        ]);

        $html = $this->render()->player(31);

        self::assertSame(['1150', '1200', '1275'], $this->column($html, 'bho-after'));
    }

    public function testTheRatingInTheHeadIsWhereTheLastRowLeftIt(): void
    {
        // Not the last game and not the last award: whichever of the two actually happened last.
        $this->answerWith([
            'games' => [$this->game(sequence: 0, ratingAfter: 1150)],
            'awards' => [$this->award(sequence: 1, points: 75, ratingAfter: 1225)],
        ]);

        $html = $this->render()->player(31);

        self::assertStringContainsString('<strong>1225</strong>', $html);
    }

    public function testTheHeadCountsTheSeasonsOwnStartingRating(): void
    {
        // "von 1100" beside a table that began at 1500 is a page arguing with itself.
        $this->answerWith([
            'startingRating' => 1500,
            'games' => [$this->game(sequence: 0, ratingAfter: 1550)],
        ]);

        self::assertStringContainsString('from 1500', $this->render()->player(31));
    }

    public function testAnOlderLadderThatSendsNoStartingRatingFallsBackToTheBooklets(): void
    {
        $this->answerWith(['games' => [$this->game(sequence: 0, ratingAfter: 1150)]]);

        self::assertStringContainsString('from 1100', $this->render()->player(31));
    }

    public function testAResultTakenOutIsShownAndLeftOutOfTheRecord(): void
    {
        // Both halves matter: dropping the row would look like a game nobody can find again, and
        // counting it would make the record disagree with the rating beside it.
        $this->answerWith([
            'games' => [
                $this->game(sequence: 0, ratingAfter: 1150),
                $this->game(sequence: 1, ratingAfter: 1150, excluded: true),
            ],
        ]);

        $html = $this->render()->player(31);

        self::assertStringContainsString('bho-excluded', $html);
        self::assertStringContainsString('1–0–0', $html, 'one win, and the excluded game is not in it');
    }

    public function testABonusNamesItsEventAndItsPlacing(): void
    {
        $this->answerWith([
            'awards' => [$this->award(
                sequence: 0,
                points: 75,
                ratingAfter: 1175,
                reason: 'tournamentFirst',
                tournament: 'BLACK HYDRA OPEN — I',
            )],
        ]);

        $html = $this->render()->player(31);

        self::assertStringContainsString('BLACK HYDRA OPEN — I', $html);
        self::assertStringContainsString('1st place', $html);
    }

    public function testAReasonThisPluginHasNoWordsForFallsBackToWhatWasTyped(): void
    {
        // The application can add a reason before a site is updated, and a raw key on a public page
        // helps nobody.
        $this->answerWith([
            'awards' => [$this->award(
                sequence: 0,
                points: 40,
                ratingAfter: 1140,
                reason: 'somethingNewOverThere',
                note: 'Ran the desk all weekend',
            )],
        ]);

        self::assertStringContainsString('Ran the desk all weekend', $this->render()->player(31));
    }

    public function testTheNoteStandsBesideTheReasonRatherThanReplacingIt(): void
    {
        $this->answerWith([
            'awards' => [$this->award(
                sequence: 0,
                points: 25,
                ratingAfter: 1125,
                reason: 'other',
                note: 'Best painted',
            )],
        ]);

        self::assertStringContainsString('Other — Best painted', $this->render()->player(31));
    }

    public function testAPlayerWithNothingInThisSeasonSaysSoRatherThanRenderingAnEmptyTable(): void
    {
        $this->answerWith(['games' => [], 'awards' => []]);

        $html = $this->render()->player(31);

        self::assertStringContainsString('No games yet.', $html);
        self::assertStringNotContainsString('<ul class="bho-games">', $html);
    }

    public function testAPlayerNobodyKnowsIsSaidPlainlyRatherThanAsAnError(): void
    {
        $this->refuse(404);

        self::assertStringContainsString('No such player.', $this->render()->player(99));
    }

    public function testAPlayersNameIsEscapedBeforeItReachesThePage(): void
    {
        // The names come from Tabletop Herald, where people type them. This is the one place in the
        // plugin where somebody else's text becomes HTML on the club's site.
        $this->answerWith([
            'player' => ['id' => 31, 'name' => '<script>alert(1)</script>', 'country' => 'DE'],
            'games' => [$this->game(sequence: 0, ratingAfter: 1150)],
        ]);

        $html = $this->render()->player(31);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTheBlocksSeasonReachesThePlayerPage(): void
    {
        // Without it the page is a career across every game the club runs, under standings covering
        // one of them.
        $this->answerWith(['games' => []]);

        $this->render(season: 12)->player(31);

        self::assertStringContainsString('season=12', $this->asked()[0]);
    }

    public function testABlockThatNamesNoSeasonAsksForTheDefaultOneExplicitly(): void
    {
        $this->answerWith(['games' => []]);

        $this->render()->player(31);

        self::assertStringContainsString('season=default', $this->asked()[0]);
    }

    // -----------------------------------------------------------------------

    private function render(?int $season = null): BHO_Render
    {
        return new BHO_Render(new BHO_Api('https://ladder.example'), bho_ladder_strings(), $season);
    }

    /** @param array<string,mixed> $body */
    private function answerWith(array $body): void
    {
        $this->answer($body + [
            'player' => ['id' => 31, 'name' => 'Fenrisson', 'country' => 'DE'],
            'startingRating' => 1100,
            'games' => [],
            'awards' => [],
        ]);
    }

    /** @return array<string,mixed> */
    private function game(
        ?int $sequence = null,
        int $ratingAfter = 1150,
        bool $excluded = false,
    ): array {
        $game = [
            'tournament' => 'BLACK HYDRA OPEN — I',
            'round' => 1,
            'playedOn' => '2026-07-19',
            'killTeam' => 'Kasrkin',
            'opponent' => ['id' => 32, 'name' => 'Eisregen', 'country' => 'CH'],
            'opponentKillTeam' => 'Hearthkyn',
            'score' => 17,
            'opponentScore' => 11,
            'result' => 'WIN',
            'ratingChange' => 50,
            'ratingAfter' => $ratingAfter,
            'excluded' => $excluded,
        ];

        return $sequence === null ? $game : $game + ['sequence' => $sequence];
    }

    /** @return array<string,mixed> */
    private function award(
        ?int $sequence = null,
        int $points = 75,
        int $ratingAfter = 1175,
        string $reason = 'tournamentFirst',
        ?string $tournament = null,
        ?string $note = null,
    ): array {
        $award = [
            'reason' => $reason,
            'tournament' => $tournament,
            'note' => $note,
            'awardedOn' => '2026-08-30',
            'points' => $points,
            'ratingAfter' => $ratingAfter,
        ];

        return $sequence === null ? $award : $award + ['sequence' => $sequence];
    }

    /**
     * Every cell of one column, in the order it appears on the page.
     *
     * @return list<string>
     */
    private function column(string $html, string $class): array
    {
        preg_match_all('/<span class="' . preg_quote($class, '/') . '">([^<]*)<\/span>/', $html, $found);

        return $found[1];
    }
}
