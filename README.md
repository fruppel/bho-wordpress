# BHO Ladder — WordPress plugin

Draws the [Black Hydra Open](https://github.com/fruppel/bho) ladder on a WordPress page, with a detail
page per player, and brings a WordPress to look at it in.

The ladder itself is a separate application. This plugin does not reimplement it: it asks that
application's public API for the same numbers and renders them into whatever theme the site wears.

```
[bho_ladder]                        the table, with the three latest games above it
[bho_ladder limit="10" recent="0"]  a top-ten teaser for a front page
```

Clicking a player stays on the same page and appends `?bho_player=…`. No second page to create, and
no permalink setting to change — which matters, because blackhydra.org runs on plain permalinks.

## Why server-side

The plugin reads the API in PHP, not with JavaScript in the visitor's browser. Three reasons, each of
which would be enough on its own: there is no CORS to arrange, the table is in the HTML so search
engines and script blockers see it, and one answer is cached for everybody instead of fetched once per
visitor. The ladder is recomputed from every game on each request over there, so that last one is not
a detail.

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
  includes/strings.php        German, English and Spanish
  assets/ladder.css           only what a theme cannot know: rank tints, podium, flag frame
  assets/flags/               271 country flags (flag-icons, MIT)
```

The flags ship with the plugin rather than being fetched from the ladder's server: a country code is
two letters, so the file name is derivable, and every page view would otherwise put a handful of
requests on somebody's private machine.

`strings.php` is not gettext, deliberately — the wording has to stay in step with the application's own
`frontend/src/i18n/*.ts`, and one readable file makes that possible to check. The site's language
decides which of the three is used, and `bho_ladder_strings` is a filter for correcting a word without
editing the plugin.

## Against which ladder

Any, including production:

```bash
BHO_API=https://bho.fruppel.de make install
```
