<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case for all Meridian tests.
 *
 * Unit tests: Extend this without RefreshDatabase.
 * API tests:  Extend via Pest.php with RefreshDatabase trait applied.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
