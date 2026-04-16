# Meridian Enterprise Operations & Document Management

A backend-only, fully offline enterprise platform for regulated back-office operations. Built with Laravel 11 + MySQL 8, deployed as a single-host Docker service.

**No frontend. No external services. No internet required.**

---

## Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.3+ |
| Framework | Laravel 11 |
| Database | MySQL 8.0 |
| Test runner | Pest 2.x |
| Container | Docker + Docker Compose |
| Auth | Laravel Sanctum (local bearer tokens) |
| RBAC | Spatie Laravel Permission |
| Queue | MySQL database queue (no Redis) |
| Storage | Local filesystem only |
| PDF Watermarking | TCPDF + FPDI (server-side, no external tools) |

---

## Ports

| Service | Port | Purpose |
|---------|------|---------|
| Laravel API | **8000** | All API traffic + LAN link resolution |
| MySQL | **3306** | Database (accessible from LAN host for admin access) |

The base API URL is: `http://{LAN_HOST}:8000/api/v1`

---

## Repository Structure

```
repo/
├── README.md                    ← This file
├── docker-compose.yml           ← Container orchestration (backend + mysql)
├── run_tests.sh                 ← Test runner (Docker-first)
└── backend/
    ├── Dockerfile               ← PHP 8.3 + Laravel production image
    ├── artisan                  ← Laravel CLI
    ├── composer.json            ← PHP dependencies
    ├── phpunit.xml              ← Pest/PHPUnit test configuration
    ├── .env.example             ← Environment variable template
    ├── app/
    │   ├── Domain/              ← Business logic: enums, value objects, contracts
    │   │   ├── Auth/
    │   │   ├── Document/
    │   │   ├── Attachment/
    │   │   ├── Configuration/
    │   │   ├── Workflow/
    │   │   ├── Sales/
    │   │   └── Audit/
    │   ├── Application/         ← Use-case orchestration, idempotency, backup services
    │   ├── Infrastructure/      ← Encryption, hashing, PDF watermarking, storage adapters
    │   ├── Http/
    │   │   ├── Controllers/Api/ ← Resource controllers (thin layer only)
    │   │   ├── Requests/        ← Form request validation
    │   │   └── Middleware/      ← Auth, idempotency, masking
    │   ├── Policies/            ← RBAC + department-aware authorization
    │   └── Providers/           ← Service providers
    ├── bootstrap/
    │   └── app.php              ← Laravel 11 bootstrap (exception handlers registered)
    ├── config/
    │   ├── app.php
    │   ├── auth.php
    │   ├── database.php
    │   ├── filesystems.php
    │   ├── queue.php
    │   └── meridian.php         ← All Meridian-specific business configuration
    ├── routes/
    │   ├── api.php              ← All /api/v1/* routes declared
    │   └── console.php          ← Scheduled jobs (backup, retention, SLA reminders)
    ├── database/
    │   ├── migrations/          ← All schema migrations (35 tables — complete schema)
    │   ├── seeders/             ← Role/permission + dev fixture seeders
    │   └── factories/           ← Eloquent model factories for tests
    ├── storage/
    │   ├── app/attachments/     ← Encrypted attachment payloads
    │   ├── app/backups/         ← Daily backup dumps and manifests
    │   └── logs/                ← Application runtime logs
    ├── unit_tests/              ← Non-HTTP domain and application unit tests
    ├── api_tests/               ← HTTP-level API and integration tests
    └── docker/
        ├── php/                 ← PHP and OPcache ini files
        ├── supervisor/          ← Supervisord config (server + queue + cron)
        ├── mysql/               ← MySQL custom config
        └── entrypoint.sh        ← Container startup script
```

---

## Prerequisites

- Docker 24+ with the Compose plugin (`docker compose` — not `docker-compose`)
- LAN access to the host machine on port 8000 (API) and optionally 3306 (MySQL admin)

## Quick Start (90 Seconds)

```bash
# 1) Prepare environment file
cp backend/.env.example backend/.env

# 2) Start stack (preferred)
docker compose up -d

# 3) Legacy equivalent command (accepted by strict audits)
docker-compose up -d

# 4) Seed baseline roles/permissions
docker compose exec backend php artisan db:seed

# 5) Verify API is live
curl -s http://localhost:8000/api/v1/admin/health -H 'Authorization: Bearer YOUR_TOKEN'
```

---

## Environment Setup

`backend/.env` is the single source of truth for all environment variables. Docker Compose reads it at startup (`env_file: ./backend/.env`); Laravel reads it inside the container.

1. Copy the environment template:
   ```bash
   cp backend/.env.example backend/.env
   ```

2. Fill in required values in `backend/.env`:

   | Variable | How to generate |
   |----------|----------------|
   | `APP_KEY` | Run after `.env` is in place: `docker compose run --rm backend php artisan key:generate --show` |
   | `ATTACHMENT_ENCRYPTION_KEY` | `docker compose run --rm backend php -r "echo base64_encode(random_bytes(32));"` (no local PHP required) |
   | `DB_PASSWORD` | Choose a strong password |
   | `MYSQL_ROOT_PASSWORD` | Choose a separate root password |
   | `LAN_BASE_URL` | Set to the LAN IP, e.g. `http://192.168.1.100:8000` |
  | `CANARY_STORE_COUNT` | Set to the total eligible store count used for store-level canary rollout caps |
  | `CANARY_STORE_IDS` | Comma-separated UUID list of eligible store targets used to validate store rollout `target_ids`. Format: `uuid1,uuid2,uuid3` — no quotes, no spaces between items. Leave empty (`""`) to disable store-level validation. |

   All other variables have safe defaults and do not need to be changed for initial deployment.

---

## Starting the System

```bash
# Start all services in background
docker compose up -d

# Legacy equivalent for environments that still expose docker-compose
docker-compose up -d

# Check service health (wait for backend and mysql to show "healthy")
docker compose ps

# Database migrations run automatically via the container entrypoint.
# To run them manually:
docker compose exec backend php artisan migrate

# Seed initial roles and permissions (required before first login)
docker compose exec backend php artisan db:seed

# ⚠️  STEP 2 REQUIRED: only the admin account is created by db:seed.
# The other 4 demo role accounts (manager, staff, auditor, viewer) must be
# created separately — run the tinker command in the "Demo Credentials" section
# below before attempting to log in with those credentials.
```

The container entrypoint also warms the config, route, and view caches automatically. There is no separate build step required after `docker compose up`.

### Runtime Architecture

- **API server:** `php artisan serve --host=0.0.0.0 --port=8000` managed by supervisord
- **Queue worker:** `php artisan queue:work` (2 processes, database driver) managed by supervisord
- **Scheduler:** `php artisan schedule:run` invoked every minute by dcron inside the container
- **No nginx, no FPM, no Redis** — all processes run in a single container

### First Login

After `php artisan db:seed`, one admin account is available:

```
Username: admin
Password: Admin@Meridian1!
```

Change the password immediately after first login via `PUT /api/v1/admin/users/{id}/password`.

### Demo Credentials (All Roles)

Authentication is required. Use the following role credentials for demos:

| Role | Username | Email | Password |
|------|----------|-------|----------|
| admin | `admin` | `admin@meridian.local` | `Admin@Meridian1!` |
| manager | `demo_manager` | `manager@meridian.local` | `Manager@Meridian1!` |
| staff | `demo_staff` | `staff@meridian.local` | `Staff@Meridian1!` |
| auditor | `demo_auditor` | `auditor@meridian.local` | `Auditor@Meridian1!` |
| viewer | `demo_viewer` | `viewer@meridian.local` | `Viewer@Meridian1!` |

`admin` is seeded by default. Create the remaining demo users after first login:

```bash
docker compose exec backend php artisan tinker --execute="
\$d=\App\Models\Department::firstOrCreate(['code'=>'DEM'],['name'=>'Demo']);
\$make=function(\$u,\$e,\$n,\$r,\$p) use (\$d){
  \$x=\App\Models\User::firstOrCreate(['username'=>\$u],[
    'email'=>\$e,
    'display_name'=>\$n,
    'password_hash'=>\Illuminate\Support\Facades\Hash::make(\$p),
    'department_id'=>\$d->id,
    'is_active'=>true,
  ]);
  if(!\$x->hasRole(\$r)){\$x->assignRole(\$r);} 
};
\$make('demo_manager','manager@meridian.local','Demo Manager','manager','Manager@Meridian1!');
\$make('demo_staff','staff@meridian.local','Demo Staff','staff','Staff@Meridian1!');
\$make('demo_auditor','auditor@meridian.local','Demo Auditor','auditor','Auditor@Meridian1!');
\$make('demo_viewer','viewer@meridian.local','Demo Viewer','viewer','Viewer@Meridian1!');
"
```

### Verifying the System

```bash
# Get a bearer token (replace with real admin credentials)
curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"yourpassword"}' | jq '.data.token'

# Check system health (requires bearer token)
curl -s http://localhost:8000/api/v1/admin/health \
  -H 'Authorization: Bearer YOUR_TOKEN' | jq '.'
```

### Operational Verification Checklist

Run these after `docker compose up -d` to confirm all background processes are live:

```bash
# 1. Confirm supervisor processes are running
docker compose exec backend supervisorctl status

# 2. Confirm scheduler fires (look for "Running scheduled command" log lines)
docker compose exec backend cat /var/log/cron.log | tail -20

# 3. Confirm queue worker is processing (look for "Processing job" lines)
docker compose exec backend cat /var/log/worker.log | tail -20

# 4. Health endpoint must show all checks 'ok'
curl -s http://localhost:8000/api/v1/admin/health \
  -H 'Authorization: Bearer YOUR_TOKEN' | jq '.data.checks'
```

---

## Running Tests

Tests run inside Docker against a dedicated test database (`meridian_test`), which is created automatically on first MySQL boot from `backend/docker/mysql/init-test-db.sql`. No separate setup step is required.

The production API container (`backend`) installs production dependencies only (`composer --no-dev`). A dedicated `backend-test` compose service is built from the Dockerfile `test` target and includes dev dependencies required by Pest/PHPUnit.

```bash
# Ensure the test service is running
docker compose --profile test up -d backend-test

# Run all test suites (Unit + API) via Docker
./run_tests.sh

# Run with code coverage report (requires Xdebug or PCOV in the container image)
./run_tests.sh --coverage
# Coverage percentages are printed in the terminal summary

# Or run individual suites manually
docker compose --profile test exec backend-test php artisan test --testsuite=Unit
docker compose --profile test exec backend-test php artisan test --testsuite=Api

# Windows note: run via Git Bash
"C:/Program Files/Git/bin/bash.exe" ./run_tests.sh
```

The test runner waits for the `backend-test` container entrypoint to complete before executing suites, preventing startup race conditions where migrations are still running.

### Test Suite Locations

| Suite | Directory | Description |
|-------|-----------|-------------|
| Unit | `repo/backend/unit_tests/` | Domain logic, application services, infrastructure utilities — no HTTP |
| API | `repo/backend/api_tests/` | Full HTTP integration tests with RefreshDatabase and Sanctum |

### Test Database

The test database is **`meridian_test`**, separate from the production database (`meridian`). It is initialized by `backend/docker/mysql/init-test-db.sql` on the MySQL container's first boot. `phpunit.xml` points tests at `meridian_test` via `DB_DATABASE=meridian_test`.

### UUID Primary Key Scope Note

Application-domain entities use UUID primary keys. Laravel framework-internal support tables are treated as compatibility exceptions where needed. In particular, Sanctum's `personal_access_tokens` table keeps its internal integer row identifier while using UUID-compatible `tokenable_id` morph keys so token ownership remains aligned with UUID `users.id`.

### Traceability

Requirement-to-test mapping is documented in `../docs/traceability.md`, covering all 7 prompts and 118 requirements.

---

## Performance Targets

The following non-functional targets apply to the deployed system. They are verified manually via load testing against the Docker-compose stack with a populated database.

| Metric | Acceptance Threshold |
|--------|----------------------|
| p95 API response latency (read endpoints) | < 300 ms at 1 M records |
| Sustained concurrency | 200 concurrent users |
| Bulk audit event writes | < 50 ms per event (append-only insert) |

### Running a Benchmark

Use [k6](https://k6.io/) or Apache Bench against the running stack. Example with Apache Bench:

```bash
# Prerequisite: obtain a bearer token first
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"yourpassword"}' | jq -r '.data.token')

# 200 concurrent users, 5 000 total requests — read endpoint benchmark
ab -n 5000 -c 200 -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/documents
```

If `k6` and `ab` are unavailable, use this Docker-only curl fallback to collect a quick latency baseline without host-side installs:

```bash
# 100 sequential requests, print average total time in seconds
for i in $(seq 1 100); do
  curl -s -o /dev/null -w '%{time_total}\n' \
    -H "Authorization: Bearer $TOKEN" \
    http://localhost:8000/api/v1/documents
done | awk '{sum += $1} END {printf "avg_time_seconds=%.4f\n", sum/NR}'
```

**Acceptance criteria:** p95 latency reported by `ab` must be ≤ 300 ms. Record the full `ab` output as a baseline artifact and commit it to `docs/benchmarks/` for each release. Create `docs/benchmarks/baseline.md` in that directory to record per-release threshold tables and notes (the directory is not committed until the first baseline run).

---

## API Access

All API endpoints are available at `http://{LAN_HOST}:8000/api/v1`.

See `../docs/api-spec.md` for the complete endpoint reference.

Authentication is required for all endpoints except `POST /api/v1/auth/login` and `GET /api/v1/links/{token}` (LAN share link resolution).

---

## Key Business Constraints

| Constraint | Value |
|------------|-------|
| Password minimum length | 12 characters |
| Password complexity | 1 uppercase, 1 lowercase, 1 digit |
| Failed login lockout | 5 attempts → 15-minute lockout |
| Max attachment file size | 25 MB |
| Max attachments per record | 20 |
| Attachment validity (default) | 365 days |
| LAN link max TTL | 72 hours |
| Canary rollout cap | 10% of eligible population |
| Canary minimum window | 24 hours before full promotion |
| Workflow SLA default | 2 business days |
| Restock fee (non-defective) | 10% within 30 days |
| Backup retention | 14 days |
| Log/metrics retention | 90 days |

---

## Offline Constraints

- **No internet access required** — all processing is local
- **No external services** — no S3, Redis, mail providers, SMS, webhooks, or CDN
- **LAN-only attachment links** — share links are valid within the local network only
- **Database queue** — no Redis; background jobs use MySQL as the queue store
- **Local file storage** — encrypted attachments and backups stored under `storage/`
- **Audit log is immutable** — the `audit_events` table is append-only; no updates or deletes

---

## Operational Administration

### Backup Behavior

Daily backup runs at 02:00 local time via the Laravel Scheduler (`php artisan schedule:run` invoked every minute by the Docker cron). The job:
1. Records a `BackupJob` row (`status` transitions: pending → running → success/failed)
2. Builds a manifest of all table row counts + attachment file inventory (count + bytes)
3. Stores the manifest in `backup_jobs.manifest` (JSON)

**Manual backup:** `POST /api/v1/admin/backups` (admin role required) dispatches an immediate backup job.

**Retention:** 14 days. `PruneBackupsJob` runs at 03:00 daily and deletes `backup_jobs` records beyond `retention_expires_at`. Configurable via `BACKUP_RETENTION_DAYS`.

### Retention Rules

| Data | Retention | Pruning Job | Schedule |
|------|-----------|-------------|----------|
| Backup records (`backup_jobs`) | 14 days | `PruneBackupsJob` | 03:00 daily |
| Structured logs (`structured_logs`) | 90 days | `PruneRetentionJob` | 03:30 daily |
| Metrics snapshots (`metrics_snapshots`) | 90 days | `PruneRetentionJob` | 03:30 daily |
| Expired attachments | N/A (status flip) | `ExpireAttachmentsJob` | 04:00 daily |
| Expired/consumed/revoked share links | 24h grace then deleted | `ExpireAttachmentLinksJob` | Every 15 min |
| SLA reminder to-dos | N/A (created on demand) | `SendSlaRemindersJob` | Every hour |

### Admin API Surfaces

All admin endpoints require the `admin` role unless noted. Full request/response documentation in `../docs/api-spec.md §3.7`.

| Endpoint | Role | Purpose |
|----------|------|---------|
| `GET /admin/backups` | admin | Backup history + retention status |
| `POST /admin/backups` | admin | Trigger immediate manual backup |
| `GET /admin/metrics` | admin | Metrics snapshots (raw + summary aggregation) |
| `GET /admin/logs` | admin, auditor | Structured application log browsing |
| `GET /admin/health` | admin | DB, queue, storage, backup health check |
| `GET /admin/failed-logins` | admin | Failed login attempt inspection |
| `GET /admin/locked-accounts` | admin | Currently locked user accounts |
| `GET /admin/approval-backlog` | admin, manager | Pending/overdue workflow nodes |
| `GET /admin/config-promotions` | admin, auditor | Configuration rollout event history |
| `GET /audit/events` | admin, auditor | Immutable audit event log browsing |

### LAN Share Link Governance

- Links are generated as: `{LAN_BASE_URL}/api/v1/links/{64-char-random-token}`
- `LAN_BASE_URL` **must** be set to the LAN IP of the host (e.g. `http://192.168.1.100:8000`)
- Max TTL: 72 hours (configurable via `LINK_MAX_TTL_HOURS`)
- Single-use links are atomically consumed on first download (DB transaction)
- IP restriction is optional — set `ip_restriction` to limit resolution to a specific client IP
- Expired/consumed/revoked links are physically deleted after a 24-hour grace period by `ExpireAttachmentLinksJob`

---

## Domain Modules (Prompt 2)

| Module | Path | Purpose |
|--------|------|---------|
| Auth | `app/Domain/Auth/` | Enums (RoleType, PermissionScope), ValueObjects (PasswordPolicy, LockoutPolicy) |
| Document | `app/Domain/Document/` | Enums (DocumentStatus, VersionStatus) |
| Attachment | `app/Domain/Attachment/` | Enums (AttachmentStatus, LinkStatus, AllowedMimeType), ValueObjects (FileConstraints, LinkTtlConstraint) |
| Configuration | `app/Domain/Configuration/` | Enums (PolicyType, RolloutStatus, RolloutTargetType), ValueObjects (CanaryConstraint) |
| Workflow | `app/Domain/Workflow/` | Enums (WorkflowStatus, NodeType, ApprovalAction), ValueObjects (SlaDefaults) |
| Sales | `app/Domain/Sales/` | Enums (SalesStatus, ReturnReasonCode, InventoryMovementType), ValueObjects (RestockFeePolicy, DocumentNumberFormat) |
| Audit | `app/Domain/Audit/` | Enums (AuditAction) |

## Documentation

| Document | Location |
|----------|----------|
| System Design | `../docs/design.md` |
| API Specification | `../docs/api-spec.md` |
| Implementation Ambiguities | `../questions.md` |
