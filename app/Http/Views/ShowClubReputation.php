<?php

namespace App\Http\Views;

use App\Models\Game;
use App\Modules\Manager\Services\CareerSummaryService;
use App\Modules\Manager\Services\PerformanceHistoryService;
use App\Modules\Reputation\Services\ReputationSummaryService;

class ShowClubReputation
{
    public function __construct(
        private readonly ReputationSummaryService $reputationSummaryService,
        private readonly CareerSummaryService $careerSummaryService,
        private readonly PerformanceHistoryService $performanceHistoryService,
    ) {}

    public function __invoke(string $gameId)
    {
        $game = Game::with('team.clubProfile')->findOrFail($gameId);
        abort_if($game->isTournamentMode(), 404);

        $userId = (int) auth()->id();

        return view('club.reputation', [
            'game' => $game,
            'summary' => $this->reputationSummaryService->build($game),
            'career' => $this->careerSummaryService->build($game, $userId),
            'trophyCabinet' => $this->careerSummaryService->buildTrophyCabinet($game, $userId),
            'history' => $this->performanceHistoryService->build($game),
        ]);
    }
}
