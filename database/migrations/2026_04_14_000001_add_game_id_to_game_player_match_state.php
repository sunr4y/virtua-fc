<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 1: Add nullable column
        Schema::table('game_player_match_state', function (Blueprint $table) {
            $table->uuid('game_id')->nullable()->after('game_player_id');
        });

        // Phase 2: Backfill from game_players
        DB::statement(<<<'SQL'
            UPDATE game_player_match_state
            SET game_id = gp.game_id
            FROM game_players gp
            WHERE game_player_match_state.game_player_id = gp.id
        SQL);

        // Phase 3: Add NOT NULL constraint, FK, and composite index
        Schema::table('game_player_match_state', function (Blueprint $table) {
            $table->uuid('game_id')->nullable(false)->change();
            $table->foreign('game_id')->references('id')->on('games')->onDelete('cascade');
            $table->index(['game_id', 'game_player_id'], 'gpms_game_id_game_player_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('game_player_match_state', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropIndex('gpms_game_id_game_player_id_index');
            $table->dropColumn('game_id');
        });
    }
};
