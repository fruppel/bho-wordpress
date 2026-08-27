#!/bin/sh
# Brings the demo site to the state worth looking at: installed, the plugin on, a page carrying the
# shortcode, and the ladder's address filled in. Idempotent — every step checks first.
set -e

SITE=http://localhost:8087

if ! wp core is-installed 2>/dev/null; then
  echo "→ installing WordPress"
  wp core install \
    --url="$SITE" \
    --title="BLACKHYDRA (Testumgebung)" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.com \
    --skip-email
fi

# German, because the site this imitates is. The plugin picks its wording from exactly this.
wp language core install de_DE --activate >/dev/null 2>&1 || true

# Plain permalinks, like blackhydra.org. Deliberate: it is the setting the plugin has to cope with.
wp option update permalink_structure '' >/dev/null

echo "→ theme"
wp theme install broadcast-lite --activate >/dev/null 2>&1 \
  || echo "  broadcast-lite not available, keeping the default theme"

echo "→ plugin"
wp plugin activate bho-ladder >/dev/null
wp option update bho_ladder_settings --format=json "{\"api\":\"$BHO_API\",\"ttl\":5}" >/dev/null

PAGE=$(wp post list --post_type=page --name=ladder --field=ID --post_status=publish 2>/dev/null | head -1)
if [ -z "$PAGE" ]; then
  echo "→ page"
  PAGE=$(wp post create --post_type=page --post_status=publish \
    --post_title='Ladder' --post_name='ladder' \
    --post_content='[bho_ladder]' --porcelain)
fi

wp menu create "Hauptmenü" >/dev/null 2>&1 || true
wp menu item add-post "Hauptmenü" "$PAGE" >/dev/null 2>&1 || true
wp menu location assign "Hauptmenü" primary >/dev/null 2>&1 || true

echo
echo "Ladder:  $SITE/?page_id=$PAGE"
echo "Admin:   $SITE/wp-admin/  (admin / admin)"
echo "API:     $BHO_API"
