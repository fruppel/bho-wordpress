<?php

namespace BHO\Tests;

use BHO_Api;
use BHO_Test_Site;

/**
 * The half of the plugin that talks to the ladder.
 *
 * Everything here is about what happens when the answer is not the happy one — an unreachable
 * application, a 500, a body that is not JSON — because the page still has to render, and about the
 * cache key, which is what lets two ladders live on one site.
 */
final class ApiTest extends PluginTestCase
{
    public function testTheSeasonTravelsInTheQuery(): void
    {
        $this->answer(['entries' => []]);

        (new BHO_Api('https://ladder.example'))->ladder(12);

        self::assertSame('https://ladder.example/api/v1/ladder?season=12', $this->asked()[0]);
    }

    public function testNoSeasonAsksForTheDefaultOne(): void
    {
        $this->answer(['entries' => []]);

        (new BHO_Api('https://ladder.example'))->ladder();

        self::assertSame('https://ladder.example/api/v1/ladder', $this->asked()[0]);
    }

    public function testTheTableAndTheGamesUnderItAreOneAnswer(): void
    {
        // Two requests would be two cached copies, and a result could appear in the list minutes
        // before its points appear in the table above it.
        $this->answer(['entries' => [], 'games' => []]);

        (new BHO_Api('https://ladder.example'))->ladder(12, 8);

        self::assertCount(1, $this->asked());
        self::assertStringContainsString('games=8', $this->asked()[0]);
        self::assertStringContainsString('season=12', $this->asked()[0]);
    }

    public function testTwoSeasonsOnOnePageAreTwoCachedAnswers(): void
    {
        // The claim the whole `season` attribute rests on: the key is the path and the path carries
        // the season, so a 40k block and a Kill Team block do not overwrite each other all day.
        $api = new BHO_Api('https://ladder.example');
        $this->answer(['season' => ['id' => 12]]);
        $this->answer(['season' => ['id' => 34]]);

        $first = $api->ladder(12);
        $second = $api->ladder(34);

        self::assertSame(12, $first['season']['id']);
        self::assertSame(34, $second['season']['id']);
        self::assertCount(2, $this->asked());
    }

    public function testTheSecondReadOfTheSameThingComesOutOfTheCache(): void
    {
        $api = new BHO_Api('https://ladder.example');
        $this->answer(['entries' => ['cached']]);

        $api->ladder(12);
        $again = $api->ladder(12);

        self::assertSame(['cached'], $again['entries']);
        self::assertCount(1, $this->asked(), 'the ladder is recomputed per request over there');
    }

    public function testTwoSitesPointedAtDifferentLaddersDoNotShareACacheEntry(): void
    {
        // The key is a hash of the address *and* the path. Without the address, a developer pointing
        // a staging site at production would serve production's table from the staging cache.
        $this->answer(['entries' => ['live']]);
        $this->answer(['entries' => ['staging']]);

        $live = (new BHO_Api('https://ladder.example'))->ladder();
        $staging = (new BHO_Api('https://staging.example'))->ladder();

        self::assertSame(['live'], $live['entries']);
        self::assertSame(['staging'], $staging['entries']);
    }

    public function testAnUnreachableLadderServesThisMorningsTable(): void
    {
        // A day-old table with a note under it beats an error message where the ladder should be:
        // the numbers were true this morning, and the page says so.
        $api = new BHO_Api('https://ladder.example');
        $this->answer(['entries' => ['from this morning']]);
        $api->ladder();

        // The fresh copy expires; the stale one is kept a day longer.
        BHO_Test_Site::$transients = array_filter(
            BHO_Test_Site::$transients,
            static fn(string $key): bool => str_ends_with($key, '_stale'),
            ARRAY_FILTER_USE_KEY,
        );
        $this->unreachable();

        $served = $api->ladder();

        self::assertSame(['from this morning'], $served['entries']);
        self::assertTrue($api->servedStale(), 'and the page has to be able to say it is stale');
    }

    public function testWithNothingKeptTheFailureIsPassedOn(): void
    {
        $this->unreachable('Connection timed out');

        $answer = (new BHO_Api('https://ladder.example'))->ladder();

        self::assertTrue(is_wp_error($answer));
        self::assertSame('Connection timed out', $answer->get_error_message());
    }

    public function testAStatusThatIsNotOkSaysWhichOne(): void
    {
        // Named because the person reading it is the club's admin, and "the ladder answered 502" is
        // the difference between a deploy in progress and a wrong address in the settings.
        $this->refuse(502);

        $answer = (new BHO_Api('https://ladder.example'))->ladder();

        self::assertTrue(is_wp_error($answer));
        self::assertStringContainsString('502', $answer->get_error_message());
    }

    public function testABodyThatIsNotJsonIsARefusalRatherThanAnEmptyTable(): void
    {
        // A proxy's HTML error page arrives with a 200. Rendering it as "no players yet" would be
        // this plugin agreeing that the club has no ladder.
        BHO_Test_Site::$answers[] = ['response' => ['code' => 200], 'body' => '<html>maintenance</html>'];

        self::assertTrue(is_wp_error((new BHO_Api('https://ladder.example'))->ladder()));
    }

    public function testNothingIsCachedFromAFailure(): void
    {
        $this->refuse(500);

        (new BHO_Api('https://ladder.example'))->ladder();

        self::assertSame([], BHO_Test_Site::$transients);
    }

    public function testAnAddressThatWasNeverConfiguredSaysSoWithoutAskingAnybody(): void
    {
        $answer = (new BHO_Api(''))->ladder();

        self::assertTrue(is_wp_error($answer));
        self::assertSame([], $this->asked(), 'and no request is made to an empty address');
    }

    public function testATrailingSlashOnTheAddressDoesNotDoubleUp(): void
    {
        $this->answer([]);

        (new BHO_Api('https://ladder.example/'))->ladder();

        self::assertSame('https://ladder.example/api/v1/ladder', $this->asked()[0]);
    }

    public function testTheRequestSaysWhoIsAsking(): void
    {
        // The other end's logs are where unexplained traffic gets noticed; this is what makes it
        // explained. The version is in it, so a site left behind on an old plugin is visible there.
        $this->answer([]);

        (new BHO_Api('https://ladder.example'))->ladder();

        $agent = BHO_Test_Site::$requests[0]['args']['user-agent'];
        self::assertStringContainsString('BHO-Ladder-WordPress/' . BHO_LADDER_VERSION, $agent);
        self::assertStringContainsString('club.example', $agent);
    }

    public function testAPlayerIsAskedForWithinTheSeasonBeingShown(): void
    {
        // Without the season a player's page is their whole career across every game the club runs,
        // printed under standings that cover one of them.
        $this->answer(['games' => []]);

        (new BHO_Api('https://ladder.example'))->player(31, 12);

        self::assertSame('https://ladder.example/api/v1/players/31?season=12', $this->asked()[0]);
    }
}
