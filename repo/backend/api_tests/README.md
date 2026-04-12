# API Tests — Meridian Backend

This directory contains HTTP-level API and multi-step integration tests for the Meridian backend.

**Test runner:** Pest 2.x with Laravel plugin
**Suite name:** `Api` (defined in `phpunit.xml`)

## What Goes Here

- Full HTTP endpoint tests (request → response lifecycle)
- Authentication and authorization boundary tests
- Idempotency behavior tests (same key → same response)
- State machine transition tests (document archive, sales lifecycle, approval flow)
- Multi-step integration tests (create → approve → complete)
- File upload validation tests (MIME spoofing, size limits, file count limits)
- Error envelope structure verification
- Security-sensitive flow tests (field masking, watermark logging, link consumption)

## What Does NOT Go Here

- Pure domain logic tests with no HTTP layer — those go in `unit_tests/`

## Running Tests

```bash
# From repo/ directory
docker compose --profile test exec backend-test php artisan test --testsuite=Api

# Or via run_tests.sh
./run_tests.sh
```

## Database

API tests use the `meridian_test` database and apply `RefreshDatabase` to reset state between tests.
The test database connection is configured in `phpunit.xml`.

## Directory Layout

```
api_tests/
├── Contract/           ← Error envelope, validation schema, idempotency header tests
├── Auth/               ← Login, logout, lockout, password complexity tests
├── Document/           ← Document CRUD, versioning, archive, download tests
├── Attachment/         ← Upload validation, link generation, link consumption tests
├── Configuration/      ← Config sets, versions, canary rollout, promotion tests
├── Workflow/           ← Template, instance, approval, rejection, SLA tests
├── Sales/              ← Sales lifecycle, document numbering, void, linkage tests
├── Returns/            ← Return creation, inventory rollback, restock fee tests
└── Audit/              ← Audit event immutability, idempotency deduplication tests
```
