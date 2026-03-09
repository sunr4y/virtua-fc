<?php

namespace App\Http\Actions;

use App\Events\SeasonCompleted;
use App\Modules\Match\Services\MatchdayOrchestrator;
use App\Models\Game;

class AdvanceMatchday
{
    public function __construct(
        private readonly MatchdayOrchestrator $orchestrator,
    ) {}

    public function __invoke(string $gameId)
    {
        $game = Game::findOrFail($gameId);
        $result = $this->orchestrator->advance($game);

        return match ($result->type) {
            'blocked' => $this->redirectBlocked($gameId, $result->pendingAction),
            'season_complete' => $this->redirectSeasonEnd($game, $gameId),
            'live_match' => redirect()->route('game.live-match', [
                'gameId' => $gameId,
                'matchId' => $result->matchId,
            ]),
            'done' => $this->redirectDone($game, $gameId),
        };
    }

    private function redirectSeasonEnd(Game $game, string $gameId)
    {
        event(new SeasonCompleted($game));

        return redirect()->route('game.season-end', $gameId);
    }

    private function redirectDone(Game $game, string $gameId)
    {
        event(new SeasonCompleted($game));

        return redirect()->route('show-game', $gameId);
    }

    private function redirectBlocked(string $gameId, ?array $pendingAction)
    {
        if ($pendingAction && $pendingAction['route']) {
            return redirect()->route($pendingAction['route'], $gameId)
                ->with('warning', __('messages.action_required'));
        }

        return redirect()->route('show-game', $gameId)
            ->with('warning', __('messages.action_required'));
    }
}
