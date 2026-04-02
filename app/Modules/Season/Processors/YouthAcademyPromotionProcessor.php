<?php

namespace App\Modules\Season\Processors;

use App\Modules\Season\Contracts\SeasonProcessor;
use App\Modules\Season\DTOs\SeasonTransitionData;
use App\Modules\Academy\Services\YouthAcademyService;
use App\Modules\Notification\Services\NotificationService;
use App\Models\Game;
use App\Models\GameNotification;

/**
 * Auto-promotes academy players who have reached ACADEMY_END age
 * to the first team at the start of the new season.
 */
class YouthAcademyPromotionProcessor implements SeasonProcessor
{
    public function __construct(
        private readonly YouthAcademyService $youthAcademyService,
        private readonly NotificationService $notificationService,
    ) {}

    public function priority(): int
    {
        return 28; // Before fixtures (30) and budget projections (50)
    }

    public function process(Game $game, SeasonTransitionData $data): SeasonTransitionData
    {
        $promoted = $this->youthAcademyService->promoteOveragePlayers($game);

        if ($promoted->isNotEmpty()) {
            $data->setMetadata('academy_overage_promoted', $promoted->count());

            $this->notificationService->create(
                game: $game,
                type: GameNotification::TYPE_ACADEMY_BATCH,
                title: __('notifications.academy_overage_promoted_title'),
                message: __('notifications.academy_overage_promoted_message', ['count' => $promoted->count()]),
                priority: GameNotification::PRIORITY_INFO,
            );
        }

        return $data;
    }
}
