<?php

namespace App\Support\TeamColors;

interface TeamColorProvider
{
    /** @return array<string, array{pattern: string, primary: string, secondary: string, number: string}> */
    public static function teams(): array;
}
