<?php

namespace BHO\Tests;

use BHO_Test_Site;

/**
 * The three languages the club's page is read in.
 *
 * Two things can go wrong here and both are silent. A key present in one language and missing from
 * another prints nothing where a word belongs — on the public page, in the language nobody testing
 * was reading. And a `%d` dropped from a translated sentence makes `sprintf` return a string missing
 * its number rather than complaining.
 */
final class StringsTest extends PluginTestCase
{
    private const LANGUAGES = ['en', 'de', 'es'];

    public function testEveryLanguageHasEveryWordTheOthersHave(): void
    {
        $keys = [];
        foreach (self::LANGUAGES as $language) {
            $keys[$language] = array_keys($this->strings($language));
            sort($keys[$language]);
        }

        foreach (self::LANGUAGES as $language) {
            self::assertSame(
                $keys['en'],
                $keys[$language],
                sprintf('%s is missing %s, or has words English does not', $language, implode(', ', array_diff($keys['en'], $keys[$language]))),
            );
        }
    }

    public function testNoWordIsLeftEmpty(): void
    {
        foreach (self::LANGUAGES as $language) {
            foreach ($this->strings($language) as $key => $word) {
                self::assertNotSame('', trim($word), "$language:$key is blank");
            }
        }
    }

    /**
     * The sentences that take numbers keep them, in every language.
     *
     * Not a rule about counting `%`: `games_total` takes three and `page_of` takes two, so what has
     * to hold is that a translation takes the same ones as the English it was written from.
     */
    public function testASentenceWithNumbersInItKeepsThemWhenTranslated(): void
    {
        $english = $this->strings('en');

        foreach (self::LANGUAGES as $language) {
            foreach ($this->strings($language) as $key => $word) {
                self::assertSame(
                    $this->placeholders($english[$key]),
                    $this->placeholders($word),
                    "$language:$key does not take what the English one takes",
                );
            }
        }
    }

    public function testTheDefaultIsEnglishRatherThanTheSitesLanguage(): void
    {
        // blackhydra.org is an English page. A German WordPress behind it would otherwise put a
        // German table on it, which is the wrong way round.
        BHO_Test_Site::$locale = 'de_DE';

        self::assertSame('Player', bho_ladder_strings()['player']);
    }

    public function testTheSiteSettingFollowsTheSitesLocale(): void
    {
        BHO_Test_Site::$options['bho_ladder_settings'] = ['language' => 'site'];
        BHO_Test_Site::$locale = 'de_DE';

        self::assertSame('Spieler', bho_ladder_strings()['player']);
    }

    public function testALocaleNobodyHasTranslatedFallsBackToEnglish(): void
    {
        BHO_Test_Site::$options['bho_ladder_settings'] = ['language' => 'site'];
        BHO_Test_Site::$locale = 'fi';

        self::assertSame('Player', bho_ladder_strings()['player']);
    }

    public function testASiteCanCorrectAWordWithoutEditingThePlugin(): void
    {
        // The documented way out for a club that says something differently. Worth a test because the
        // day the filter stops being applied, a site's own wording silently reverts to the plugin's.
        add_filter('bho_ladder_strings', static function (array $words): array {
            $words['player'] = 'Hydra';

            return $words;
        });

        self::assertSame('Hydra', bho_ladder_strings()['player']);
    }

    public function testTheFilterIsToldWhichLanguageItIsCorrecting(): void
    {
        // A site that runs two languages has to be able to correct one of them.
        $seen = null;
        add_filter('bho_ladder_strings', static function (array $words, string $language) use (&$seen): array {
            $seen = $language;

            return $words;
        });

        BHO_Test_Site::$options['bho_ladder_settings'] = ['language' => 'de'];
        bho_ladder_strings();

        self::assertSame('de', $seen);
    }

    /** @return array<string,string> */
    private function strings(string $language): array
    {
        BHO_Test_Site::$options['bho_ladder_settings'] = ['language' => $language];

        return bho_ladder_strings();
    }

    /**
     * The printf placeholders in a sentence, sorted.
     *
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[a-z]/', $text, $found);
        $placeholders = array_map(
            // "%1$s" and "%s" are the same requirement — a translation may reorder what it takes.
            static fn(string $one): string => '%' . substr($one, -1),
            $found[0],
        );
        sort($placeholders);

        return $placeholders;
    }
}
