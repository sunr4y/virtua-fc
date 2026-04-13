<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Modules\Player\Support\GamePlayerScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data backfill that copies match-state columns out of game_players
 * into the new game_player_match_state satellite for every existing game.
 *
 * Run between the create-table migration and the drop-columns migration:
 *
 *   php artisan migrate                                        # creates the satellite table
 *   php artisan app:backfill-game-player-match-state           # this command
 *   php artisan migrate                                        # drops the 13 columns
 *
 * Idempotent: a player whose satellite row already exists is left alone.
 *
 * Memory-safe: groups games by country so team-scope resolution happens once
 * per country (not per game), and batches INSERTs across chunks of games.
 */
class BackfillGamePlayerMatchState extends Command
{
    protected $signature = 'app:backfill-game-player-match-state {--game= : Backfill a single game by id}';

    protected $description = 'Backfill game_player_match_state from existing game_players hot-write columns';

    private const CHUNK_SIZE = 500;

    public function handle(GamePlayerScopeResolver $scopeResolver): int
    {
        if ($gameId = $this->option('game')) {
            return $this->handleSingleGame($gameId, $scopeResolver);
        }

        return $this->handleAll($scopeResolver);
    }

    private function handleSingleGame(string $gameId, GamePlayerScopeResolver $scopeResolver): int
    {
        $game = Game::find($gameId);
        if (! $game) {
            $this->error("Game {$gameId} not found.");

            return self::FAILURE;
        }

        $activeTeamIds = $game->isTournamentMode()
            ? $this->tournamentTeamIds($gameId)
            : $scopeResolver->activeTeamIdsForCountry($game->country);

        $inserted = $this->insertBatch([$gameId], $activeTeamIds);
        $this->info("Done. Inserted {$inserted} match-state rows.");

        return self::SUCCESS;
    }

    private function handleAll(GamePlayerScopeResolver $scopeResolver): int
    {
        $totalGames = Game::count();
        if ($totalGames === 0) {
            $this->info('No games to backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling match state for {$totalGames} game(s).");

        $totalInserted = 0;
        $bar = $this->output->createProgressBar($totalGames);

        // Group by country so we resolve active team IDs once per country
        $countries = Game::distinct()->pluck('country');

        foreach ($countries as $country) {
            $activeTeamIds = $scopeResolver->activeTeamIdsForCountry($country);

            // Career / null-mode games: use country-based team scope
            Game::where('country', $country)
                ->where(function ($q) {
                    $q->where('game_mode', '!=', Game::MODE_TOURNAMENT)
                        ->orWhereNull('game_mode');
                })
                ->select('id')
                ->chunkById(self::CHUNK_SIZE, function ($games) use ($activeTeamIds, &$totalInserted, $bar) {
                    $gameIds = $games->pluck('id')->all();
                    $totalInserted += $this->insertBatch($gameIds, $activeTeamIds);
                    $bar->advance(count($gameIds));
                });

            // Tournament games: each has its own team scope
            Game::where('country', $country)
                ->where('game_mode', Game::MODE_TOURNAMENT)
                ->select('id')
                ->chunkById(self::CHUNK_SIZE, function ($games) use (&$totalInserted, $bar) {
                    foreach ($games as $game) {
                        $teamIds = $this->tournamentTeamIds($game->id);
                        if (! empty($teamIds)) {
                            $totalInserted += $this->insertBatch([$game->id], $teamIds);
                        }
                        $bar->advance();
                    }
                });
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Inserted {$totalInserted} match-state rows.");

        return self::SUCCESS;
    }

    /**
     * Batch INSERT ... SELECT for a set of game IDs and team IDs.
     * Returns the number of rows inserted.
     */
    private function insertBatch(array $gameIds, array $teamIds): int
    {
        if (empty($gameIds) || empty($teamIds)) {
            return 0;
        }

        $gameIdArray = '{'.implode(',', $gameIds).'}';
        $teamIdArray = '{'.implode(',', $teamIds).'}';

        return DB::affectingStatement(<<<'SQL'
            INSERT INTO game_player_match_state (
                game_player_id, fitness, morale, injury_until, injury_type,
                appearances, season_appearances, goals, own_goals, assists,
                yellow_cards, red_cards, goals_conceded, clean_sheets
            )
            SELECT
                gp.id,
                COALESCE(gp.fitness, 80),
                COALESCE(gp.morale, 80),
                gp.injury_until,
                gp.injury_type,
                COALESCE(gp.appearances, 0),
                COALESCE(gp.season_appearances, 0),
                COALESCE(gp.goals, 0),
                COALESCE(gp.own_goals, 0),
                COALESCE(gp.assists, 0),
                COALESCE(gp.yellow_cards, 0),
                COALESCE(gp.red_cards, 0),
                COALESCE(gp.goals_conceded, 0),
                COALESCE(gp.clean_sheets, 0)
            FROM game_players gp
            WHERE gp.game_id = ANY(?::uuid[])
              AND gp.team_id = ANY(?::uuid[])
            ON CONFLICT (game_player_id) DO NOTHING
        SQL, [$gameIdArray, $teamIdArray]);
    }

    /**
     * For tournament games, all teams that have players in the game are active.
     *
     * @return string[]
     */
    private function tournamentTeamIds(string $gameId): array
    {
        return DB::table('game_players')
            ->where('game_id', $gameId)
            ->whereNotNull('team_id')
            ->distinct()
            ->pluck('team_id')
            ->all();
    }
}
