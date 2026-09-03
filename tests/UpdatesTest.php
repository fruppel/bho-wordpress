<?php

namespace BHO\Tests;

use BHO_Test_Site;
use BHO_Updates;

/**
 * How a site finds out there is a newer plugin.
 *
 * The club's site updates itself from a GitHub release, so this decides whether an update is offered
 * at all. Two failures matter and both are quiet: offering the *generated* source archive installs a
 * folder named after the repository and lands beside this plugin instead of over it, and a GitHub
 * that cannot be read must not be asked again on every update check — its limit is sixty requests an
 * hour for anonymous callers, and a shared address can spend those on somebody else.
 */
final class UpdatesTest extends PluginTestCase
{
    public function testANewerReleaseIsOfferedWithTheAttachedZip(): void
    {
        $this->release('0.9.0', [
            ['name' => 'bho-ladder-0.9.0.zip', 'browser_download_url' => 'https://github.test/bho-ladder-0.9.0.zip'],
        ]);

        $offer = BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));

        self::assertSame('0.9.0', $offer['version']);
        self::assertSame('https://github.test/bho-ladder-0.9.0.zip', $offer['package']);
        self::assertSame('bho-ladder', $offer['slug']);
    }

    public function testTheAttachedZipIsTakenRatherThanGitHubsSourceArchive(): void
    {
        // GitHub always attaches `zipball_url` and `tarball_url`; those hold the repository, whose
        // top folder is `bho-wordpress-v0.9.0`. Installing that puts a second plugin on the site.
        $this->release('0.9.0', [
            ['name' => 'Source code (zip)', 'browser_download_url' => 'https://github.test/source.tar.gz'],
            ['name' => 'bho-ladder-0.9.0.zip', 'browser_download_url' => 'https://github.test/bho-ladder-0.9.0.zip'],
        ]);

        $offer = BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));

        self::assertSame('https://github.test/bho-ladder-0.9.0.zip', $offer['package']);
    }

    public function testTheVersionIsReportedEvenWhenItIsTheOneInstalled(): void
    {
        // Core compares this against the plugin header itself. Answering only when newer would leave
        // core with nothing to compare and the plugin permanently "unknown".
        $this->release(BHO_LADDER_VERSION, [
            ['name' => 'bho-ladder.zip', 'browser_download_url' => 'https://github.test/bho-ladder.zip'],
        ]);

        $offer = BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));

        self::assertSame(BHO_LADDER_VERSION, $offer['version']);
    }

    public function testAQuestionAboutAnotherPluginIsPassedStraightThrough(): void
    {
        // Every plugin on the site whose Update URI points at github.com reaches this filter.
        $answer = BHO_Updates::answer(false, [], 'some-other-plugin/some-other-plugin.php');

        self::assertFalse($answer);
        self::assertSame([], $this->asked(), 'and GitHub is not asked about somebody else’s plugin');
    }

    public function testWhatAnotherFilterAlreadyDecidedIsNotOverwritten(): void
    {
        $existing = ['version' => '1.2.3'];

        self::assertSame($existing, BHO_Updates::answer($existing, [], 'other/other.php'));
    }

    public function testTheTagsLeadingVIsNotPartOfTheVersion(): void
    {
        // Releases are tagged `v0.9.0`; the plugin header says `0.9.0`. Comparing the two as they
        // come would offer an update forever, because "v0.9.0" is never equal to "0.9.0".
        $this->release('v0.9.0', [
            ['name' => 'bho-ladder-0.9.0.zip', 'browser_download_url' => 'https://github.test/z.zip'],
        ]);

        self::assertSame('0.9.0', BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE))['version']);
    }

    public function testARefusalFromGitHubIsRememberedSoItIsNotAskedAgainAtOnce(): void
    {
        // Sixty requests an hour for anonymous callers, and a shared address can spend them on
        // somebody else. A repository that is private, renamed or without a release must not be
        // asked on every update check.
        BHO_Test_Site::$answers[] = ['response' => ['code' => 404], 'body' => '{}'];

        self::assertFalse(BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE)));

        BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));

        self::assertCount(1, $this->asked(), 'GitHub was asked twice for the same refusal');
    }

    public function testAReleaseWithNoZipAttachedIsNoOfferAtAll(): void
    {
        // Better no update than one WordPress cannot install: a release whose workflow failed has a
        // tag and no asset, and offering it would download nothing.
        $this->release('0.9.0', []);

        self::assertFalse(BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE)));
    }

    public function testAnAnswerThatIsNotAReleaseIsNoOffer(): void
    {
        BHO_Test_Site::$answers[] = ['response' => ['code' => 200], 'body' => 'not json'];

        self::assertFalse(BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE)));
    }

    public function testTheAnswerIsRememberedRatherThanFetchedPerCheck(): void
    {
        $this->release('0.9.0', [
            ['name' => 'z.zip', 'browser_download_url' => 'https://github.test/z.zip'],
        ]);

        BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));
        BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));

        self::assertCount(1, $this->asked());
    }

    public function testTheRequestSaysWhichPluginIsAsking(): void
    {
        // GitHub refuses an anonymous request with no User-Agent outright.
        $this->release('0.9.0', [['name' => 'z.zip', 'browser_download_url' => 'https://github.test/z.zip']]);

        BHO_Updates::answer(false, [], plugin_basename(BHO_LADDER_FILE));

        self::assertSame(
            'bho-ladder/' . BHO_LADDER_VERSION,
            BHO_Test_Site::$requests[0]['args']['headers']['User-Agent'],
        );
    }

    /** @param list<array<string,string>> $assets */
    private function release(string $tag, array $assets): void
    {
        BHO_Test_Site::$answers[] = [
            'response' => ['code' => 200],
            'body' => json_encode(['tag_name' => $tag, 'assets' => $assets]),
        ];
    }
}
