<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
| Pest auto-loads this file from tests/Pest.php.
| Our test suites live in custom directories (unit_tests and api_tests),
| so we wire them here explicitly.
*/

uses(Tests\TestCase::class)
    ->in(__DIR__.'/../unit_tests');

uses(Tests\TestCase::class, RefreshDatabase::class)
    ->in(__DIR__.'/../api_tests');
