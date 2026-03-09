<?php

namespace App\Modules\Season\Listeners;

use App\Events\SeasonCompleted;
use App\Models\Competition;
use App\Models\GameStanding;
use App\Modules\Competition\Promotions\PromotionRelegationFactory;
use App\Modules\Finance\Services\SeasonSimulationService;

class SimulateOtherLeagues
{
    public function __construct(
        private readonly PromotionRelegationFactory $factory,
        private readonly SeasonSimulationService $simulationService,
    ) {}

    public function handle(SeasonCompleted $event): void
    {
        $game = $event->game;

        $rule = $this->factory->forCompetition($game->competition_id);

        if (! $rule) {
            return;
        }

        $otherCompetitionId = $rule->getTopDivision() === $game->competition_id
            ? $rule->getBottomDivision()
            : $rule->getTopDivision();

        // Skip if real standings already exist for the other division
        $hasRealStandings = GameStanding::where('game_id', $game->id)
            ->where('competition_id', $otherCompetitionId)
            ->exists();

        if ($hasRealStandings) {
            return;
        }

        $otherCompetition = Competition::find($otherCompetitionId);

        if (! $otherCompetition) {
            return;
        }

        $this->simulationService->simulateLeague($game, $otherCompetition);
    }
}
