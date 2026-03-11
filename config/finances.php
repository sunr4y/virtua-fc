<?php

return [
    // Annual operating expenses by reputation level (in cents)
    // Covers: non-playing staff, admin, travel, insurance, legal, etc.
    'operating_expenses' => [
        'elite'        => 12_000_000_000, // €120M
        'continental'  =>  7_000_000_000, // €70M
        'established'  =>  3_500_000_000, // €35M
        'modest'       =>  1_800_000_000, // €18M
        'local'        =>    750_000_000, // €7.5M
    ],

    // Commercial revenue per seat per season by reputation level (in cents).
    'commercial_per_seat' => [
        'elite'        => 120_000, // €1,200/seat
        'continental'  =>  65_000, // €650/seat
        'established'  =>  45_000, // €450/seat
        'modest'       =>  35_000, // €350/seat
        'local'        =>  18_000, // €180/seat
    ],

    // Matchday revenue per seat per season by reputation level (in cents).
    'revenue_per_seat' => [
        'elite'        => 60_000, // €600/seat
        'continental'  => 38_000, // €380/seat
        'established'  => 27_000, // €270/seat
        'modest'       => 18_000, // €180/seat
        'local'        =>  8_000, // €80/seat
    ],

    // Operating expense multiplier by competition tier.
    // Tier 1 (La Liga) = full cost, Tier 2 (Segunda) = reduced.
    'operating_expense_tier_multiplier' => [
        1 => 1.0,   // La Liga: full operating expenses
        2 => 0.70,  // Segunda: 70% of base operating expenses
    ],

    // Position-based commercial revenue growth multipliers.
    // Key = max position (inclusive), value = multiplier applied to projected commercial revenue.
    'commercial_growth' => [
        4  => 1.05,  // 1st-4th: +5%
        8  => 1.02,  // 5th-8th: +2%
        14 => 1.00,  // 9th-14th: flat
        17 => 0.97,  // 15th-17th: -3%
        20 => 0.93,  // 18th-20th: -7%
    ],
];
