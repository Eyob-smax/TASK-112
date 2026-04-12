<?php

/*
|--------------------------------------------------------------------------
| Pest Bootstrap — API Tests
|--------------------------------------------------------------------------
| This file configures Pest for the API test suite.
| API tests boot the full Laravel application and use RefreshDatabase.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Auth', 'Authorization', 'Contract', 'Document', 'Attachment', 'Configuration', 'Idempotency', 'Workflow', 'Sales', 'Returns', 'Audit', 'Admin');
