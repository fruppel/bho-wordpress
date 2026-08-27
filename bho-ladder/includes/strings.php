<?php
/**
 * The wording, in the three languages the ladder application itself speaks.
 *
 * Not gettext, deliberately. The strings here have to stay in step with `frontend/src/i18n/*.ts` in
 * the application — the same table under the same headings — and keeping them side by side in one
 * readable file is what makes that possible to check. A .po/.mo pair would put the German in a
 * compiled binary and add a build step to a plugin that otherwise has none.
 *
 * The site's own language decides, so a German WordPress shows German. `bho_ladder_strings` is a
 * filter, so a site can correct a word without editing the plugin.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

/** @return array<string,string> */
function bho_ladder_strings(): array
{
    $all = [
        'de' => [
            'ladder' => 'Ladder',
            'back' => '← Zur Ladder',
            'player' => 'Spieler',
            'rating' => 'Punkte',
            'rank' => 'Rang',
            'record' => 'S–U–N',
            'events' => 'Turniere',
            'running_one' => 'Laufendes Turnier',
            'running_many' => 'Laufende Turniere',
            'running_note' => 'Diese Ergebnisse bewegen sich noch, solange Spiele eintreffen, und die Turnierpunkte gibt es erst, wenn ein Turnier vorbei ist.',
            'herald' => 'Tabletop Herald ↗',
            'latest' => 'Neueste Spiele',
            'empty' => 'Bisher wurde nichts importiert.',
            'unavailable' => 'Die Ladder ist gerade nicht erreichbar.',
            'stale' => 'Zuletzt bekannter Stand — die Ladder war beim Aktualisieren nicht erreichbar.',
            'starts_at' => 'Alle starten bei 1100.',
            'players_count' => '%d Spieler',
            'no_games' => 'Noch keine Spiele.',
            'no_player' => 'Diesen Spieler gibt es nicht.',
            'from_1100' => 'von 1100',
            'here' => 'hier',
            'games_count' => '%1$s aus %2$d Spielen',
            'win' => 'Sieg',
            'draw' => 'Remis',
            'loss' => 'Niederlage',
            'versus' => 'vs',
            'below' => 'unter %d',
            'upwards' => 'ab %d',
            'first' => 'Erster Platz',
            'second' => 'Zweiter Platz',
            'third' => 'Dritter Platz',
            'note_provisional' => '%1$s: %2$d von etwa %3$d Spielen gezählt — die Platzierungen und die Bonuspunkte daraus sind vorläufig.',
        ],
        'en' => [
            'ladder' => 'Ladder',
            'back' => '← Back to the ladder',
            'player' => 'Player',
            'rating' => 'Rating',
            'rank' => 'Rank',
            'record' => 'W–D–L',
            'events' => 'Events',
            'running_one' => 'Tournament in progress',
            'running_many' => 'Tournaments in progress',
            'running_note' => 'Those results move as games come in, and the tournament bonus is only awarded once an event is over.',
            'herald' => 'Tabletop Herald ↗',
            'latest' => 'Latest games',
            'empty' => 'Nothing has been imported so far.',
            'unavailable' => 'The ladder cannot be reached at the moment.',
            'stale' => 'Last known standings — the ladder could not be reached when this was refreshed.',
            'starts_at' => 'Everyone starts at 1100.',
            'players_count' => '%d players',
            'no_games' => 'No games yet.',
            'no_player' => 'No such player.',
            'from_1100' => 'from 1100',
            'here' => 'here',
            'games_count' => '%1$s across %2$d games',
            'win' => 'Win',
            'draw' => 'Draw',
            'loss' => 'Loss',
            'versus' => 'vs',
            'below' => 'below %d',
            'upwards' => '%d and up',
            'first' => 'First place',
            'second' => 'Second place',
            'third' => 'Third place',
            'note_provisional' => '%1$s: %2$d of about %3$d games counted, so its placings — and the bonus points from them — are provisional.',
        ],
        'es' => [
            'ladder' => 'Ladder',
            'back' => '← Volver a la ladder',
            'player' => 'Jugador',
            'rating' => 'Puntos',
            'rank' => 'Rango',
            'record' => 'V–E–D',
            'events' => 'Torneos',
            'running_one' => 'Torneo en curso',
            'running_many' => 'Torneos en curso',
            'running_note' => 'Esos resultados se mueven a medida que llegan las partidas, y los puntos de torneo solo se otorgan cuando un evento ha terminado.',
            'herald' => 'Tabletop Herald ↗',
            'latest' => 'Últimas partidas',
            'empty' => 'Todavía no se ha importado nada.',
            'unavailable' => 'No se puede contactar con la ladder en este momento.',
            'stale' => 'Última clasificación conocida — no se pudo contactar con la ladder al actualizar.',
            'starts_at' => 'Todos empiezan en 1100.',
            'players_count' => '%d jugadores',
            'no_games' => 'Aún no hay partidas.',
            'no_player' => 'Ese jugador no existe.',
            'from_1100' => 'desde 1100',
            'here' => 'aquí',
            'games_count' => '%1$s en %2$d partidas',
            'win' => 'Victoria',
            'draw' => 'Empate',
            'loss' => 'Derrota',
            'versus' => 'vs',
            'below' => 'por debajo de %d',
            'upwards' => '%d o más',
            'first' => 'Primer puesto',
            'second' => 'Segundo puesto',
            'third' => 'Tercer puesto',
            'note_provisional' => '%1$s: se han contado %2$d de unas %3$d partidas, así que sus puestos —y los puntos de bonificación que salen de ellos— son provisionales.',
        ],
    ];

    $locale = get_locale();
    $short = strtolower(substr($locale, 0, 2));

    return apply_filters('bho_ladder_strings', $all[$short] ?? $all['en'], $locale);
}
