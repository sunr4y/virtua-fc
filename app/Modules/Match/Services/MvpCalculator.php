<?php

namespace App\Modules\Match\Services;

use Illuminate\Support\Collection;

/**
 * Calculates the MVP (Man of the Match) from player performances and match events.
 *
 * Shared between FullMatchSimulationService (initial simulation) and
 * MatchResimulationService (after tactical changes).
 */
class MvpCalculator
{
    /**
     * Score each player and return the ID of the best performer.
     *
     * @param  array  $performances  Map of playerId → performance modifier (0.70–1.30)
     * @param  Collection  $homePlayers  Home team players (need id, position_group)
     * @param  Collection  $awayPlayers  Away team players (need id, position_group)
     * @param  string  $homeTeamId
     * @param  string  $awayTeamId
     * @param  int  $homeScore  Final home score
     * @param  int  $awayScore  Final away score
     * @param  Collection  $events  Match events (MatchEventData or MatchEvent models with type/event_type and gamePlayerId/game_player_id)
     */
    public static function calculate(
        array $performances,
        Collection $homePlayers,
        Collection $awayPlayers,
        string $homeTeamId,
        string $awayTeamId,
        int $homeScore,
        int $awayScore,
        Collection $events,
    ): ?string {
        if (empty($performances)) {
            return null;
        }

        // Build lookup maps for position group and team membership
        $positionGroups = [];
        $playerTeams = [];
        foreach ($homePlayers as $player) {
            $positionGroups[$player->id] = $player->position_group;
            $playerTeams[$player->id] = $homeTeamId;
        }
        foreach ($awayPlayers as $player) {
            $positionGroups[$player->id] = $player->position_group;
            $playerTeams[$player->id] = $awayTeamId;
        }

        $goalsConceded = [
            $homeTeamId => $awayScore,
            $awayTeamId => $homeScore,
        ];

        $winningTeamId = match (true) {
            $homeScore > $awayScore => $homeTeamId,
            $awayScore > $homeScore => $awayTeamId,
            default => null,
        };

        // Position-scaled event bonuses (rarer contributions score higher)
        $goalBonuses = ['Goalkeeper' => 0.55, 'Defender' => 0.45, 'Midfielder' => 0.35, 'Forward' => 0.30];
        $assistBonuses = ['Goalkeeper' => 0.25, 'Defender' => 0.15, 'Midfielder' => 0.15, 'Forward' => 0.15];

        // Count events per player — supports both MatchEventData (type, gamePlayerId)
        // and MatchEvent models (event_type, game_player_id)
        $goals = [];
        $assists = [];
        $yellowCards = [];
        $redCards = [];

        // Entry/exit minutes for each player. Starters default to 0 entry; everyone
        // defaults to matchLength exit unless they're subbed off or red-carded.
        // Detect extra time by checking for events past regulation stoppage (minute 93).
        $entryMinutes = [];
        $exitMinutes = [];
        $matchLength = 90;

        foreach ($events as $event) {
            $type = $event->type ?? $event->event_type ?? null;
            $playerId = $event->gamePlayerId ?? $event->game_player_id ?? null;
            $minute = $event->minute ?? 0;
            $metadata = $event->metadata ?? null;

            if ($minute > 93) {
                $matchLength = 120;
            }

            if (! $playerId) {
                continue;
            }

            match ($type) {
                'goal' => $goals[$playerId] = ($goals[$playerId] ?? 0) + 1,
                'assist' => $assists[$playerId] = ($assists[$playerId] ?? 0) + 1,
                'yellow_card' => $yellowCards[$playerId] = ($yellowCards[$playerId] ?? 0) + 1,
                'red_card' => $redCards[$playerId] = ($redCards[$playerId] ?? 0) + 1,
                default => null,
            };

            if ($type === 'substitution') {
                $exitMinutes[$playerId] = $minute;
                $playerInId = $metadata['player_in_id'] ?? null;
                if ($playerInId) {
                    $entryMinutes[$playerInId] = $minute;
                }
            } elseif ($type === 'red_card') {
                $exitMinutes[$playerId] = $minute;
            }
        }

        $lateEntryThreshold = $matchLength - 10;

        // Score each player
        $bestPlayerId = null;
        $bestScore = -INF;
        $bestGoals = -1;
        $bestMinutesPlayed = -1;

        foreach ($performances as $playerId => $performance) {
            $group = $positionGroups[$playerId] ?? 'Midfielder';
            $teamId = $playerTeams[$playerId] ?? null;
            $teamConceded = $teamId ? ($goalsConceded[$teamId] ?? 0) : 0;

            $goalsScored = $goals[$playerId] ?? 0;
            $assistsMade = $assists[$playerId] ?? 0;
            $entryMinute = $entryMinutes[$playerId] ?? 0;
            $exitMinute = $exitMinutes[$playerId] ?? $matchLength;
            $minutesPlayed = max(0, $exitMinute - $entryMinute);

            // Late entrants (entered in the last 10 minutes) are ineligible unless
            // they scored or assisted — brief cameos shouldn't win MVP.
            if ($entryMinute > $lateEntryThreshold && $goalsScored === 0 && $assistsMade === 0) {
                continue;
            }

            // Normalized performance: map 0.70-1.30 to 0.0-1.0
            $score = ($performance - 0.70) / 0.60;

            // Position-scaled goal/assist bonuses
            $score += $goalsScored * ($goalBonuses[$group] ?? 0.15);
            $score += $assistsMade * ($assistBonuses[$group] ?? 0.10);

            // Card penalties
            $score -= ($yellowCards[$playerId] ?? 0) * 0.10;
            $score -= ($redCards[$playerId] ?? 0) * 0.30;

            // Clean sheet bonus for goalkeepers and defenders
            if ($teamConceded === 0) {
                $score += match ($group) {
                    'Goalkeeper' => 0.20,
                    'Defender' => 0.15,
                    default => 0.0,
                };
            } elseif ($teamConceded === 1) {
                $score += match ($group) {
                    'Goalkeeper' => 0.05,
                    'Defender' => 0.05,
                    default => 0.0,
                };
            }

            // Goals conceded penalty for goalkeepers
            if ($group === 'Goalkeeper') {
                $score -= match (true) {
                    $teamConceded >= 4 => 0.20,
                    $teamConceded >= 3 => 0.10,
                    default => 0.0,
                };
            }

            // Winning team edge
            $isWinner = $winningTeamId !== null && $teamId === $winningTeamId;
            if ($isWinner) {
                $score += 0.08;
            }

            // Goals against penalty for losing team (linear per goal conceded)
            $losingTeamId = match (true) {
                $homeScore > $awayScore => $awayTeamId,
                $awayScore > $homeScore => $homeTeamId,
                default => null,
            };
            if ($losingTeamId !== null && $teamId === $losingTeamId) {
                $score -= min($teamConceded * 0.04, 0.20);
            }

            // Tiebreaks on equal score: more goals scored, then more minutes played.
            $takeThis = $score > $bestScore
                || ($score === $bestScore && $goalsScored > $bestGoals)
                || ($score === $bestScore && $goalsScored === $bestGoals && $minutesPlayed > $bestMinutesPlayed);

            if ($takeThis) {
                $bestScore = $score;
                $bestGoals = $goalsScored;
                $bestMinutesPlayed = $minutesPlayed;
                $bestPlayerId = $playerId;
            }
        }

        return $bestPlayerId;
    }
}
