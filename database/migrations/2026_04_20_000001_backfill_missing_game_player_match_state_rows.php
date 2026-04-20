<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill game_player_match_state rows for every existing game_player
     * that doesn't already have one.
     *
     * Previously the satellite table was sparse — only "active scope" players
     * (teams in the user's domestic competitions) carried a row, and foreign
     * transfer-pool players relied on the defaults baked into
     * {@see \App\Models\GamePlayer}'s accessor delegates. The lazy
     * ensureExistForGamePlayers path filled the gap at matchday time whenever
     * an out-of-scope team entered play (e.g. European qualification).
     *
     * Going forward every game_player is guaranteed a satellite row at
     * creation time. This migration brings existing games up to the new
     * invariant so the matchday-time ensure path can be retired.
     *
     * Idempotent: the LEFT JOIN filters out players that already have a row,
     * so re-running on an up-to-date database is a no-op.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO game_player_match_state (
                game_player_id, game_id, fitness, morale, injury_until, injury_type,
                appearances, season_appearances, goals, own_goals, assists,
                yellow_cards, red_cards, goals_conceded, clean_sheets
            )
            SELECT gp.id, gp.game_id, 80, 80, NULL, NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0
            FROM game_players gp
            LEFT JOIN game_player_match_state gpms
                ON gpms.game_player_id = gp.id
            WHERE gpms.game_player_id IS NULL
            ORDER BY gp.id
            ON CONFLICT (game_player_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // No-op. We can't selectively drop only the backfilled rows without
        // tracking which ones were added here, and rolling back the whole
        // table would blow away every game's match state.
    }
};
