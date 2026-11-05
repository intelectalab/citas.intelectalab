<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

function money(float|int|string|null $value): float
{
    return round((float) $value, 2);
}
