<?php

namespace App\Modules\Season\Listeners;

use App\Events\TournamentEnded;
use App\Models\Game;
use App\Models\TournamentSummary;

class DetectTournamentEnded
{
    /**
     * Fire TournamentEnded if the tournament game has genuinely reached its end.
     *
     * Called directly from MatchFinalizationService::finalize (after beforeMatches
     * has had a chance to generate the next knockout round) and from end-of-run
     * actions (SimulateTournament, FinalizeMatch) that drive AI-only matches which
     * never flow through finalize(). Running this as a MatchFinalized listener is
     * wrong for group_stage_cup competitions: the next round isn't generated yet
     * at the point listeners fire, so the "no unplayed matches" check would be
     * true between rounds and end the tournament prematurely.
     */
    public function detect(Game $game): void
    {
        if (! $game->isTournamentMode()) {
            return;
        }

        if ($game->deleting_at !== null) {
            return;
        }

        $hasUnplayed = $game->matches()->where('played', false)->exists();

        if ($hasUnplayed) {
            return;
        }

        $summaryExists = TournamentSummary::where('original_game_id', $game->id)->exists();

        if ($summaryExists) {
            return;
        }

        TournamentEnded::dispatch($game);
    }
}
