<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PARTITION_COUNT = 64;

    public function up(): void
    {
        // Partitioning is PostgreSQL-only; skip on other drivers (e.g. SQLite in tests)
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Entire migration is wrapped in a transaction so any failure rolls back cleanly.
        // PostgreSQL supports transactional DDL.
        DB::transaction(function () {
            // --- Step 1: Drop FK constraints pointing TO game_matches ---
            // match_events is the only table with a FK referencing game_matches.id.
            // Note: game_matches_mvp_player_id_foreign (pointing to game_players) was
            // already dropped by the game_players partition migration.
            Schema::table('match_events', fn ($t) => $t->dropForeign('match_events_game_match_id_foreign'));

            $expectedCount = DB::table('game_matches')->count();

            // --- Step 2: Rename existing table and drop its indexes ---
            // PostgreSQL keeps original index names after table rename, which
            // would conflict with the identically-named indexes on the new table.
            Schema::rename('game_matches', 'game_matches_old');

            // PostgreSQL keeps original index names after table rename, which
            // would conflict with the identically-named indexes on the new table.
            DB::statement('ALTER TABLE game_matches_old DROP CONSTRAINT IF EXISTS game_matches_pkey CASCADE');
            DB::statement('DROP INDEX IF EXISTS game_matches_game_id_competition_id_round_number_index');
            DB::statement('DROP INDEX IF EXISTS game_matches_game_id_played_index');
            DB::statement('DROP INDEX IF EXISTS game_matches_game_id_competition_id_played_index');
            DB::statement('DROP INDEX IF EXISTS game_matches_game_id_played_scheduled_date_index');
            DB::statement('DROP INDEX IF EXISTS game_matches_mvp_player_id_index');
            DB::statement('DROP INDEX IF EXISTS game_matches_cup_tie_id_index');

            // --- Step 3: Create partitioned table ---
            DB::statement("
                CREATE TABLE game_matches (
                    id UUID NOT NULL,
                    game_id UUID NOT NULL,
                    competition_id VARCHAR(10) NOT NULL,
                    round_number SMALLINT NOT NULL,
                    round_name VARCHAR(255),
                    home_team_id UUID NOT NULL,
                    away_team_id UUID NOT NULL,
                    scheduled_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    home_score SMALLINT,
                    away_score SMALLINT,
                    played BOOLEAN NOT NULL DEFAULT FALSE,
                    cup_tie_id UUID,
                    is_extra_time BOOLEAN NOT NULL DEFAULT FALSE,
                    home_score_et SMALLINT,
                    away_score_et SMALLINT,
                    home_score_penalties SMALLINT,
                    away_score_penalties SMALLINT,
                    home_lineup JSON,
                    away_lineup JSON,
                    home_formation VARCHAR(10),
                    away_formation VARCHAR(10),
                    home_mentality VARCHAR(255),
                    away_mentality VARCHAR(255),
                    home_playing_style VARCHAR(255),
                    away_playing_style VARCHAR(255),
                    home_pressing VARCHAR(255),
                    away_pressing VARCHAR(255),
                    home_defensive_line VARCHAR(255),
                    away_defensive_line VARCHAR(255),
                    home_possession SMALLINT,
                    away_possession SMALLINT,
                    mvp_player_id UUID,
                    home_pitch_positions JSON,
                    away_pitch_positions JSON,
                    home_slot_assignments JSON,
                    away_slot_assignments JSON,
                    substitutions JSON,
                    PRIMARY KEY (id, game_id)
                ) PARTITION BY HASH (game_id)
            ");

            // --- Step 4: Create 64 hash partitions ---
            for ($i = 0; $i < self::PARTITION_COUNT; $i++) {
                DB::statement(
                    "CREATE TABLE game_matches_p{$i} PARTITION OF game_matches FOR VALUES WITH (MODULUS ".self::PARTITION_COUNT.", REMAINDER {$i})"
                );
            }

            // --- Step 5: Indexes ---
            DB::statement('CREATE INDEX game_matches_game_id_competition_id_round_number_index ON game_matches (game_id, competition_id, round_number)');
            DB::statement('CREATE INDEX game_matches_game_id_played_index ON game_matches (game_id, played)');
            DB::statement('CREATE INDEX game_matches_game_id_competition_id_played_index ON game_matches (game_id, competition_id, played)');
            DB::statement('CREATE INDEX game_matches_game_id_played_scheduled_date_index ON game_matches (game_id, played, scheduled_date)');
            DB::statement('CREATE INDEX game_matches_mvp_player_id_index ON game_matches (mvp_player_id)');
            DB::statement('CREATE INDEX game_matches_cup_tie_id_index ON game_matches (cup_tie_id)');

            // --- Step 6: FK constraints FROM game_matches ---
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_game_id_foreign FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_competition_id_foreign FOREIGN KEY (competition_id) REFERENCES competitions(id)');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_home_team_id_foreign FOREIGN KEY (home_team_id) REFERENCES teams(id)');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_away_team_id_foreign FOREIGN KEY (away_team_id) REFERENCES teams(id)');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_cup_tie_id_foreign FOREIGN KEY (cup_tie_id) REFERENCES cup_ties(id) ON DELETE SET NULL');

            // --- Step 7: Migrate data and verify row count ---
            // Use explicit column list because column order may differ between old and new tables
            $columns = 'id, game_id, competition_id, round_number, round_name, home_team_id, away_team_id, scheduled_date, home_score, away_score, played, cup_tie_id, is_extra_time, home_score_et, away_score_et, home_score_penalties, away_score_penalties, home_lineup, away_lineup, home_formation, away_formation, home_mentality, away_mentality, home_playing_style, away_playing_style, home_pressing, away_pressing, home_defensive_line, away_defensive_line, home_possession, away_possession, mvp_player_id, home_pitch_positions, away_pitch_positions, home_slot_assignments, away_slot_assignments, substitutions';
            DB::statement("INSERT INTO game_matches ({$columns}) SELECT {$columns} FROM game_matches_old");

            $actualCount = DB::table('game_matches')->count();
            if ($actualCount !== $expectedCount) {
                throw new \RuntimeException(
                    "Row count mismatch after migration: expected {$expectedCount}, got {$actualCount}. Rolling back."
                );
            }

            // --- Step 8: Drop old table ---
            Schema::drop('game_matches_old');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::transaction(function () {
            $hasData = DB::table('game_matches')->exists();

            if ($hasData) {
                DB::statement('CREATE TABLE game_matches_backup AS SELECT * FROM game_matches');
            }

            // Drop partitioned table (cascades all partitions)
            Schema::dropIfExists('game_matches');

            // Recreate as regular table
            DB::statement("
                CREATE TABLE game_matches (
                    id UUID NOT NULL PRIMARY KEY,
                    game_id UUID NOT NULL,
                    competition_id VARCHAR(10) NOT NULL,
                    round_number SMALLINT NOT NULL,
                    round_name VARCHAR(255),
                    home_team_id UUID NOT NULL,
                    away_team_id UUID NOT NULL,
                    scheduled_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    home_score SMALLINT,
                    away_score SMALLINT,
                    played BOOLEAN NOT NULL DEFAULT FALSE,
                    cup_tie_id UUID,
                    is_extra_time BOOLEAN NOT NULL DEFAULT FALSE,
                    home_score_et SMALLINT,
                    away_score_et SMALLINT,
                    home_score_penalties SMALLINT,
                    away_score_penalties SMALLINT,
                    home_lineup JSON,
                    away_lineup JSON,
                    home_formation VARCHAR(10),
                    away_formation VARCHAR(10),
                    home_mentality VARCHAR(255),
                    away_mentality VARCHAR(255),
                    home_playing_style VARCHAR(255),
                    away_playing_style VARCHAR(255),
                    home_pressing VARCHAR(255),
                    away_pressing VARCHAR(255),
                    home_defensive_line VARCHAR(255),
                    away_defensive_line VARCHAR(255),
                    home_possession SMALLINT,
                    away_possession SMALLINT,
                    mvp_player_id UUID,
                    home_pitch_positions JSON,
                    away_pitch_positions JSON,
                    home_slot_assignments JSON,
                    away_slot_assignments JSON,
                    substitutions JSON
                )
            ");

            // Restore indexes
            DB::statement('CREATE INDEX game_matches_game_id_competition_id_round_number_index ON game_matches (game_id, competition_id, round_number)');
            DB::statement('CREATE INDEX game_matches_game_id_played_index ON game_matches (game_id, played)');
            DB::statement('CREATE INDEX game_matches_game_id_competition_id_played_index ON game_matches (game_id, competition_id, played)');
            DB::statement('CREATE INDEX game_matches_game_id_played_scheduled_date_index ON game_matches (game_id, played, scheduled_date)');
            DB::statement('CREATE INDEX game_matches_mvp_player_id_index ON game_matches (mvp_player_id)');
            DB::statement('CREATE INDEX game_matches_cup_tie_id_index ON game_matches (cup_tie_id)');

            // Restore FK constraints FROM game_matches
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_game_id_foreign FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_competition_id_foreign FOREIGN KEY (competition_id) REFERENCES competitions(id)');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_home_team_id_foreign FOREIGN KEY (home_team_id) REFERENCES teams(id)');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_away_team_id_foreign FOREIGN KEY (away_team_id) REFERENCES teams(id)');
            DB::statement('ALTER TABLE game_matches ADD CONSTRAINT game_matches_cup_tie_id_foreign FOREIGN KEY (cup_tie_id) REFERENCES cup_ties(id) ON DELETE SET NULL');

            // Restore data
            if ($hasData) {
                DB::statement('INSERT INTO game_matches SELECT * FROM game_matches_backup');
                Schema::drop('game_matches_backup');
            }

            // Restore inbound FK
            Schema::table('match_events', fn ($t) => $t->foreign('game_match_id')->references('id')->on('game_matches')->cascadeOnDelete());

            // Restore mvp FK (dropped by game_players partition migration, restored here)
            Schema::table('game_matches', fn ($t) => $t->foreign('mvp_player_id')->references('id')->on('game_players')->nullOnDelete());
        });
    }
};
