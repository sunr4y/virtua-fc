<?php

namespace Tests\Unit;

use App\Models\Competition;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GamePlayer;
use App\Models\MatchEvent;
use App\Models\Team;
use App\Models\User;
use App\Modules\Lineup\Services\SubstitutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesLineups;

class BuildActiveLineupRedCardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesLineups;

    private SubstitutionService $substitutionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->substitutionService = app(SubstitutionService::class);
    }

    public function test_build_active_lineup_drops_sent_off_goalkeeper_when_sub_is_outfield_for_reserve_keeper(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $awayTeam = Team::factory()->create();
        $competition = Competition::factory()->league()->create(['id' => 'ESP1']);
        $team->competitions()->attach($competition->id, ['season' => '2025']);

        $game = Game::factory()->create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'competition_id' => $competition->id,
            'season' => '2025',
            'current_date' => '2025-10-01',
        ]);

        $lineup = $this->createLineup($game, $team, 11, 70);
        $startingGk = $lineup->firstWhere('position', 'Goalkeeper');
        $midfielder = $lineup->firstWhere('position', 'Central Midfield');
        $this->assertNotNull($startingGk);
        $this->assertNotNull($midfielder);

        $reserveGk = GamePlayer::factory()
            ->forGame($game)
            ->forTeam($team)
            ->goalkeeper()
            ->create([
                'game_technical_ability' => 70,
                'game_physical_ability' => 70,
                'fitness' => 95,
                'morale' => 80,
            ]);

        $match = GameMatch::factory()
            ->forGame($game)
            ->forCompetition($competition)
            ->between($team, $awayTeam)
            ->create([
                'home_lineup' => $lineup->pluck('id')->all(),
            ]);

        MatchEvent::query()->create([
            'game_id' => $game->id,
            'game_match_id' => $match->id,
            'game_player_id' => $startingGk->id,
            'team_id' => $team->id,
            'minute' => 30,
            'event_type' => MatchEvent::TYPE_RED_CARD,
            'metadata' => [],
        ]);

        $active = $this->substitutionService->buildActiveLineup($match, $team->id, [
            ['playerOutId' => $midfielder->id, 'playerInId' => $reserveGk->id],
        ]);

        $ids = $active->pluck('id')->all();

        $this->assertNotContains($startingGk->id, $ids, 'Sent-off goalkeeper should not appear on pitch');
        $this->assertNotContains($midfielder->id, $ids, 'Sacrificed outfielder should be off');
        $this->assertContains($reserveGk->id, $ids);
        $this->assertCount(10, $ids, 'One outfield sacrifices for reserve GK after primary GK sent off → 10 on pitch');
        $this->assertSame(1, $active->filter(fn (GamePlayer $p) => $p->position === 'Goalkeeper')->count());
    }
}
