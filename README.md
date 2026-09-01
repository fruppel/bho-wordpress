# BHO Ladder — WordPress plugin

Draws the [Black Hydra Open](https://github.com/fruppel/bho) ladder on a WordPress page, with a detail
page per player, and brings a WordPress to look at it in.

The ladder itself is a separate application. This plugin does not reimplement it: it asks that
application's public API — `/api/v1/ladder`, `/api/v1/players/{id}`, `/api/v1/seasons` — for the same
numbers and renders them into whatever theme the site wears. The version prefix exists for this
plugin: a breaking change over there means `/api/v2/` and a plugin update, in that order.

```
[bho_ladder]                                the page: standings, latest games, rules
[bho_ladder limit="10" games="3" rules="0"] less of it, for a front page
[bho_ladder season="12"]                    a season other than the current one
[bho_all_games per="25"]                    every game of the season, paginated
```

Without `season` a block shows whichever season the ladder application marks as the **default**,
which is what one club running one game wants. Naming one is how a site carries **several ladders at once**: the
club runs more than one game, each with its own seasons, and only one of them can be current over
there. The id is on the Seasons screen in the ladder's admin area — an id and not a name, because a
name is a thing somebody renames and a page that then shows an empty table gives no clue why.

It runs through the whole block: table, latest games, and the player page behind a click. That last
one is the point — a player's page is their whole career across every game, so a 40k page would
otherwise open onto somebody's Kill Team results.

Two shortcodes, because there are two pages. `[bho_ladder]` is the standings, the last few games and
the rules in one block: the table is what the page is for, the games say what just happened, and the
rules are what a reader checks a row against. Above 960 pixels the games and the rules stand beside
each other under the table; below it the three stack. There were four shortcodes until 2026-08-28, one
of them for the rules alone — three snippets to paste and keep in the right order for a page that only
ever wanted one.

Every side of a game carries the rank class its player held **when that game was scored**, in the same
badge the standings use. It is the reason the number beside it is what it is: the step comes from the
two classes and from nothing else, so a C losing to a D reads −45 and the D reads +60, and the row now
says why. Not the class they hold today — that is in the table above, and printing it on a game from
July would label that game with a fact from this morning. The ladder sends it per side (`rank`); where
it does not — a tournament assigned to no season, or an installation still on an older ladder — the
badge is simply absent rather than guessed at.

The last few games come with the standings — `/api/v1/ladder?games=8` — rather than from a second
request. They are still their own endpoint for the page that lists them all, but the block above the
fold shows the table and the games together, and two requests would be two caches: a result could
appear in the list minutes before its points appear in the table above it. What is listed is all the
block holds; the link under it goes to every game there is.

Below about 390 pixels the standings stop fitting and scroll inside a wrapper of their own. The page
does not go with them: a table that takes the page sideways puts the rating and the rank off the right
edge of the screen and everything else on the page with them. Above that width the wrapper is inert —
`overflow-x: auto` draws no scrollbar on a table that fits. What it costs is that a name scrolls out of
view along with everything else, which is the trade a scrolling table makes.

The stylesheet is versioned by its own modification time rather than by the plugin version. With the
plugin version, editing the CSS kept the same `?ver=` and every browser that had been on the page kept
the file it already had — which looked exactly like the change not having been made.

On a player's own page the block renders nothing: that page is already a list of games, and this one
underneath would be the same rows again, most of them about somebody else.

A player's page also lists the **points the club gave out by hand** under their games, with the day,
the reason and the running rating after each one — the part of a rating no game accounts for, and the
one that would otherwise leave the arithmetic on that page not adding up. A bonus given at a
tournament leads with that event and then the placing (*BLACKHYDRA OPEN KILL TEAM - I · 1. Platz*),
because the placing alone says nothing about which weekend. The ladder sends the reason as a code
(`tournamentFirst`, `other`, …) and `includes/strings.php` writes it out in each of the three
languages; a code with no wording there falls back to the note the organiser typed, so the
application can add a reason before this plugin is updated.

The **installed version** is printed in small print at the foot of any page this plugin drew on. Not
on `wp_footer`, which is after the theme's container and therefore invisible on a theme that puts its
background there rather than on `body` — inside the block, where the text colour is the one the
ladder above it is being read in.

`[bho_all_games]` is the same rows again, a page at a time, with previous/next paging. It was a table
of its own until it drifted from the block in spacing, in what it showed and in how a score was drawn:
two ways of printing one thing is one too many. Point the *Seite „Alle Spiele"* setting at the page
holding it and the latest-games block links there; leave it unset and the link stays away.

Clicking a player stays on the same page and appends `?bho_player=…`. No second page to create, and
no permalink setting to change — which matters, because blackhydra.org runs on plain permalinks.

The standings sort by clicking a column head, which appends `?bho_sort=games`, `-games` for the other
direction. In the URL and not in a script: a sorted table is then a link somebody can send, and it
costs nothing, because the standings arrive complete in one answer and the sorting is a function on an
array the page already holds. Rank and the W–D–L record are not sortable — rank is the rating in a
badge, and "best record" is a question the club answers with the Turnier Score, which a column head is
the wrong place to argue. Places stay with their players, so a table sorted by games still says who is
first. The sort travels into a player's page and back out of it, so coming back lands on the table
that was left.

## Installing it on a site that is not this one

`make zip` builds `bho-ladder-<version>.zip` from the committed files, with `bho-ladder/` as the folder
inside it — which is the folder WordPress installs, and the folder an update has to land in to replace
the plugin rather than sit down beside it. GitHub's own "Source code (zip)" cannot be installed: it
holds the repository, so the plugin would end up one level down. First installation is that zip under
*Plugins → Add New → Upload Plugin*.

After that the site updates itself. The plugin names an `Update URI` in its header, which gives it
core's `update_plugins_github.com` filter; `BHO_Updates` answers it with the newest release's tag and
the zip attached to it, cached for six hours against GitHub's sixty-requests-an-hour limit for
anonymous callers. The plugins screen then offers the update like any other, and the button is the
button everybody already knows.

Six hours is the wait for somebody who has just tagged a release, and core's own answer can be twelve
behind that — so the plugin's row carries a **Check for updates** link that drops both caches and asks
now. Both are needed: `wp_update_plugins()` declines to look again within the hour, so without
deleting core's site transient first the filter above is never reached and the link would report what
core decided this morning. On a machine with WP-CLI the same thing is
`wp transient delete bho_ladder_latest_release && wp cron event run wp_update_plugins`.

Releasing is `git tag v0.3.0 && git push --tags`. The workflow refuses a tag that disagrees with the
plugin header, because WordPress compares the header against what the update check reported: a tag
ahead of the header would offer an update that installs, still calls itself the old version, and is
offered again forever.

**Every change under `bho-ladder/` gets a line in `CHANGELOG.md` under *Unreleased*, in the same
commit that makes it.** The site updates itself, so that file is what somebody reads when the plugins
screen offers them an update — and a changelog written afterwards from the log is a changelog that
records what the commits said rather than what a reader needs to know, which is what breaks and what
to do about it. Releasing turns the *Unreleased* heading into the version and opens an empty one.

## Colours it does not own

`assets/ladder.css` sets no font, no text colour and no background — those come from the site. What it
does need a colour for is mixed with `currentColor`, the theme's own text colour: a flat `#17a673` is
muddy on one of dark and light, while the same green mixed into the text colour reads on both. That is
the one trick in the file, and it is why the ladder looks deliberate on blackhydra.org's near-black
theme and would on a white one.

Two consequences worth knowing before editing it: the podium is a bar down the left edge rather than a
tinted row, because a 9% amber wash comes out olive on black and beige on white; and every numeric
column needs `th.bho-num`/`td.bho-num` rather than `.bho-num`, because `.bho-table td` wins on
specificity — which it did, silently left-aligning every number in the table.

## Why server-side

The plugin reads the API in PHP, not with JavaScript in the visitor's browser. Three reasons, each of
which would be enough on its own: there is no CORS to arrange, the table is in the HTML so search
engines and script blockers see it, and one answer is cached for everybody instead of fetched once per
visitor. The ladder is recomputed from every game on each request over there, so that last one is not
a detail.

Both screens live under `Settings` and carry the same two tabs — *Einstellungen* and *Saisons* — so
each one leads to the other. Without them they were reachable only by knowing the URL, which is not
navigation.

`Settings → BHO Saisons` shows which tournaments count towards which season, and names the ones
counting towards nothing — a state somebody has to be able to notice rather than discover when a table
looks short. It is **read-only**: assigning happens in the ladder's own admin area, and the screen
links there. Two write paths to one dataset means the rules that hold it together — exactly one
current season, one season per tournament — either live in two places or drift apart. It also spares
this WordPress a credential with write access, which on shared hosting is the last place to keep one.

`Settings → BHO Ladder` holds the address of the ladder and how long an answer is kept (five minutes by
default; the table only changes when somebody presses Import). If the ladder cannot be reached, the
last good answer is served for up to a day with a line saying so — a day-old table beats an error
message where the ladder should be.

## Looking at it locally

Needs the ladder itself running on port 8085 (`make up` in the [bho](https://github.com/fruppel/bho)
repository), then:

```bash
make up        # WordPress on http://localhost:8087, installed and filled in
make down      # stop it, keep the database
make reset     # throw the site away and start over
```

`make up` prints the two addresses you want. The admin is `admin` / `admin`.

The demo site is set up to **resemble blackhydra.org rather than a clean install**: German, plain
permalinks (`?page_id=4`), the same `broadcast-lite` theme with their colours and fonts, their
navigation, and the page under the menu entry the real ladder sits under. That is the point of having
it — a plugin that only works on pretty permalinks would look fine on a default install and break
there, and a plugin judged on a white page tells you nothing about how it looks on a black one.

The colours are not a guess: they are the `:root` block blackhydra.org serves (`#0e0e10`, `#161819`,
title `#95bef1`, nav `#313338` with a red hover). The ladder page also carries the theme's
`no-masthead-template.php`, which is what the real ladder page uses — the theme otherwise puts a
1920×1080 hero above every page, and a table is not a landing page.

The plugin directory is mounted into the container, so editing a file and reloading the page is the
whole loop.

## Installing it for real

Copy `bho-ladder/` into `wp-content/plugins/`, activate it, set the address under Settings, and put
`[bho_ladder]` on a page. Nothing is compiled and there is no build step.

## What is in here

```
bho-ladder/
  bho-ladder.php              plugin header, shortcode, page title and canonical link
  includes/class-bho-api.php  the two endpoints, cached, with a stale fallback
  includes/class-bho-render.php  all the HTML, everything escaped on the way out
  includes/class-bho-settings.php  the settings page
  includes/class-bho-overview.php  the read-only seasons screen in wp-admin
  includes/nav.php            the tab bar the two admin screens share
  includes/strings.php        German, English and Spanish
  assets/ladder.css           only what a theme cannot know: rank tints, podium, flag frame
  assets/flags/               271 country flags (flag-icons, MIT)
```

The flags ship with the plugin rather than being fetched from the ladder's server: a country code is
two letters, so the file name is derivable, and every page view would otherwise put a handful of
requests on somebody's private machine.

`strings.php` is not gettext, deliberately: three languages side by side in one readable file can be
checked against each other, where a compiled .mo pair would add a build step to a plugin that has
none. English is the default rather than the WordPress locale, because blackhydra.org is an English
page and a German install behind it would otherwise turn the table German; the setting can follow the
site instead. `bho_ladder_strings` is a filter for correcting a word without editing the plugin.

## Against which ladder

Any, including production:

```bash
BHO_API=https://bho.fruppel.de make install
```
