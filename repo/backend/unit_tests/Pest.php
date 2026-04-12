<?php

/*
|--------------------------------------------------------------------------
| Pest Bootstrap — Unit Tests
|--------------------------------------------------------------------------
| This file configures Pest for the Unit test suite.
| Unit tests do not boot the full Laravel application.
*/

pest()->extend(Tests\TestCase::class)->in('Domain', 'Application', 'Infrastructure');
