<?php

namespace App\Modules\Player\Services;

/**
 * Static configuration for player development curves.
 *
 * Defines age-based development multipliers and match experience bonuses.
 * Values > 1.0 indicate growth, values < 1.0 indicate decline.
 */
final class DevelopmentCurve
{
    /**
     * Base development points per season (before multipliers).
     */
    public const BASE_DEVELOPMENT = 2;

    /**
     * Minimum appearances in a season to qualify for the starter bonus.
     */
    public const MIN_APPEARANCES_FOR_BONUS = 15;

    /**
     * Multiplier for players who meet the appearance threshold.
     * Represents +50% development bonus for regular starters.
     */
    public const APPEARANCE_BONUS = 1.5;

    /**
     * Age-based development multipliers for technical and physical abilities.
     *
     * - 16-21: High growth (multipliers > 1.1)
     * - 22-26: Moderate growth / plateau (multipliers 1.0-1.05)
     * - 27-30: Early decline (physical faster than technical)
     * - 31+: Veteran decline (accelerating)
     *
     * Reflects real-world football: technical peaks ~28-30 (experience),
     * physical peaks ~24-26 (athleticism), overall peak ~27-29.
     */
    public const AGE_CURVES = [
        16 => ['technical' => 1.4, 'physical' => 1.5],
        17 => ['technical' => 1.3, 'physical' => 1.4],
        18 => ['technical' => 1.2, 'physical' => 1.3],
        19 => ['technical' => 1.15, 'physical' => 1.2],
        20 => ['technical' => 1.1, 'physical' => 1.15],
        21 => ['technical' => 1.1, 'physical' => 1.1],
        22 => ['technical' => 1.05, 'physical' => 1.05],
        23 => ['technical' => 1.05, 'physical' => 1.0],
        24 => ['technical' => 1.0, 'physical' => 1.0],
        25 => ['technical' => 1.0, 'physical' => 1.0],
        26 => ['technical' => 1.0, 'physical' => 1.0],
        27 => ['technical' => 1.0, 'physical' => 0.95],
        28 => ['technical' => 1.0, 'physical' => 0.9],
        29 => ['technical' => 0.95, 'physical' => 0.85],
        30 => ['technical' => 0.95, 'physical' => 0.8],
        31 => ['technical' => 0.9, 'physical' => 0.7],
        32 => ['technical' => 0.85, 'physical' => 0.6],
        33 => ['technical' => 0.75, 'physical' => 0.45],
        34 => ['technical' => 0.65, 'physical' => 0.35],
    ];

    /**
     * Get the development multipliers for a given age.
     *
     * @return array{technical: float, physical: float}
     */
    public static function getMultipliers(int $age): array
    {
        // Clamp age to our defined range
        if ($age < 16) {
            return self::AGE_CURVES[16];
        }

        if ($age > 34) {
            return self::AGE_CURVES[34];
        }

        return self::AGE_CURVES[$age];
    }

    /**
     * Calculate development change for a single ability.
     *
     * @param float $multiplier The age-based multiplier for this ability
     * @param bool $hasAppearanceBonus Whether the player qualifies for the starter bonus
     * @return int The change in ability points (can be negative for decline)
     */
    public static function calculateChange(float $multiplier, bool $hasAppearanceBonus): int
    {
        // Base development adjusted by age multiplier
        $development = self::BASE_DEVELOPMENT * $multiplier;

        // Apply appearance bonus for players who play regularly
        if ($hasAppearanceBonus && $multiplier > 0) {
            $development *= self::APPEARANCE_BONUS;
        }

        // For declining players (multiplier < 1), convert to negative change
        // e.g., multiplier 0.6 means they develop at 60% rate, but since they're
        // past peak, this becomes decline
        if ($multiplier < 1.0) {
            // The further below 1.0, the more they decline
            // 0.8 = -0.4 points, 0.5 = -1.0 points, 0.2 = -1.6 points
            $declineRate = (1.0 - $multiplier) * self::BASE_DEVELOPMENT;
            return (int) round(-$declineRate);
        }

        return (int) round($development);
    }

    /**
     * Check if the player qualifies for the appearance bonus.
     */
    public static function qualifiesForBonus(int $seasonAppearances): bool
    {
        return $seasonAppearances >= self::MIN_APPEARANCES_FOR_BONUS;
    }
}
