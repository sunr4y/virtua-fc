<?php

namespace App\Events;

use App\Models\Game;

class SeasonCompleted
{
    public function __construct(
        public readonly Game $game,
    ) {}
}
