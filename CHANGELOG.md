# Changelog

What changed in the plugin, newest first. The site it is installed on updates itself from a GitHub
release, so this file is what somebody reads when the plugins screen offers them one.

The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the numbers are
[semantic](https://semver.org/spec/v2.0.0.html). A heading here is the number in the plugin header:
the release workflow refuses a tag that disagrees with it, so the two cannot drift.

Only the plugin is versioned. The ladder it reads is a separate application with its own releases —
where a change here needs a change there, the entry says so.

## [Unreleased]

### Added

- **Free-text, coloured announcements**, shown above the standings and grouped into one box per
  colour — yellow, red or blue. Written and posted from the ladder application's own admin area, so a
  one-off message no longer needs a plugin update to say something new; the sentence comes from the
  API exactly as an organiser wrote it. Needs a ladder that answers `GET /api/v1/notices`.

### Removed

- The `provisionalPlacings` note this plugin knew how to render. The ladder application dropped the
  tournament bonus and the finding that came with it, so the code could never arrive here again; the
  free-text announcements above are its replacement. `excludedGames`, the other finding the standings
  used to send, was never rendered here to begin with.

## [0.6.0] — 2026-09-02

### Added

- **A `season` attribute on both shortcodes**, which is what lets one site carry more than one
  ladder: `[bho_ladder season="12"]` and `[bho_all_games season="12"]`. The club runs several games
  side by side, each with its own seasons, and the ladder application can only call one of them
  current — so a page that wants another one says which. Left out, everything behaves as before.

  It runs through the whole block: the table, the latest games under it and the player page a click
  opens all show the same season. That last one is the reason it exists — a player's page is their
  whole career across every game, so the page behind the 40k standings would otherwise list their
  Kill Team games under them. Needs a ladder that accepts `?season=` on `/api/v1/players/{id}`.

  An id rather than a name, because a name is a thing somebody renames, and a page that then shows an
  empty table gives no clue why. The ids are on the Seasons screen in the ladder's admin area. An id
  that does not exist says so rather than quietly falling back to the current season.

  Two blocks on one page get two cached answers, not one that keeps being overwritten: the cache key
  is the request path, and the path carries the season.

- The **Saisons** screen now prints each season's `season="…"` beside its name, so a page can be set
  up without leaving WordPress to look the number up, and a **Spiel** column beside every tournament
  — a list covering several games is otherwise one nobody can read.

### Changed

- The season the ladder falls back to is called the **default** rather than the *current* one, in
  step with the ladder application. With several games run side by side there are several seasons
  being played at once, so "current" was a claim of uniqueness that no longer held; what is unique is
  which season a block gets that names none.

  Against a ladder that still sends the old `isCurrent`, the Saisons screen reads that instead, so a
  site updated before the ladder still marks the right row.

- **Bonus points on a player's page**, in a list under their games: the day, why, what it was worth
  and where the rating stood afterwards. The club can now give points out by hand for what the ladder
  cannot see on its own — a side event, a tournament whose results never reached Herald — and this is
  the half of that which anybody can read. Their own list rather than rows among the games: a bonus
  has no opponent, no score and no result, so half a game row would be empty for every one of them.

  A tournament bonus leads with **the event it was given at**, then the placing: *BLACKHYDRA OPEN
  KILL TEAM - I · 1. Platz*. The event is a field on the award rather than something somebody typed
  into a note, so the same tournament cannot end up spelt two ways down the list — and "1. Platz" on
  its own says nothing about which weekend.

  The reason arrives as a code and is written out here in all three languages, with whatever the
  organiser typed beside it. The placings are worded short, because the event is in front of them and
  the block is already headed *Bonuspunkte*; saying *Turnierbonus* between the two would be the third
  time. A reason this plugin has no wording for falls back to the organiser's words rather than
  printing a key, so the ladder can add one before the plugin is updated.

  The rating in the head of the page now stands where the last bonus left it rather than where the
  last game did — they are added after every game of their season, whatever day they carry.

  Needs a ladder that sends `awards` on `/api/v1/players/{id}`; against one that does not, the list is
  simply absent.

- **The installed version, in small print at the foot of the page.** The site updates itself from a
  GitHub release, so which one is actually on there otherwise needs the plugins screen and an account
  allowed to open it — and "which version are you on" is the first question about a table that looks
  wrong.

  Under the block this plugin drew and not on `wp_footer`, which is where it was first put and where
  it is invisible: that prints after the theme's container, and a theme carrying its dark background
  on the container rather than on `body` leaves white text on a white strip. Printed once, however
  many shortcodes a page holds, and not at all on the pages that hold none.

## [0.5.0] — 2026-08-29

### Added

- **Check for updates**, a link in the plugin's row on the plugins screen, beside *Settings*. A
  release is otherwise noticed within six hours at the latest — this plugin's cache — and up to twelve
  behind that, core's own. The link throws both away and asks GitHub now, then says what it found:
  the version waiting, or that this one is the newest, or that GitHub refused (its limit is sixty
  requests an hour for anonymous callers, which a shared address can spend on somebody else).

  Shown only to users who may update plugins, and the handler checks that again rather than trusting
  the link's absence.

## [0.4.0] — 2026-08-29

### Added

- Every side of a game carries the rank class its player held **when that game was scored**, in the
  badge the standings already use — in the latest-games block and on the all-games page. It is the
  reason the number beside it is what it is: the step comes from the two players' classes and from
  nothing else, so a C losing to a D reads −45 and the D reads +60. Not the class they hold today,
  which is in the table above. Needs a ladder that sends `rank` per side; against one that does not,
  the badge is absent rather than guessed at.

### Fixed

- The class badge sat four pixels below the flag beside it. A row of games aligns on the baseline, and
  the two disagree about what theirs is: an image puts its bottom edge on the baseline, a badge offers
  the baseline of the letter inside it. Both are centred on the row now.
- The rank column in the standings hung left while every column around it was right-aligned, which
  left the badge clinging to the rating with a wide gap after it and its heading out of step with the
  rest of the head row.
- A W–D–L record no longer breaks across two lines. `3–0–0` reads as two numbers once the browser
  takes one of its dashes as a break opportunity, which it did below 360 pixels — and at 390 as soon
  as one player reaches Warmaster, whose badge is nine characters wide in a column budgeted for one.
- The standings scroll inside a wrapper of their own below about 390 pixels instead of compressing
  their columns to fit. A name scrolls out of view with the rest of the row, which is the trade a
  scrolling table makes.
- The air under the table came back: the rule that puts it there names the table's sibling, and
  wrapping the table in a scroller left it matching nothing, so the latest games sat against the last
  row again.

## [0.3.0] — 2026-08-28

### Changed

- **Breaking:** `[bho_recent_games]` and `[bho_rules]` are gone. `[bho_ladder]` draws the standings,
  the last few games and the rules as one block — the table is what the page is for, the games say
  what just happened, and the rules are what a reader checks a row against. Three snippets to paste
  and keep in the right order was two too many for a page that only ever wanted one.

  **A page using the removed shortcodes shows nothing where they were.** Replace all three with a
  single `[bho_ladder]`; `games` and `rules` are attributes now (`[bho_ladder games="3" rules="0"]`).

- The last few games arrive with the standings from one request rather than from a second one. Fetched
  separately they were cached separately, and a result could appear in the list minutes before its
  points appeared in the table above it.

- The block renders nothing on a player's own page: that page is already a list of games, and this one
  underneath would be the same rows again, most of them about somebody else.

## [0.2.0] — 2026-08-27

### Added

- The site updates itself. The plugin names an `Update URI`, and the newest GitHub release is offered
  on the plugins screen like any other update. First installation is still the zip by hand.
- `make zip` builds that installable archive from the committed files, with `bho-ladder/` at its root —
  which GitHub's own "Source code (zip)" does not have, and which is why that one cannot be installed.
- `[bho_all_games]`, every game of the season a page at a time, with previous/next paging.
- The standings sort by clicking a column head, in the URL rather than in a script, so a sorted table
  is a link somebody can send. The sort survives a trip into a player's page and back out of it.
- A player's own page, reached by clicking a name — same page, `?bho_player=…` appended, so there is
  no second page to create and no permalink setting to change.
- Flags beside the names, shipped with the plugin rather than fetched from anywhere.
- The rules and the rank legend under the table, from the ladder's own numbers rather than typed out
  again, so neither can drift from what the rating is computed with.
- `Settings → BHO Saisons`: which tournaments count towards which season, and which count towards
  nothing. Read-only — assigning happens in the ladder's own admin area.
- English by default, with German and Spanish available, and a `bho_ladder_strings` filter for a site
  that wants a word changed without editing the plugin.

### Changed

- Reads the versioned public API (`/api/v1/…`). **Needs a ladder that serves it.**
- An excluded result strikes the player who was taken out, not the whole row: a game the club took one
  player out of still counts for the other.
- Each side of a game carries its own half of the score, so on a phone, where the two players go on
  separate lines, each keeps the number that is his.
- The stylesheet is versioned by its own modification time rather than by the plugin version — with
  the plugin version, editing the CSS kept the same `?ver=` and every browser that had been on the
  page kept the file it already had.

## [0.1.0] — 2026-08-27

The first one, and the only one never tagged: it predates the release workflow, so it is linked to the
commit rather than to a release.

### Added

- The plugin: `[bho_ladder]` draws the standings on a WordPress page, read server-side from the
  ladder's API and cached, so there is no CORS to arrange, the table is in the HTML, and one answer
  serves everybody.
- `Settings → BHO Ladder`: the address of the ladder and how long an answer is kept. When the ladder
  cannot be reached the last good answer is served for up to a day with a line saying so.
- A stylesheet that sets no font, no text colour and no background — those come from the site — and
  mixes what it does need with the theme's own text colour, so the table reads on a dark theme and on
  a light one.
- A Dockerised demo site to look at it in (`make up`).

[Unreleased]: https://github.com/fruppel/bho-wordpress/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/fruppel/bho-wordpress/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/fruppel/bho-wordpress/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fruppel/bho-wordpress/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fruppel/bho-wordpress/compare/534dc9f...v0.2.0
[0.1.0]: https://github.com/fruppel/bho-wordpress/commit/534dc9f
