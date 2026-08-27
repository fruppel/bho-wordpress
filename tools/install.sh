#!/bin/sh
# Brings the demo site to the state worth looking at: installed, dressed like blackhydra.org, the
# plugin on, a page carrying the shortcode, and that page in the menu where the real one sits.
#
# Idempotent — every step either checks first or is a plain overwrite.
set -e

SITE=http://localhost:8087

if ! wp core is-installed 2>/dev/null; then
  echo "→ installing WordPress"
  wp core install \
    --url="$SITE" \
    --title="BLACKHYDRA" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.com \
    --skip-email
fi

wp language core install de_DE --activate >/dev/null 2>&1 || true

wp option update blogname 'BLACKHYDRA' >/dev/null
wp option update blogdescription 'Tabletop & Games' >/dev/null

# Plain permalinks, like blackhydra.org. Deliberate: it is the setting the plugin has to cope with.
wp option update permalink_structure '' >/dev/null

echo "→ theme"
wp theme install broadcast-lite --activate >/dev/null 2>&1 \
  || echo "  broadcast-lite not available, keeping the default theme"

# The colours and fonts blackhydra.org runs, read out of the :root block that theme prints. Not a
# guess at their taste — the point of this site is that the plugin is judged against the real thing.
wp theme mod set bc_colour_picker_primary   '#0e0e10' >/dev/null 2>&1 || true
wp theme mod set bc_colour_picker_secondary '#161819' >/dev/null 2>&1 || true
wp theme mod set bc_colour_picker_tertiary  '#000a04' >/dev/null 2>&1 || true
wp theme mod set bc_colour_picker_text      '#ffffff' >/dev/null 2>&1 || true
wp theme mod set bc_colour_picker_title     '#95bef1' >/dev/null 2>&1 || true
wp theme mod set bc_colour_picker_button    '#202020' >/dev/null 2>&1 || true
wp theme mod set bc_nav_bg_color            '#313338' >/dev/null 2>&1 || true
wp theme mod set bc_nav_link_color          '#ffffff' >/dev/null 2>&1 || true
wp theme mod set bc_nav_link_hover_color    '#f92720' >/dev/null 2>&1 || true
wp theme mod set bc_primary_font            'Arial, sans-serif' >/dev/null 2>&1 || true
wp theme mod set bc_secondary_font          'Roboto' >/dev/null 2>&1 || true

echo "→ plugin"
wp plugin activate bho-ladder >/dev/null
# Set after the pages exist, because the games page is referenced by id.

PAGE=$(wp post list --post_type=page --name=ladder --field=ID --post_status=publish 2>/dev/null | head -1)
if [ -z "$PAGE" ]; then
  echo "→ pages"
  PAGE=$(wp post create --post_type=page --post_status=publish \
    --post_title='BLACKHYDRA OPEN LADDER / ELO' --post_name='ladder' --porcelain)
fi

GAMES=$(wp post list --post_type=page --name=alle-spiele --field=ID --post_status=publish 2>/dev/null | head -1)
if [ -z "$GAMES" ]; then
  GAMES=$(wp post create --post_type=page --post_status=publish \
    --post_title='Alle Spiele' --post_name='alle-spiele' --porcelain)
fi

# Overwritten every run: the shortcodes are what this script is demonstrating, and a page left with
# an older combination is a page that shows the wrong thing without saying so.
wp post update "$PAGE" --post_content='[bho_ladder]

[bho_recent_games show="3" more="10"]' >/dev/null
wp post update "$GAMES" --post_content='[bho_all_games per="25"]' >/dev/null

wp option update bho_ladder_settings --format=json \
  "{\"api\":\"$BHO_API\",\"ttl\":5,\"games_page\":$GAMES}" >/dev/null

# Overwritten every run rather than only at creation: a demo site that keeps a title from an earlier
# version of this script is a demo site nobody trusts.
wp post update "$PAGE" --post_title='BLACKHYDRA OPEN LADDER / ELO' >/dev/null

# The theme puts a 1920×1080 hero above every page unless a page opts out, and the real ladder page
# opts out — it is a table, not a landing page. Same template here, or the demo would be judged
# against a header the live site does not have.
for p in "$PAGE" "$GAMES"; do
  wp post meta update "$p" _wp_page_template 'no-masthead-template.php' >/dev/null
done

echo "→ menu"
# The real navigation, in its real order, so the ladder is reached the way it will be reached there.
# Everything except the ladder is a placeholder — this site has no About page to link to.
if ! wp menu list --fields=name 2>/dev/null | grep -q '^Hauptmenü$'; then
  wp menu create "Hauptmenü" >/dev/null
  for item in "Start" "Partners" "About" "Podcast: Warp Signal" "WH40k, KT & SC TmG" \
              "Kill Team Resources" "Kill Team Mediathek" "BLACKHYDRA OPEN"; do
    wp menu item add-custom "Hauptmenü" "$item" "$SITE/" >/dev/null
  done
  wp menu item add-post "Hauptmenü" "$PAGE" --title="BLACKHYDRA OPEN LADDER / ELO" >/dev/null
  wp menu item add-post "Hauptmenü" "$GAMES" --title="Alle Spiele" >/dev/null
  for item in "Supporter Lodge" "Shop" "Ebay Shop"; do
    wp menu item add-custom "Hauptmenü" "$item" "$SITE/" >/dev/null
  done
fi

# The theme calls it main-navigation. Assigning is cheap and repeatable, so it happens on every run
# rather than only when the menu is created.
wp menu location assign "Hauptmenü" main-navigation >/dev/null 2>&1 || true

echo
echo "Ladder:      $SITE/?page_id=$PAGE"
echo "Alle Spiele: $SITE/?page_id=$GAMES"
echo "Admin:       $SITE/wp-admin/  (admin / admin)"
echo "API:         $BHO_API"
