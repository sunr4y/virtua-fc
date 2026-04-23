<?php

namespace App\Console\Commands;

use App\Models\ManagerTrophy;
use App\Models\TeamReputation;
use Illuminate\Console\Command;

/**
 * One-shot to credit the user team's historical trophies toward
 * TeamReputation.loyalty_points.
 *
 * Loyalty was added late (migration 2026_04_16_000002) and then wiped by
 * app:resync-fan-loyalty so accumulated drift on a bogus baseline wouldn't
 * persist. As a side effect, long-running career games entered the
 * post-resync world with loyalty frozen at the curated ClubProfile anchor,
 * ignoring an entire career of titles. This command reads ManagerTrophy
 * (the only reliable historical trophy record for the user's team) and
 * nudges loyalty_points using the same per-trophy deltas the season-close
 * processor uses, so the user's fan base finally reflects their career.
 *
 * Scope: user team only — AI teams don't have comparable historical cup
 * data. Their curated anchor already represents their "cultural" loyalty,
 * which is the design intent for AI clubs.
 *
 * Idempotency: defaults to dry-run; --force commits. Running --force twice
 * would double-credit, so the command prints a sharp warning before
 * writing.
 */
class BackfillHistoricalLoyalty extends Command
{
    protected $signature = 'app:backfill-historical-loyalty {--force : Commit changes; otherwise only prints a dry-run}';

    protected $description = 'Credit user-team historical trophies toward TeamReputation.loyalty_points';

    public function handle(): int
    {
        $deltas = config('finances.loyalty_deltas', []);
        $leagueDelta = (int) ($deltas['league_title'] ?? 0);
        $cupDelta = (int) ($deltas['cup'] ?? 0);

        if ($leagueDelta === 0 && $cupDelta === 0) {
            $this->error('loyalty_deltas config is missing league_title / cup values.');
            return self::FAILURE;
        }

        $commit = (bool) $this->option('force');
        $this->info($commit ? 'COMMIT mode — writing changes.' : 'DRY-RUN — no changes will be written. Pass --force to commit.');
        $this->line("Using deltas: league_title=+{$leagueDelta}, cup/european/supercup=+{$cupDelta}");

        // Aggregate trophies per (game_id, team_id). trophy_type 'league' uses
        // league delta; all cup-like trophies (cup, european, supercup) share
        // the cup delta.
        $rows = ManagerTrophy::query()
            ->selectRaw('game_id, team_id, trophy_type, COUNT(*) as total')
            ->groupBy('game_id', 'team_id', 'trophy_type')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No ManagerTrophy records found. Nothing to backfill.');
            return self::SUCCESS;
        }

        $perTeam = [];
        foreach ($rows as $row) {
            $key = $row->game_id . '|' . $row->team_id;
            $perTeam[$key] ??= [
                'game_id' => $row->game_id,
                'team_id' => $row->team_id,
                'league' => 0,
                'cup_like' => 0,
            ];

            if ($row->trophy_type === 'league') {
                $perTeam[$key]['league'] += (int) $row->total;
            } else {
                $perTeam[$key]['cup_like'] += (int) $row->total;
            }
        }

        $reputations = TeamReputation::query()
            ->whereIn('game_id', array_column($perTeam, 'game_id'))
            ->whereIn('team_id', array_column($perTeam, 'team_id'))
            ->get()
            ->keyBy(fn ($rep) => $rep->game_id . '|' . $rep->team_id);

        $updated = 0;
        $skipped = 0;

        foreach ($perTeam as $key => $tally) {
            $rep = $reputations->get($key);
            if (! $rep) {
                $skipped++;
                continue;
            }

            $delta = ($tally['league'] * $leagueDelta) + ($tally['cup_like'] * $cupDelta);
            if ($delta === 0) {
                continue;
            }

            $before = (int) $rep->loyalty_points;
            $after = max(TeamReputation::LOYALTY_MIN, min(TeamReputation::LOYALTY_MAX, $before + $delta));

            $this->line(sprintf(
                '  game=%s team=%s league=%d cup-like=%d  Δ=%+d  %d → %d',
                $rep->game_id,
                $rep->team_id,
                $tally['league'],
                $tally['cup_like'],
                $delta,
                $before,
                $after,
            ));

            if ($commit && $after !== $before) {
                $rep->loyalty_points = $after;
                $rep->save();
            }
            $updated++;
        }

        $this->info("Rows processed: {$updated}; skipped (no matching TeamReputation): {$skipped}");

        if (! $commit) {
            $this->warn('Dry-run complete. Re-run with --force to apply. Running twice with --force will double-credit.');
        }

        return self::SUCCESS;
    }
}
