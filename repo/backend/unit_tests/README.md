# Unit Tests — Meridian Backend

This directory contains non-HTTP unit and domain tests for the Meridian backend.

**Test runner:** Pest 2.x
**Suite name:** `Unit` (defined in `phpunit.xml`)

## What Goes Here

- Domain value object tests (PasswordPolicy, FileConstraints, CanaryConstraint, etc.)
- Domain enum tests (state machine rules, valid/invalid values)
- Application service logic tests (SLA calculation, idempotency key hashing, restock fee calculation)
- Infrastructure helper tests (fingerprint computation, encryption round-trips where safe)

## What Does NOT Go Here

- HTTP-level tests — those go in `api_tests/`
- Tests that require a database — those go in `api_tests/` using `RefreshDatabase`
- Integration tests involving multiple external services

## Running Tests

```bash
# From repo/ directory
docker compose --profile test exec backend-test php artisan test --testsuite=Unit

# Or via run_tests.sh
./run_tests.sh
```

## Directory Layout

```
unit_tests/
└── Domain/
    ├── Auth/
    ├── Attachment/
    ├── Configuration/
    ├── Workflow/
    ├── Sales/
    └── Audit/
```
