<?php

namespace App\Jobs;

use App\Models\Game;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeleteGameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Tables to delete in order: leaf tables first, then parents.
     * Each entry is [table, column] where column is the FK to filter on.
     * 'game_player_id' entries use a subquery through game_players.
     */
    private const GAME_PLAYER_CHILDREN = [
        'player_suspensions',
    ];

    private const GAME_CHILDREN = [
        'match_events',
        'game_transfers',
        'transfer_offers',
        'loans',
        'renewal_negotiations',
        'shortlisted_players',
        'financial_transactions',
        'game_players',
        'game_finances',
        'game_investments',
        'game_tactics',
        'game_tactical_presets',
        'game_notifications',
        'game_matches',
        'game_standings',
        'cup_ties',
        'competition_entries',
        'scout_reports',
        'simulated_seasons',
        'season_archives',
        'academy_players',
        'team_reputations',
        'manager_stats',
        'manager_trophies',
    ];

    public function __construct(
        public string $gameId,
    ) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game) {
            return;
        }

        Cache::forget("game_owner:{$this->gameId}");

        // Explicit ordered deletes avoid the deep CASCADE chain that causes
        // PostgreSQL to recursively discover and process 20+ tables in a
        // single implicit transaction, which is very slow on remote databases.
        $gamePlayerIds = DB::table('game_players')
            ->where('game_id', $this->gameId)
            ->pluck('id');

        if ($gamePlayerIds->isNotEmpty()) {
            foreach (self::GAME_PLAYER_CHILDREN as $table) {
                DB::table($table)
                    ->whereIn('game_player_id', $gamePlayerIds)
                    ->delete();
            }
        }

        foreach (self::GAME_CHILDREN as $table) {
            DB::table($table)
                ->where('game_id', $this->gameId)
                ->delete();
        }

        $game->delete();
    }
}
