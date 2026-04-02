<?php

namespace App\Modules\Season\Processors;

use App\Modules\Season\Contracts\SeasonProcessor;
use App\Modules\Season\DTOs\SeasonTransitionData;
use App\Modules\Academy\Services\YouthAcademyService;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Squad\DTOs\GeneratedPlayerData;
use App\Modules\Squad\Services\PlayerGeneratorService;
use App\Modules\Transfer\Services\ContractService;
use App\Models\Game;
use App\Models\GameNotification;
use App\Models\GamePlayer;
use App\Support\PositionMapper;
use Carbon\Carbon;

/**
 * Ensures the user's squad meets minimum size at season start.
 *
 * Three phases, in order:
 * 1. Auto-promote academy players who have reached ACADEMY_END age.
 * 2. If squad is still under MIN_SQUAD_SIZE, promote best remaining academy players.
 * 3. If still under, generate synthetic players for remaining spots.
 */
class YouthAcademyPromotionProcessor implements SeasonProcessor
{
    /**
     * Representative position per group, used when generating synthetic players.
     */
    private const GROUP_REPRESENTATIVE = [
        'Goalkeeper' => 'Goalkeeper',
        'Defender' => 'Centre-Back',
        'Midfielder' => 'Central Midfield',
        'Forward' => 'Centre-Forward',
    ];

    public function __construct(
        private readonly YouthAcademyService $youthAcademyService,
        private readonly NotificationService $notificationService,
        private readonly PlayerGeneratorService $playerGenerator,
    ) {}

    public function priority(): int
    {
        return 28; // Before fixtures (30) and budget projections (50)
    }

    public function process(Game $game, SeasonTransitionData $data): SeasonTransitionData
    {
        // Phase 1: Promote overage academy players (mandatory — they must leave)
        $overagePromoted = $this->youthAcademyService->promoteOveragePlayers($game);

        if ($overagePromoted->isNotEmpty()) {
            $data->setMetadata('academy_overage_promoted', $overagePromoted->count());

            $this->notificationService->create(
                game: $game,
                type: GameNotification::TYPE_ACADEMY_BATCH,
                title: __('notifications.academy_overage_promoted_title'),
                message: __('notifications.academy_overage_promoted_message', ['count' => $overagePromoted->count()]),
                priority: GameNotification::PRIORITY_INFO,
            );
        }

        // Phase 2: If squad is under minimum, promote best academy players to fill
        $squadCount = ContractService::squadCount($game);
        $deficit = ContractService::MIN_SQUAD_SIZE - $squadCount;

        $gapPromoted = $this->youthAcademyService->promoteBestPlayers($game, $deficit);

        if ($gapPromoted->isNotEmpty()) {
            $data->setMetadata('academy_gap_promoted', $gapPromoted->count());

            $this->notificationService->create(
                game: $game,
                type: GameNotification::TYPE_ACADEMY_BATCH,
                title: __('notifications.academy_gap_promoted_title'),
                message: __('notifications.academy_gap_promoted_message', ['count' => $gapPromoted->count()]),
                priority: GameNotification::PRIORITY_INFO,
            );
        }

        // Phase 3: If still under minimum, generate synthetic players
        $squadCount = ContractService::squadCount($game);
        $remaining = ContractService::MIN_SQUAD_SIZE - $squadCount;

        if ($remaining > 0) {
            $this->generateSyntheticPlayers($game, $remaining, $data);
        }

        return $data;
    }

    /**
     * Generate synthetic players to fill remaining squad gaps.
     * Positions are chosen by finding the most depleted position group.
     */
    private function generateSyntheticPlayers(Game $game, int $count, SeasonTransitionData $data): void
    {
        $players = GamePlayer::where('game_id', $game->id)
            ->where('team_id', $game->team_id)
            ->get();

        $teamAvgAbility = $this->calculateTeamAverageAbility($players);
        $positions = $this->selectPositionsToFill($players, $count);

        $emergencyNames = [];
        foreach ($positions as $position) {
            $playerData = $this->buildPlayerData($game, $position, $teamAvgAbility);
            $gamePlayer = $this->playerGenerator->create($game, $playerData);
            $emergencyNames[] = $gamePlayer->player->name;
        }

        if (! empty($emergencyNames)) {
            $this->notificationService->notifyEmergencySignings($game, $emergencyNames);
            $data->setMetadata('emergency_signings', count($emergencyNames));
        }
    }

    /**
     * Pick positions to fill by cycling through the most depleted position groups.
     *
     * @return string[]
     */
    private function selectPositionsToFill($players, int $count): array
    {
        $groupCounts = ['Goalkeeper' => 0, 'Defender' => 0, 'Midfielder' => 0, 'Forward' => 0];
        foreach ($players as $player) {
            $group = PositionMapper::getPositionGroup($player->position);
            if (isset($groupCounts[$group])) {
                $groupCounts[$group]++;
            }
        }

        $groupTargets = ['Goalkeeper' => 3, 'Defender' => 6, 'Midfielder' => 6, 'Forward' => 4];
        $positions = [];

        for ($i = 0; $i < $count; $i++) {
            // Find group with largest deficit relative to target
            $worstGroup = null;
            $worstDeficit = PHP_INT_MIN;
            foreach ($groupTargets as $group => $target) {
                $deficit = $target - $groupCounts[$group];
                if ($deficit > $worstDeficit) {
                    $worstDeficit = $deficit;
                    $worstGroup = $group;
                }
            }

            $positions[] = self::GROUP_REPRESENTATIVE[$worstGroup];
            $groupCounts[$worstGroup]++;
        }

        return $positions;
    }

    private function buildPlayerData(Game $game, string $position, int $teamAvgAbility): GeneratedPlayerData
    {
        $variance = mt_rand(-10, 10);
        $baseAbility = max(35, min(90, $teamAvgAbility + $variance));

        $techBias = mt_rand(-5, 5);
        $technical = max(30, min(95, $baseAbility + $techBias));
        $physical = max(30, min(95, $baseAbility - $techBias));

        $age = mt_rand(22, 29);
        $dateOfBirth = $game->current_date->copy()->subYears($age)->subDays(mt_rand(0, 364));

        return new GeneratedPlayerData(
            teamId: $game->team_id,
            position: $position,
            technical: $technical,
            physical: $physical,
            dateOfBirth: $dateOfBirth,
            contractYears: mt_rand(2, 4),
        );
    }

    private function calculateTeamAverageAbility($players): int
    {
        if ($players->isEmpty()) {
            return 55;
        }

        $total = $players->sum(fn ($p) => (int) round(($p->game_technical_ability + $p->game_physical_ability) / 2));

        return (int) round($total / $players->count());
    }
}
