# B Team Call-Ups — Implementation Plan

## Overview

Temporary call-ups allow a manager to bring B team players into the A team squad for a single matchday. The player appears in the A team lineup selection, plays the match, and automatically returns to the B team after the matchday is processed. This mirrors real football where reserve players are called up to fill bench spots or cover for injuries.

## Approach: Reuse the Loan Mechanism

The existing `Loan` model already handles temporary team reassignment: it changes `GamePlayer.team_id`, remembers the `parent_team_id`, and has automatic return logic. Rather than building a parallel system, we extend `Loan` with a `type` column to distinguish call-ups from regular loans.

**Why this works with zero changes to the match pipeline:**
- `LineupService::getAvailablePlayers()` filters by `team_id` — a called-up player's `team_id` is already set to the A team
- `MatchdayOrchestrator::processBatch()` loads players via `whereIn('team_id', $teamIds)` — called-up players are included automatically
- `SubstitutionService` validates by `team_id` — works as-is
- `ShowSquad` queries by `team_id` — called-up players appear in A team roster

---

## Implementation Steps

### Step 1: Migration — Add `type` column to `loans`

**File:** `database/migrations/2026_03_19_000001_add_type_to_loans_table.php`

```php
Schema::table('loans', function (Blueprint $table) {
    $table->string('type')->default('loan')->after('status');
});
```

Values: `'loan'` (default, existing behavior) or `'call_up'`.

No index needed — call-ups are always queried alongside `game_id + status` which is already indexed.

---

### Step 2: Update `Loan` Model

**File:** `app/Models/Loan.php`

Add:
- Constants: `TYPE_LOAN = 'loan'`, `TYPE_CALL_UP = 'call_up'`
- Add `'type'` to `$fillable`
- Method: `isCallUp(): bool`
- Scope: `scopeCallUps($query)` — filters to `type = 'call_up'`
- Scope: `scopeLoans($query)` — filters to `type = 'loan'` (for existing loan queries that should exclude call-ups)

---

### Step 3: Add `GamePlayer::activeCallUp()` Relationship

**File:** `app/Models/GamePlayer.php`

```php
public function activeCallUp(): HasOne
{
    return $this->hasOne(Loan::class, 'game_player_id')
        ->where('status', Loan::STATUS_ACTIVE)
        ->where('type', Loan::TYPE_CALL_UP);
}

public function isOnCallUp(): bool
{
    return $this->activeCallUp()->exists();
}
```

---

### Step 4: `LoanService` — Call-Up Methods

**File:** `app/Modules/Transfer/Services/LoanService.php`

#### `createCallUp(Game $game, GamePlayer $player, Team $callUpTeam): Loan`

1. **Validate:**
   - Player's current team is a B team (`team.parent_team_id !== null`)
   - The B team's parent is the calling A team (`team.parent_team_id === callUpTeam.id`)
   - Player is not injured (`injury_until` is null or in the past)
   - Player does not already have an active loan or call-up
   - A team squad size hasn't exceeded a maximum (e.g., 25 matchday players)

2. **Create Loan record:**
   ```php
   Loan::create([
       'game_id'        => $game->id,
       'game_player_id' => $player->id,
       'parent_team_id' => $player->team_id,  // B team
       'loan_team_id'   => $callUpTeam->id,   // A team
       'started_at'     => $game->current_date,
       'return_at'      => $game->current_date, // same day
       'status'         => Loan::STATUS_ACTIVE,
       'type'           => Loan::TYPE_CALL_UP,
   ]);
   ```

3. **Update player:** `$player->update(['team_id' => $callUpTeam->id])`

4. **Assign squad number:** Use `GamePlayer::nextAvailableNumber()` for the A team.

#### `returnCallUps(Game $game): void`

1. Find all active call-ups for the game: `Loan::where('game_id', ...)->active()->callUps()->get()`
2. For each: reset `GamePlayer.team_id` to `parent_team_id`, mark loan as `completed`
3. Reassign squad number at the B team

#### `getActiveCallUps(Game $game): Collection`

Returns active call-up loans for the user's team (for UI display).

---

### Step 5: Hook Auto-Return into Matchday Advancement

**File:** `app/Modules/Match/Services/MatchdayOrchestrator.php`

In the `advance()` method, **after** the transaction completes (after all matches in the batch are processed), call:

```php
$this->loanService->returnCallUps($game);
```

This ensures:
- Called-up players are available for the matchday's matches
- They return to the B team before the next matchday begins
- The return happens regardless of whether the user's team played

**Injection:** Add `LoanService` to `MatchdayOrchestrator`'s constructor.

**Placement:** Inside the `advance()` method, after the `DB::transaction` block but before the `ProcessCareerActions` dispatch. This way the return is committed before any career actions process.

```php
$result = DB::transaction(function () use ($game) {
    // ... existing matchday processing ...
});

// Return all matchday call-ups after the batch is fully processed
$this->loanService->returnCallUps($game);

// Dispatch career actions to background after transaction commits
if ($this->careerActionTicks > 0) {
    // ...existing code...
}
```

---

### Step 6: Exclude Call-Ups from Regular Loan Logic

**File:** `app/Modules/Transfer/Services/LoanService.php`

Update `returnAllLoans()` to skip call-ups (they auto-return per matchday, not per season):

```php
$activeLoans = Loan::where('game_id', $game->id)
    ->active()
    ->loans()  // excludes call-ups
    ->with('gamePlayer')
    ->get();
```

Update `getActiveLoans()` to separate call-ups from loans in the returned data, or filter them out so the transfers UI doesn't show call-ups as regular loans.

---

### Step 7: Action Class — `CallUpBTeamPlayer`

**File:** `app/Http/Actions/CallUpBTeamPlayer.php`

Invokable action that handles the form submission:

```php
class CallUpBTeamPlayer
{
    public function __invoke(string $gameId, string $playerId, LoanService $loanService)
    {
        $game = Game::findOrFail($gameId);
        $player = GamePlayer::where('game_id', $gameId)->findOrFail($playerId);
        $team = Team::findOrFail($game->team_id);

        $loanService->createCallUp($game, $player, $team);

        return redirect()->route('game.squad', $gameId)
            ->with('success', __('messages.player_called_up', ['player' => $player->player->name]));
    }
}
```

---

### Step 8: Action Class — `ReturnCalledUpPlayer`

**File:** `app/Http/Actions/ReturnCalledUpPlayer.php`

Allows manual early return (before the matchday auto-return):

```php
class ReturnCalledUpPlayer
{
    public function __invoke(string $gameId, string $playerId, LoanService $loanService)
    {
        $game = Game::findOrFail($gameId);
        $player = GamePlayer::where('game_id', $gameId)->findOrFail($playerId);

        $loan = $player->activeCallUp;

        if ($loan) {
            $loanService->returnLoan($loan);
        }

        return redirect()->route('game.squad', $gameId)
            ->with('success', __('messages.player_returned_to_b_team', ['player' => $player->player->name]));
    }
}
```

---

### Step 9: Routes

**File:** `routes/web.php`

```php
Route::post('/game/{gameId}/squad/call-up/{playerId}', CallUpBTeamPlayer::class)
    ->name('game.squad.call-up');

Route::post('/game/{gameId}/squad/return-call-up/{playerId}', ReturnCalledUpPlayer::class)
    ->name('game.squad.return-call-up');
```

---

### Step 10: UI — Call-Up Controls

Two integration points in existing views:

#### A) B Team Roster View (new, part of broader B team feature)

Each player row gets a **"Call Up"** button that POSTs to `game.squad.call-up`. Disabled if:
- Player is injured
- Player already has an active call-up
- A team is at matchday squad limit

#### B) A Team Squad View (`ShowSquad`)

Called-up players appear in the squad list with a **visual badge** (e.g., "B" badge or "Called Up" indicator) and a **"Return"** button that POSTs to `game.squad.return-call-up`.

**Update `ShowSquad`** to eager-load `activeCallUp` relationship and pass a `is_called_up` flag per player to the Blade template.

---

### Step 11: Translations

**Files:** `lang/es/messages.php`, `lang/en/messages.php`

```php
// English
'player_called_up' => ':player has been called up to the first team.',
'player_returned_to_b_team' => ':player has been returned to the B team.',

// Spanish
'player_called_up' => ':player ha sido convocado al primer equipo.',
'player_returned_to_b_team' => ':player ha vuelto al equipo B.',
```

---

## What Does NOT Need to Change

| System | Why it works as-is |
|--------|--------------------|
| `LineupService::getAvailablePlayers()` | Filters by `team_id` — called-up player has A team's `team_id` |
| `MatchdayOrchestrator::processBatch()` | Loads players by `team_id` — no change needed |
| `SubstitutionService` | Validates by `team_id` — works automatically |
| `MatchSimulator` | Receives players from lineup — agnostic to origin |
| `PlayerDevelopmentService` | Processes all `GamePlayer` records — B team players develop normally |
| `EligibilityService` | Checks injury/suspension — doesn't care about call-up status |
| `PlayerConditionService` | Updates fitness/morale — works on all players regardless of team |

---

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| Called-up player gets injured during match | Injury is applied to `GamePlayer`. Auto-return still happens after matchday. Player is injured on B team roster. |
| Called-up player gets a red card | Suspension is per-competition. Since B team doesn't compete, the suspension only applies if they're called up again for the same competition. |
| Called-up player gets a yellow card | Accumulation tracked per competition — same as red card logic. |
| User tries to call up during matchday advance | Action is blocked — same as other squad actions during advancement. |
| Multiple call-ups at once | Each creates its own Loan record. All return after the matchday. |
| Season ends with active call-ups | `returnCallUps()` runs after every matchday. Season-end `returnAllLoans()` is a safety net (now scoped to `loans()` type). |
| Player is called up but not selected in lineup | Still returns after matchday — call-up is squad availability, not lineup guarantee. |

---

## File Summary

| File | Change Type |
|------|-------------|
| `database/migrations/2026_03_19_000001_add_type_to_loans_table.php` | **New** |
| `app/Models/Loan.php` | Modify (constants, fillable, scopes, method) |
| `app/Models/GamePlayer.php` | Modify (relationship + helper) |
| `app/Modules/Transfer/Services/LoanService.php` | Modify (3 new methods + scope existing queries) |
| `app/Modules/Match/Services/MatchdayOrchestrator.php` | Modify (inject LoanService, add return call) |
| `app/Http/Actions/CallUpBTeamPlayer.php` | **New** |
| `app/Http/Actions/ReturnCalledUpPlayer.php` | **New** |
| `routes/web.php` | Modify (2 routes) |
| `app/Http/Views/ShowSquad.php` | Modify (eager-load call-up flag) |
| `lang/en/messages.php` | Modify (2 keys) |
| `lang/es/messages.php` | Modify (2 keys) |

**Estimated total: ~200 lines of new code across 11 files.**
