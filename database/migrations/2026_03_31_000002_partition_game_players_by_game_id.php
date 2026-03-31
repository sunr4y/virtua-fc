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

        // --- Step 1: Drop all FK constraints pointing TO game_players ---
        Schema::table('match_events', fn ($t) => $t->dropForeign('match_events_game_player_id_foreign'));
        Schema::table('transfer_offers', fn ($t) => $t->dropForeign('transfer_offers_game_player_id_foreign'));
        Schema::table('loans', fn ($t) => $t->dropForeign('loans_game_player_id_foreign'));
        Schema::table('renewal_negotiations', fn ($t) => $t->dropForeign('renewal_negotiations_game_player_id_foreign'));
        Schema::table('player_suspensions', fn ($t) => $t->dropForeign('player_suspensions_game_player_id_foreign'));
        Schema::table('shortlisted_players', fn ($t) => $t->dropForeign('shortlisted_players_game_player_id_foreign'));
        Schema::table('game_transfers', fn ($t) => $t->dropForeign('game_transfers_game_player_id_foreign'));
        Schema::table('game_matches', fn ($t) => $t->dropForeign(['mvp_player_id']));

        // --- Step 2: Rename existing table ---
        Schema::rename('game_players', 'game_players_old');

        // --- Step 3: Create partitioned table ---
        DB::statement("
            CREATE TABLE game_players (
                id UUID NOT NULL,
                game_id UUID NOT NULL,
                player_id UUID NOT NULL,
                team_id UUID,
                number SMALLINT,
                position VARCHAR(255) NOT NULL,
                market_value VARCHAR(255),
                market_value_cents BIGINT NOT NULL DEFAULT 0,
                contract_until DATE,
                annual_wage BIGINT NOT NULL DEFAULT 0,
                pending_annual_wage BIGINT,
                fitness SMALLINT NOT NULL DEFAULT 100,
                morale SMALLINT NOT NULL DEFAULT 70,
                durability SMALLINT NOT NULL DEFAULT 50,
                injury_until DATE,
                injury_type VARCHAR(255),
                suspended_until_matchday INTEGER,
                appearances SMALLINT NOT NULL DEFAULT 0,
                goals SMALLINT NOT NULL DEFAULT 0,
                own_goals SMALLINT NOT NULL DEFAULT 0,
                assists SMALLINT NOT NULL DEFAULT 0,
                yellow_cards SMALLINT NOT NULL DEFAULT 0,
                red_cards SMALLINT NOT NULL DEFAULT 0,
                goals_conceded INTEGER NOT NULL DEFAULT 0,
                clean_sheets INTEGER NOT NULL DEFAULT 0,
                game_technical_ability SMALLINT,
                game_physical_ability SMALLINT,
                tier SMALLINT NOT NULL DEFAULT 1,
                potential SMALLINT,
                potential_low SMALLINT,
                potential_high SMALLINT,
                season_appearances SMALLINT NOT NULL DEFAULT 0,
                transfer_status VARCHAR(255),
                transfer_listed_at TIMESTAMP(0) WITHOUT TIME ZONE,
                retiring_at_season VARCHAR(255),
                PRIMARY KEY (id, game_id)
            ) PARTITION BY HASH (game_id)
        ");

        // --- Step 4: Create 64 hash partitions ---
        for ($i = 0; $i < self::PARTITION_COUNT; $i++) {
            DB::statement(
                "CREATE TABLE game_players_p{$i} PARTITION OF game_players FOR VALUES WITH (MODULUS ".self::PARTITION_COUNT.", REMAINDER {$i})"
            );
        }

        // --- Step 5: Indexes ---
        DB::statement('CREATE UNIQUE INDEX game_players_game_id_player_id_unique ON game_players (game_id, player_id)');
        DB::statement('CREATE INDEX game_players_game_id_team_id_index ON game_players (game_id, team_id)');
        DB::statement('CREATE INDEX game_players_game_id_team_id_position_index ON game_players (game_id, team_id, position)');
        DB::statement('CREATE INDEX game_players_game_id_tier_index ON game_players (game_id, tier)');
        DB::statement('CREATE INDEX game_players_player_id_index ON game_players (player_id)');
        DB::statement('CREATE UNIQUE INDEX game_players_squad_number_unique ON game_players (game_id, team_id, number)');
        DB::statement('CREATE INDEX game_players_game_id_team_id_transfer_status_index ON game_players (game_id, team_id, transfer_status)');

        // --- Step 6: FK constraints FROM game_players ---
        DB::statement('ALTER TABLE game_players ADD CONSTRAINT game_players_game_id_foreign FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE game_players ADD CONSTRAINT game_players_player_id_foreign FOREIGN KEY (player_id) REFERENCES players(id)');
        DB::statement('ALTER TABLE game_players ADD CONSTRAINT game_players_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams(id)');

        // --- Step 7: Migrate data ---
        DB::statement('INSERT INTO game_players SELECT * FROM game_players_old');

        // --- Step 8: Drop old table ---
        Schema::drop('game_players_old');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // --- Collect existing data before dropping partitioned table ---
        $hasData = DB::table('game_players')->exists();

        if ($hasData) {
            DB::statement('CREATE TABLE game_players_backup AS SELECT * FROM game_players');
        }

        // --- Drop partitioned table (cascades all partitions) ---
        Schema::dropIfExists('game_players');

        // --- Recreate as regular table ---
        DB::statement("
            CREATE TABLE game_players (
                id UUID NOT NULL PRIMARY KEY,
                game_id UUID NOT NULL,
                player_id UUID NOT NULL,
                team_id UUID,
                number SMALLINT,
                position VARCHAR(255) NOT NULL,
                market_value VARCHAR(255),
                market_value_cents BIGINT NOT NULL DEFAULT 0,
                contract_until DATE,
                annual_wage BIGINT NOT NULL DEFAULT 0,
                pending_annual_wage BIGINT,
                fitness SMALLINT NOT NULL DEFAULT 100,
                morale SMALLINT NOT NULL DEFAULT 70,
                durability SMALLINT NOT NULL DEFAULT 50,
                injury_until DATE,
                injury_type VARCHAR(255),
                suspended_until_matchday INTEGER,
                appearances SMALLINT NOT NULL DEFAULT 0,
                goals SMALLINT NOT NULL DEFAULT 0,
                own_goals SMALLINT NOT NULL DEFAULT 0,
                assists SMALLINT NOT NULL DEFAULT 0,
                yellow_cards SMALLINT NOT NULL DEFAULT 0,
                red_cards SMALLINT NOT NULL DEFAULT 0,
                goals_conceded INTEGER NOT NULL DEFAULT 0,
                clean_sheets INTEGER NOT NULL DEFAULT 0,
                game_technical_ability SMALLINT,
                game_physical_ability SMALLINT,
                tier SMALLINT NOT NULL DEFAULT 1,
                potential SMALLINT,
                potential_low SMALLINT,
                potential_high SMALLINT,
                season_appearances SMALLINT NOT NULL DEFAULT 0,
                transfer_status VARCHAR(255),
                transfer_listed_at TIMESTAMP(0) WITHOUT TIME ZONE,
                retiring_at_season VARCHAR(255)
            )
        ");

        // --- Restore indexes ---
        DB::statement('CREATE UNIQUE INDEX game_players_game_id_player_id_unique ON game_players (game_id, player_id)');
        DB::statement('CREATE INDEX game_players_game_id_team_id_index ON game_players (game_id, team_id)');
        DB::statement('CREATE INDEX game_players_game_id_team_id_position_index ON game_players (game_id, team_id, position)');
        DB::statement('CREATE INDEX game_players_game_id_tier_index ON game_players (game_id, tier)');
        DB::statement('CREATE INDEX game_players_player_id_index ON game_players (player_id)');
        DB::statement('CREATE UNIQUE INDEX game_players_squad_number_unique ON game_players (game_id, team_id, number)');
        DB::statement('CREATE INDEX game_players_game_id_team_id_transfer_status_index ON game_players (game_id, team_id, transfer_status)');

        // --- Restore FK constraints FROM game_players ---
        DB::statement('ALTER TABLE game_players ADD CONSTRAINT game_players_game_id_foreign FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE game_players ADD CONSTRAINT game_players_player_id_foreign FOREIGN KEY (player_id) REFERENCES players(id)');
        DB::statement('ALTER TABLE game_players ADD CONSTRAINT game_players_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams(id)');

        // --- Restore data ---
        if ($hasData) {
            DB::statement('INSERT INTO game_players SELECT * FROM game_players_backup');
            Schema::drop('game_players_backup');
        }

        // --- Restore FK constraints TO game_players ---
        Schema::table('match_events', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('transfer_offers', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('loans', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('renewal_negotiations', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('player_suspensions', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('shortlisted_players', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('game_transfers', fn ($t) => $t->foreign('game_player_id')->references('id')->on('game_players')->cascadeOnDelete());
        Schema::table('game_matches', fn ($t) => $t->foreign('mvp_player_id')->references('id')->on('game_players')->nullOnDelete());
    }
};
