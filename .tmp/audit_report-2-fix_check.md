# Fix Check Report (Round 1)

- Source baseline: `.tmp/audit_report-2.md`
- Method: static-only verification (no runtime, no Docker, no tests executed)
- Scope: verify whether each previously reported issue is fixed in current code

## Overall Result
- Fixed: 4/5
- Partially Fixed: 0/5
- Not Fixed: 1/5

## Issue-by-Issue Status

### Issue 1 (High)
- Title: Audit log immutability is bypassable through query-builder updates
- Previous status: Fail
- Current status: **Fixed (static evidence)**
- Evidence:
  - DB-level immutability triggers were added:
    - `repo/backend/database/migrations/2024_01_01_000042_add_audit_events_immutability_triggers.php:23` (`CREATE TRIGGER prevent_audit_event_update`)
    - `repo/backend/database/migrations/2024_01_01_000042_add_audit_events_immutability_triggers.php:24` (`BEFORE UPDATE ON audit_events`)
    - `repo/backend/database/migrations/2024_01_01_000042_add_audit_events_immutability_triggers.php:33` (`CREATE TRIGGER prevent_audit_event_delete`)
    - `repo/backend/database/migrations/2024_01_01_000042_add_audit_events_immutability_triggers.php:34` (`BEFORE DELETE ON audit_events`)
  - Application-layer guard still present (defense in depth):
    - `repo/backend/app/Models/AuditEvent.php:59` (`public function save(...)` throws on update path)
- Notes:
  - This closes the prior bypass class at DB level by design. Runtime enforcement remains a deployment-time verification item.

### Issue 2 (Medium)
- Title: Backup job creation write is not explicitly audited at create step
- Previous status: Partial Fail
- Current status: **Fixed**
- Evidence:
  - Backup creation point exists:
    - `repo/backend/app/Application/Backup/BackupMetadataService.php:33` (`BackupJob::create([...])`)
  - Create audit emission now exists in the same flow:
    - `repo/backend/app/Application/Backup/BackupMetadataService.php:44` (`action: AuditAction::Create`)

### Issue 3 (Medium)
- Title: Admin user creation enforces required email despite username+password-only auth prompt
- Previous status: Partial Fail
- Current status: **Fixed**
- Evidence:
  - Email validation is optional (`nullable`):
    - `repo/backend/app/Http/Requests/Admin/StoreAdminUserRequest.php:29`
      (`'email' => ['nullable', 'email', 'max:255', 'unique:users,email']`)

### Issue 4 (Medium)
- Title: Manual backup trigger response can return a stale/unrelated “latest manual” job
- Previous status: Partial Fail
- Current status: **Fixed**
- Evidence:
  - Controller creates deterministic job first and uses returned ID:
    - `repo/backend/app/Http/Controllers/Api/Admin/BackupController.php:79` (`$job = $this->metadata->startBackup(true);`)
    - `repo/backend/app/Http/Controllers/Api/Admin/BackupController.php:81` (`RunBackupJob::dispatch(true, $job->id);`)
    - `repo/backend/app/Http/Controllers/Api/Admin/BackupController.php:83` (`return ... jobShape($job) ...`)

### Issue 5 (Low)
- Title: Canary 10% cap uses floor, producing zero allowable targets for small eligible populations
- Previous status: Suspected Risk
- Current status: **Fixed**
- Evidence:
  - Small-population guard now ensures at least one target when eligible count > 0:
    - `repo/backend/app/Domain/Configuration/ValueObjects/CanaryConstraint.php:56`
      (`return max(1, (int) floor(...));`)

## Remaining Risk / Follow-up
- No remaining open issue from the five-item baseline in `.tmp/audit_report-2.md` based on static code evidence.
- Runtime-only confirmation still out of scope in this round (e.g., migration application state and trigger behavior in a live DB).
