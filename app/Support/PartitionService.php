<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class PartitionService
{
    /**
     * Create a new LIST partition for the given game_id.
     *
     * The partition table is named {table}_part_{first 8 chars of UUID}.
     */
    public static function createPartition(string $table, string $gameId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $partitionName = self::partitionName($table, $gameId);

        DB::statement(
            "CREATE TABLE IF NOT EXISTS {$partitionName} PARTITION OF {$table} FOR VALUES IN (?)",
            [$gameId],
        );
    }

    /**
     * Drop the partition for the given game_id (e.g. when deleting a game).
     */
    public static function dropPartition(string $table, string $gameId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $partitionName = self::partitionName($table, $gameId);

        DB::statement("DROP TABLE IF EXISTS {$partitionName}");
    }

    /**
     * Generate a deterministic partition table name from game_id.
     */
    public static function partitionName(string $table, string $gameId): string
    {
        $shortId = str_replace('-', '', substr($gameId, 0, 8));

        return "{$table}_part_{$shortId}";
    }
}
