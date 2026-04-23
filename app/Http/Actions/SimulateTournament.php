<?php

namespace App\Http\Actions;

use App\Models\TournamentSummary;
use App\Modules\Match\Services\MatchdayOrchestrator;
use App\Modules\Season\Listeners\DetectTournamentEnded;
use App\Models\Game;

class SimulateTournament
{
    public function __construct(
        private readonly MatchdayOrchestrator $orchestrator,
        private readonly DetectTournamentEnded $tournamentEndDetector,
    ) {}

    public function __invoke(string $gameId)
    {
        $game = Game::findOrFail($gameId);

        if (! $game->isTournamentMode()) {
            return redirect()->route('show-game', $gameId);
        }

        for ($i = 0; $i < 500; $i++) {
            $result = $this->orchestrator->advance($game);

            if ($result->type === 'live_match') {
                return redirect()->route('game.live-match', [
                    'gameId' => $game->id,
                    'matchId' => $result->matchId,
                ]);
            }

            if ($result->type === 'blocked') {
                // Transient block — redirect back to game view instead of ending tournament
                return redirect()->route('show-game', $game->id)
                    ->with('warning', __('messages.action_required'));
            }

            if (in_array($result->type, ['done', 'season_complete'])) {
                break;
            }

            $game->refresh()->setRelations([]);
        }

        // Finalization (snapshot, soft-delete, activation) happens via the
        // TournamentEnded listener chain fired from DetectTournamentEnded.
        // MatchFinalizationService invokes the detector for the user's matches;
        // call it here too so AI-only completions (e.g. an eliminated user
        // fast-forwarding through remaining knockouts) still end the tournament.
        $this->tournamentEndDetector->detect($game->refresh()->setRelations([]));

        $summary = TournamentSummary::where('original_game_id', $game->id)->first();

        if (! $summary) {
            return redirect()->route('show-game', $game->id);
        }

        return redirect()->route('tournament-summary.show', $summary->id);
    }
}
