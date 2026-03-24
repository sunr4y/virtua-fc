<?php

namespace App\Http\Actions;

use App\Modules\Season\Jobs\ProcessSeasonTransition;
use App\Models\Game;

class StartNewSeason
{
    public function __invoke(string $gameId)
    {
        $game = Game::findOrFail($gameId);

        // Verify season is complete
        $unplayedMatches = $game->matches()->where('played', false)->count();
        if ($unplayedMatches > 0) {
            return redirect()->route('show-game', $gameId)
                ->with('error', __('messages.season_not_complete'));
        }

        // Atomic check-and-set: only one request can win the race.
        // Clear checkpoint fields to prevent stale data from a previous
        // pipeline run (e.g. initial game setup) from causing the new
        // transition to skip processors.
        $updated = Game::where('id', $gameId)
            ->whereNull('season_transitioning_at')
            ->update([
                'season_transitioning_at' => now(),
                'season_transition_step' => null,
                'season_transition_data' => null,
            ]);

        if (! $updated) {
            return redirect()->route('show-game', $gameId);
        }

        ProcessSeasonTransition::dispatch($gameId);

        return redirect()->route('show-game', $gameId);
    }
}
