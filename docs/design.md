# Meridian Enterprise Operations & Document Management — System Design

**Version:** 7.0 (Prompts 5/6/7 — Configuration, Workflow, Sales, Returns, Operational Resilience)
**Stack:** PHP 8.3 · Laravel 11 · MySQL 8 · Docker (single-host)
**Mode:** Fully offline — no external dependencies

---

## 1. System Overview

Meridian is a backend-only regulated enterprise platform exposing resource-based REST APIs for:

- Controlled document library management with versioning and archive enforcement
- Evidence/attachment handling with encryption, fingerprinting, and expiring LAN links
- Configurable operations governance with versioned canary rollouts
- Template-driven multi-level approval workflows with SLA tracking
- Auditable sales and return/exchange lifecycle management

All processing is local. No cloud services, OCR engines, online antivirus, or external authentication providers are used. Every write operation is captured in an immutable audit trail with idempotency protection.

---

## 2. Offline Runtime Model

```
┌──────────────────────────────────────────────────┐
│             Single Docker Host                   │
│                                                  │
│  ┌────────────┐      ┌───────────────────────┐  │
│  │  MySQL 8   │◄────►│  Laravel 11 Backend   │  │
│  │  port 3306 │      │  port 8000            │  │
│  └────────────┘      └───────────────────────┘  │
│                              │                   │
│                    ┌─────────▼──────────┐        │
│                    │  Local Filesystem  │        │
│                    │  /storage/app/     │        │
│                    │  - attachments/    │        │
│                    │  - backups/        │        │
│                    └────────────────────┘        │
└──────────────────────────────────────────────────┘
         ▲
         │ LAN clients reach backend via
         │ http://{LAN_HOST}:8000/api/v1
```

- **No reverse proxy** — clients connect directly to the Laravel application server
- **No internet egress** — all DNS, NTP (optional), and API calls are LAN-local
- **LAN link sharing** — expiring token-based attachment links generated for offline LAN distribution
- **Queue driver** — `database` queue (no Redis, no RabbitMQ)
- **File storage** — local disk only (`storage/app/`), no S3 or cloud storage
- **Backup targets** — local filesystem under `storage/app/backups/`

---

## 3. Laravel API Composition and Resource Boundaries

The backend follows a strict layered Domain-Driven Design structure:

```
repo/backend/
├── app/
│   ├── Domain/             # Entities, enums, value objects, domain services, contracts
│   │   ├── Auth/
│   │   ├── Document/
│   │   ├── Attachment/
│   │   ├── Configuration/
│   │   ├── Workflow/
│   │   ├── Sales/
│   │   └── Audit/
│   ├── Application/        # Use-case orchestrators, approval flows, backup, idempotency
│   │   ├── Auth/
│   │   ├── Document/
│   │   ├── Attachment/
│   │   ├── Configuration/
│   │   ├── Workflow/
│   │   ├── Sales/
│   │   └── Idempotency/
│   ├── Infrastructure/     # Encryption, hashing, PDF watermarking, storage adapters
│   │   ├── Encryption/
│   │   ├── Hashing/
│   │   ├── Storage/
│   │   ├── Watermark/
│   │   ├── Backup/
│   │   ├── Metrics/
│   │   └── Logging/
│   └── Http/
│       ├── Controllers/Api/ # Thin resource controllers only — no business logic
│       ├── Requests/        # Form request validation classes
│       └── Middleware/      # Auth, rate-limit, idempotency, masking
├── Policies/               # Laravel policies — RBAC + department-aware enforcement
├── Providers/              # AppServiceProvider, AuthServiceProvider, EventServiceProvider
├── routes/
│   └── api.php             # All API routes — versioned under /api/v1
└── database/
    ├── migrations/         # All schema definitions
    ├── seeders/            # Role/permission seeders + development fixtures
    └── factories/          # Eloquent factories for test data
```

### Layer Responsibilities

| Layer | Responsibility |
|-------|---------------|
| Domain | Pure business rules, enums, value objects, invariant enforcement. No framework dependencies. |
| Application | Orchestrate use cases, call domain services and repositories, emit audit events, handle idempotency. |
| Infrastructure | Technical concerns: AES-256 encryption, SHA-256 hashing, PDF watermark rendering, disk I/O, scheduled job dispatch. |
| HTTP | Validate inbound requests, authorize, delegate to application layer, return structured responses. No business logic. |

---

## 4. MySQL Persistence and Migration Strategy

- **All primary keys for prompt-listed business tables are UUIDs** (`char(36)` with `uuid('id')->primary()`).
- **Framework-internal support tables may keep compatibility IDs** (for example queue internals and Sanctum row IDs) while ownership links to business entities remain UUID-compatible.
- **Migrations are the sole source of schema truth** — no manual DDL
- **Enums are PHP-backed enums** (`string`-backed) cast via `AsEnum` in Eloquent models
- **Soft deletes** on user-facing records (documents, users, sales documents)
- **No raw SQL** — Eloquent query builder used throughout, parameterized bindings always

### Key Unique Constraints

| Column | Table | Purpose |
|--------|-------|---------|
| `username` | `users` | No duplicate accounts |
| `document_number` | `sales_documents` | Globally unique sales doc IDs |
| `(document_id, version_number)` | `document_versions` | No duplicate version numbers per document |
| `token` | `attachment_links` | Unique share tokens |
| `correlation_id` | `audit_events` | Idempotency deduplication |
| `key_hash` | `idempotency_keys` | Write-API deduplication |

### Entity Group Summary

| Group | Tables |
|-------|--------|
| Auth | `users`, `roles`, `departments`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `failed_login_attempts` |
| Documents | `documents`, `document_versions`, `document_download_records` |
| Attachments | `attachments`, `attachment_links` |
| Configuration | `configuration_sets`, `configuration_versions`, `canary_rollout_targets`, `configuration_rules` |
| Workflow | `workflow_templates`, `workflow_template_nodes`, `workflow_instances`, `workflow_nodes`, `approvals`, `to_do_items` |
| Sales | `document_number_sequences`, `sales_documents`, `sales_line_items`, `returns`, `inventory_movements` |
| Audit/Ops | `audit_events`, `idempotency_keys`, `backup_jobs`, `metrics_snapshots`, `structured_logs` |

---

## 5. Document and Attachment Workflows

### 5.1 Document Lifecycle State Machine

Documents follow a strict forward-only state machine enforced by `DocumentService` and `EloquentDocumentRepository`:

```
draft ──(createVersion)──► published ──(archive)──► archived (terminal)
  │                              │
  │ (archive also allowed        new versions allowed
  │  from draft)                 (each supersedes prior current version)
  └──────────────────────────────────────────────────────────────────►
```

- `draft` → `published`: Occurs automatically when the first version is uploaded via `POST /documents/{id}/versions`
- `published` → `archived`: Via `POST /documents/{id}/archive`. Terminal — no further transitions
- `archived` documents: Reject all update and version-upload attempts with HTTP 409 `document_archived`
- All update operations (`PUT /documents/{id}`) check `is_archived` at the service layer before executing

### 5.2 Document Version Supersession

Each call to `DocumentService::createVersion()` executes the following steps atomically inside a `DB::transaction`:

1. Compute `max(version_number)` for the document — defaults to 0 if no prior versions exist
2. Set all existing versions with `status = current` to `status = superseded`
3. Create a new `document_versions` row with `version_number = max + 1` and `status = current`
4. The database unique constraint `(document_id, version_number)` provides a safety net against race conditions

**Version status values:**

| Status | Meaning |
|--------|---------|
| `current` | The active version — exactly one per document at any time |
| `superseded` | Replaced by a newer version; still downloadable |
| `archived` | Set when the parent document is archived; not downloadable |

**File storage pattern:** `storage/app/documents/{year}/{month}/{uuid}.{ext}`
- The encrypted path string (JSON envelope `{ciphertext, iv, key_id}`) is stored in `document_versions.file_path`
- File content is **not** encrypted on disk — only the path string is encrypted in the database

### 5.3 Controlled Download Protocol

The download endpoint `GET /documents/{id}/versions/{versionId}/download` enforces:

1. Document status must be `published` or another downloadable status (checked via `DocumentStatus::isDownloadable()`)
2. Version status must not be `archived` (checked via `VersionStatus::isDownloadable()`)
3. The encrypted path is decrypted → the file is read from local storage → streamed to the client
4. A `DocumentDownloadRecord` is created for every download:
   - `downloaded_by` = authenticated user ID
   - `watermark_text` = `"{display_name} - {YYYY-MM-DD HH:MM:SS}"` (for PDFs only)
   - `watermark_applied` = `false` (PDF byte-stamping is deferred to a future phase)
5. An `AuditAction::Download` event is recorded

**Response headers returned on every download:**
```
Content-Type: application/pdf   (or the version's MIME type)
Content-Disposition: attachment; filename="{original_filename}"
X-Watermark-Recorded: true
X-Watermark-Applied: false
```

### 5.4 Attachment Upload Pipeline

`AttachmentService::upload()` executes these steps in order:

```
1. Laravel form validation — MIME type string check, max 25 MB (StoreAttachmentRequest)
2. PHP finfo magic-bytes inspection — detects MIME spoofing beyond the declared Content-Type
3. Active attachment count check — aborts with 422 attachment_limit_exceeded if count = 20
4. SHA-256 fingerprint computation (global cross-record deduplication)
   → 409 duplicate_attachment if fingerprint already exists in any attachment record
5. AES-256-CBC encryption of file content in memory using EncryptionService
6. Write encrypted JSON envelope {ciphertext, iv, key_id} to disk:
   storage/app/attachments/{year}/{month}/{uuid}.enc
7. Encrypt the storage path string → store as JSON in attachments.encrypted_path column
8. Create Attachment record: fingerprint, encrypted_path, encryption_key_id, expires_at
```

**Allowed MIME types:** `application/pdf`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `image/png`, `image/jpeg`

**Per-record limit:** Maximum 20 active (non-soft-deleted) attachments per record

**Global deduplication:** The SHA-256 fingerprint check spans all records — an identical file cannot be uploaded anywhere in the system, regardless of which record it targets

**Validity period:** Default 365 days; `expires_at` = `now() + validity_days`. An attachment with a past `expires_at` is considered expired and cannot be resolved via a share link

### 5.5 LAN Share Link Lifecycle

```
created (active) ──► expired    (expires_at < now())
                 ──► consumed   (is_single_use = true AND consumed_at is set)
                 ──► revoked    (revoked_at is set)
```

**Token generation:** `bin2hex(random_bytes(32))` = 64 hexadecimal characters (cryptographically random)

**TTL:** 1–72 hours enforced by `LinkTtlConstraint`. Out-of-range values are clamped to the allowed range.

**Resolution endpoint:** `GET /api/v1/links/{token}` — **public route, no Bearer token required**. The opaque token IS the credential. Checks are applied in this order:

| Check | Failure Response |
|-------|-----------------|
| Token not found | 404 Not Found |
| `expires_at` < now | 410 `link_expired` |
| `revoked_at` is set | 410 `link_revoked` |
| `is_single_use` AND `consumed_at` is set | 410 `link_consumed` |
| Optional `ip_restriction` mismatch | 403 Forbidden |
| Attachment `expires_at` < now | 410 `attachment_expired` |
| Attachment `status` = `revoked` | 410 `attachment_revoked` |

**Single-use atomicity:** `EloquentAttachmentRepository::consumeLink()` uses `DB::transaction` with `lockForUpdate()` to prevent concurrent double-consumption.

**Audit:** Every resolution records an `AuditAction::LinkConsume` event, regardless of whether the link was previously consumed.

---

## 6. Backup, Metrics, and Retention Strategy

### Backups
- **Schedule:** Daily at a configurable time (default 02:00 local)
- **Retention:** 14 days; pruning runs automatically after each backup
- **Scope:** MySQL database dump + `storage/app/attachments/` filesystem snapshot
- **Storage:** `storage/app/backups/YYYY-MM-DD/` with a `manifest.json` per backup
- **Implementation:** Laravel scheduled job dispatching a `RunBackupJob`

### Structured Logs
- **Driver:** Database (`structured_logs` table) for queryable 90-day retention
- **Content:** Request path, duration, status, queue events, authentication events
- **Pruning:** Retention job deletes entries where `retained_until < NOW()`

### Metrics
- **Stored in:** `metrics_snapshots` table
- **Captured:** p95 request timing samples, queue depth snapshots, failed approval counts
- **Retention:** 90 days
- **API access:** Admin-only endpoint for retrieval and summary

---

## 7. Approval/Workflow Engine

- **Template-driven:** Workflows are defined as `workflow_templates` with ordered `workflow_template_nodes`
- **Instance-driven execution:** Each business record that needs approval spawns a `workflow_instance`
- **Node types:** `sequential`, `parallel`, `conditional`
- **State machine:** `pending → in_progress → approved | rejected | withdrawn | expired`
- **Supported actions:** approve, reject (mandatory reason), reassign, add_approver, withdraw (before final approval only)
- **SLA:** Default 2 business days per node (Mon–Fri, system timezone). SLA due timestamps stored on each `workflow_node`
- **Reminders:** Overdue nodes emit `to_do_items` for the assigned approver
- **Conditional branching:** Node conditions evaluated against record fields (e.g., `amount > 10000`)
- **Parallel sign-off:** Multiple nodes with same `node_order` and `is_parallel = true`

---

## 8. Audit and Idempotency Strategy

### Audit Events
- **Table:** `audit_events` — append-only, no `updated_at` column, no soft deletes
- **Fields:** `id` (UUID), `correlation_id` (unique), `actor_id`, `action` (enum), `auditable_type`, `auditable_id`, `before_hash`, `after_hash`, `payload` (JSON), `ip_address`, `created_at`
- **Before/After hashes:** SHA-256 of serialized record state before and after mutation
- **Immutability enforcement:** No `UPDATE` or `DELETE` queries permitted on this table (enforced via Eloquent model override)

### Idempotency
- **Header:** `X-Idempotency-Key` (UUID string) on all mutating requests
- **Storage:** `idempotency_keys` table — key hash, actor scope hash, canonical request hash, cached response status + body, TTL
- **Behavior:** Identical key in the same actor/method/path scope with the same payload hash returns cached response without re-executing logic
- **Conflict guard:** Reusing the same key in that scope with a different payload returns `409 idempotency_key_reused`
- **Audit deduplication:** `correlation_id` on `audit_events` is unique — the same `correlation_id` cannot produce a second row
- **Application layer:** `IdempotencyService` checked before any write operation executes

---

## 9. Security Boundaries and Role/Department Scope Model

### Authentication
- **Method:** Local username/password only — no OAuth, no SSO, no external identity
- **Session token:** Laravel Sanctum bearer tokens (stored in `personal_access_tokens`)
- **Password rules:** Minimum 12 characters, ≥1 uppercase, ≥1 lowercase, ≥1 digit
- **Password storage:** Bcrypt (adaptive, cost factor ≥ 12) via `Hash::make()`
- **Lockout:** 5 failed attempts → 15-minute lockout, tracked in `users.locked_until` and `failed_login_attempts`

### Authorization
- **RBAC:** Spatie Laravel Permission (`roles` + `permissions` tables)
- **Role types:** `admin`, `manager`, `staff`, `auditor`, `viewer`
- **Department scope:** Records carry a `department_id`; policies check user's department match
- **Permission scope:** `own_department`, `cross_department`, `system_wide`
- **Elevated access:** Explicit permission grants, not implicit role inheritance
- **Policy classes:** One policy per major resource type (DocumentPolicy, AttachmentPolicy, SalesDocumentPolicy, etc.)

### Data Protection
- **Attachment payloads:** AES-256 encrypted at rest
- **Sensitive notes:** Field-level masking applied in API responses based on role
- **PDF watermarking:** Username + timestamp watermark applied at download time for PDFs; event recorded in `document_download_records`
- **No SQL injection:** Eloquent/query builder only, parameterized bindings always
- **No secrets in logs:** Passwords, keys, and tokens never written to log channels

---

## 10. Requirement Traceability Table

| Original Prompt Domain | Backend Module(s) | Key Files (planned) |
|------------------------|-------------------|---------------------|
| Authentication & Roles | `Domain/Auth`, `Application/Auth`, `Http/Controllers/Api/AuthController` | `PasswordPolicy.php`, `LockoutPolicy.php`, `AuthController.php`, migrations: users, roles |
| Document & Materials | `Domain/Document`, `Application/Document`, `Infrastructure/Watermark` | `DocumentPolicy.php`, `DocumentController.php`, `VersionController.php`, migrations: documents, document_versions |
| Attachment & Evidence | `Domain/Attachment`, `Application/Attachment`, `Infrastructure/Encryption`, `Infrastructure/Storage` | `FileConstraints.php`, `AttachmentController.php`, `LinkController.php`, migrations: attachments, attachment_links |
| Operations & Configuration | `Domain/Configuration`, `Application/Configuration` | `CanaryConstraint.php`, `ConfigurationController.php`, migrations: configuration_sets, configuration_versions, canary_rollout_targets |
| Workflow & Approvals | `Domain/Workflow`, `Application/Workflow` | `SlaDefaults.php`, `WorkflowController.php`, `ApprovalController.php`, migrations: workflow_templates, workflow_instances, workflow_nodes, approvals, to_do_items |
| Sales Issue & Returns | `Domain/Sales`, `Application/Sales` | `RestockFeePolicy.php`, `DocumentNumberFormat.php`, `SalesController.php`, `ReturnController.php`, migrations: sales_documents, returns, inventory_movements |
| Audit & Idempotency | `Domain/Audit`, `Application/Idempotency`, `Infrastructure/Logging` | `AuditAction.php`, `IdempotencyService.php`, migrations: audit_events, idempotency_keys |
| Backups & Retention | `Application/Backup`, `Infrastructure/Backup` | `RunBackupJob.php`, `RetentionPruneJob.php`, migrations: backup_jobs, metrics_snapshots, structured_logs |
| Security & Encryption | `Infrastructure/Encryption`, `Infrastructure/Hashing` | `AttachmentEncryptionService.php`, `FingerprintService.php` |
| Offline Runtime | `docker-compose.yml`, `Dockerfile`, `.env.example` | Container assets, LAN base URL config |

---

## 11. Non-Functional Targets

| Target | Approach |
|--------|----------|
| p95 latency < 300ms on 1M records | Indexed UUIDs, eager loading, query optimization, database indexes on all FK and filter columns |
| 200 concurrent users | PHP-FPM worker pool tuning, database connection pooling, queue-backed async work |
| Daily backups, 14-day retention | Laravel scheduler + `RunBackupJob` + `PruneBackupsJob` |
| 90-day log/metrics retention | Retention jobs + `retained_until` column on log/metric tables |
| No external dependencies | `database` queue driver, local filesystem, LAN-only link generation |

---

## 12. Validation and Edge-Case Rules (Prompt 2)

### Authentication
| Rule | Value | Enforced By |
|------|-------|-------------|
| Minimum password length | 12 characters | `PasswordPolicy::validate()` |
| Password requires uppercase | ≥1 uppercase letter | `PasswordPolicy::violations()` |
| Password requires lowercase | ≥1 lowercase letter | `PasswordPolicy::violations()` |
| Password requires digit | ≥1 digit | `PasswordPolicy::violations()` |
| Lockout threshold | 5 consecutive failures | `LockoutPolicy::MAX_ATTEMPTS` |
| Lockout duration | 15 minutes | `LockoutPolicy::LOCKOUT_MINUTES` |

### Attachments
| Rule | Value | Enforced By |
|------|-------|-------------|
| Max file size | 25 MB (26,214,400 bytes) | `FileConstraints::MAX_SIZE_BYTES` |
| Max files per record | 20 | `FileConstraints::MAX_FILES_PER_RECORD` |
| Allowed types | PDF, DOCX, XLSX, PNG, JPG | `AllowedMimeType` enum + header inspection |
| Default validity period | 365 days | `LinkTtlConstraint::DEFAULT_VALIDITY_DAYS` |
| Max LAN link TTL | 72 hours | `LinkTtlConstraint::MAX_TTL_HOURS` |
| MIME spoofing prevention | File magic bytes inspected + MIME must match extension | Infrastructure/Storage validation pipeline |

### Configuration
| Rule | Value | Enforced By |
|------|-------|-------------|
| Canary rollout cap | ≤ 10% of eligible population | `CanaryConstraint::MAX_CANARY_PERCENT` |
| Canary minimum window | 24 hours before promotion | `CanaryConstraint::MIN_PROMOTION_HOURS` |

### Workflow
| Rule | Value | Enforced By |
|------|-------|-------------|
| Default SLA per node | 2 business days | `SlaDefaults::DEFAULT_SLA_BUSINESS_DAYS` |
| Business day definition | Monday–Friday (system timezone) | `SlaDefaults::calculateDueAt()` |
| Rejection requires reason | Mandatory reason field | `ApprovalAction::requiresReason()` |

### Sales and Returns
| Rule | Value | Enforced By |
|------|-------|-------------|
| Default restock fee | 10% for non-defective returns | `RestockFeePolicy::DEFAULT_RESTOCK_PERCENT` |
| Qualifying return window | 30 days from sale | `RestockFeePolicy::QUALIFYING_RETURN_DAYS` |
| Defective returns | No restock fee | `RestockFeePolicy::calculateFee()` |
| Document number format | `SITE-YYYYMMDD-000001` | `DocumentNumberFormat::format()` |
| Outbound linkage | Requires `completed` status | `SalesStatus::allowsOutboundLinkage()` |

---

## 13. Key Sequence Flows (Prompt 2)

### 13.1 Document Versioning and Archive Flow

```
1. POST /documents → Document created in 'draft' status
2. POST /documents/{id}/versions → Upload file → validate MIME + headers → compute SHA-256
   → encrypt at rest → store path → create document_version (status: 'current')
   → supersede previous current version
3. PUT /documents/{id} → Update metadata (only if not archived)
4. POST /documents/{id}/archive →
   a. Check: document must not already be archived (409 if so)
   b. Set document.status = 'archived', is_archived = true, archived_at = now, archived_by = actor
   c. Set all child versions to status = 'archived'
   d. Emit audit_event: action='archive', before_hash, after_hash
   e. No further edits or new versions permitted
```

### 13.2 Attachment Upload → Encrypt → Link → Consume Flow

```
1. POST /records/{type}/{id}/attachments
   a. Validate: MIME type in allowed list
   b. Validate: actual file magic bytes match declared MIME
   c. Validate: file size ≤ 25 MB
   d. Validate: active attachment count + new count ≤ 20
   e. Compute SHA-256 fingerprint of plaintext
   f. Encrypt with AES-256-CBC using ATTACHMENT_ENCRYPTION_KEY
   g. Store encrypted file to storage/app/attachments/{year}/{month}/{uuid}.enc
   h. Create attachment record with fingerprint, encrypted_path, validity expiry
   i. Emit audit_event: action='create'

2. POST /attachments/{id}/links
   a. Validate TTL: 1 ≤ ttl_hours ≤ 72
   b. Generate opaque 64-byte token (cryptographically random)
   c. Create attachment_link: token, expires_at = now + ttl, is_single_use
   d. Return link URL: {LAN_BASE_URL}/api/v1/links/{token}
   e. Emit audit_event: action='link_create'

3. GET /api/v1/links/{token}
   a. Lookup token in attachment_links
   b. Check: not expired (410 if expired)
   c. Check: not revoked (410 if revoked)
   d. If is_single_use: atomically set consumed_at = now, consumed_by = resolver user ID when authenticated (otherwise null)
      - If already consumed → 410 Gone
   e. Decrypt attachment payload in memory
   f. Stream response to client
   g. Emit audit_event: action='link_consume'
```

### 13.3 Configuration Canary Rollout → Promotion Flow

```
1. POST /configuration/sets/{id}/versions → Create new config version in 'draft' status
2. POST /configuration/versions/{id}/rollout
   a. Validate: target_ids.count ≤ floor(eligible_count * 0.10)
   b. Set version.status = 'canary', canary_started_at = now
   c. Insert canary_rollout_targets rows
   d. Emit audit_event: action='rollout_start'

3. POST /configuration/versions/{id}/promote
   a. Validate: now >= canary_started_at + 24 hours (423 if too early)
   b. Set version.status = 'promoted', promoted_at = now
   c. Deactivate previous promoted version for this set
   d. Emit audit_event: action='rollout_promote'

4. POST /configuration/versions/{id}/rollback
   a. Set version.status = 'rolled_back', rolled_back_at = now
   b. Reactivate previous promoted version
   c. Emit audit_event: action='rollout_back'
```

### 13.4 Workflow: Template → Instance → Approval → SLA Reminder Flow

```
1. POST /workflow/instances → Create instance for a business record
   a. Look up matching template by event_type + amount thresholds
   b. Create workflow_instance (status: 'pending')
   c. Create workflow_nodes from template nodes (apply branching conditions)
   d. Set sla_due_at on each node using SlaDefaults::calculateDueAt()
   e. Assign first non-conditional, non-parallel nodes to target users
   f. Create to_do_items for assignees

2. POST /workflow/nodes/{id}/approve
   a. Validate: node status is 'pending' or 'in_progress'
   b. Create approval record: action='approve', actioned_at
   c. Set node.status = 'approved', completed_at = now
   d. Check if all parallel nodes at same order are approved → advance to next order
   e. If last node: set instance.status = 'approved', completed_at = now
   f. Emit audit_event: action='approve'

3. POST /workflow/nodes/{id}/reject (reason required)
   a. Create approval record: action='reject', reason
   b. Set node.status = 'rejected'
   c. Set instance.status = 'rejected'
   d. Emit audit_event: action='reject'

4. SLA Reminder (scheduled job — every hour)
   a. Find workflow_nodes where sla_due_at < now AND status IN ('pending','in_progress')
     AND reminded_at IS NULL (or reminded_at > 24h ago)
   b. Create to_do_items for assigned users with type='sla_reminder'
   c. Set reminded_at = now
```

### 13.5 Sales Document: Draft → Complete → Outbound Linkage Flow

```
1. POST /sales → Create sales document (status: 'draft')
   a. Generate document_number transactionally from document_number_sequences
   b. Format: DocumentNumberFormat::format(siteCode, today, nextSequence)

2. POST /sales/{id}/submit → Transition 'draft' → 'reviewed'
   a. Validate: status is 'draft'
   b. Set status = 'reviewed', reviewed_by, reviewed_at
   c. Emit audit_event: action='sales_submit'

3. POST /sales/{id}/complete → Transition 'reviewed' → 'completed'
   a. Validate: status is 'reviewed' AND workflow_instance (if any) is 'approved'
   b. Set status = 'completed', completed_at = now
   c. Create inventory_movements for each line item (movement_type='sale')
   d. Emit audit_event: action='sales_complete'

4. POST /sales/{id}/link-outbound
   a. Validate: status must be 'completed' (outbound linkage requires final approval)
   b. Set outbound_linked_at = now, outbound_linked_by = actor
   c. Emit audit_event

5. POST /sales/{id}/void
   a. Validate: status is NOT 'completed' (cannot void a completed document)
   b. Set status = 'voided', voided_at, voided_reason
   c. Emit audit_event: action='sales_void'
```

### 13.6 Return Processing → Inventory Rollback → Restock Fee Flow

```
1. POST /sales/{id}/returns → Create return record
   a. Validate: original sales document is 'completed'
   b. Calculate days_elapsed since sales.completed_at
   c. Determine is_defective from reason_code
   d. Calculate restock_fee = RestockFeePolicy::calculateFee(amount, is_defective, days_elapsed)
   e. Calculate refund_amount = RestockFeePolicy::calculateRefundAmount(amount, restock_fee)
   f. Create return record (status: 'pending')
   g. Generate return_document_number
   h. Emit audit_event: action='return_create'

2. POST /returns/{id}/complete
   a. Validate: return status is 'pending'
   b. Set status = 'completed', completed_at = now
   c. Create compensating inventory_movements (movement_type='return')
     - quantity_delta is positive (stock restored)
     - Linked to original sales_document_id for audit provenance
   d. Emit audit_event: action='return_complete'
```

---

## 6. Configuration Center and Canary Rollout

### 6.1 Config Version Lifecycle State Machine

```
draft ──► canary ──► promoted  (terminal)
              └────► rolled_back  (terminal)
```

Transitions are enforced by `RolloutStatus::allowedTransitions()` and by `ConfigurationService` which throws `InvalidRolloutTransitionException` on illegal moves.

### 6.2 Canary Cap Enforcement

`CanaryConstraint::maxTargets(eligibleCount)` = `floor(eligible × 10%)`.

`CanaryConstraint::isWithinCap(requested, eligible)` compares `requested ≤ maxTargets`. This check runs **before** any database write inside `ConfigurationService::startCanaryRollout`. If the cap is exceeded, `CanaryCapExceededException` is thrown and no DB records are created.

### 6.3 Promotion Window (24 h)

`CanaryConstraint::canPromote(canary_started_at: DateTimeImmutable, now: DateTimeImmutable): bool` returns true only if `(now - canary_started_at) ≥ 24 h`.

If the window has not elapsed, `ConfigurationService::promoteVersion` throws `CanaryNotReadyToPromoteException` carrying `earliestAt` (canary_started_at + 24 h) for inclusion in the error response.

### 6.4 Policy Types Covered

`PolicyType` enum values: `coupon`, `promotion`, `purchase_limit`, `blacklist`, `whitelist`, `campaign`, `landing_topic`, `ad_slot`, `homepage_module`.

`ConfigurationRule.rule_type` is cast to this enum on the Eloquent model.

### 6.5 Audit Coverage

Every rollout state change records an `AuditEvent`:

| Action | AuditAction case |
|--------|-----------------|
| Canary start | `RolloutStart` |
| Promote | `RolloutPromote` |
| Roll back | `RolloutBack` |

---

## 7. Workflow Engine

### 7.1 Instance State Machine

```
pending ──► in_progress ──► approved   (terminal)
                       ├──► rejected   (terminal)
                       ├──► withdrawn  (terminal)
                       └──► expired    (terminal)
```

Terminal states: `approved`, `rejected`, `withdrawn`, `expired`.  
`WorkflowStatus::isTerminal()` guards all mutating service methods.

### 7.2 Node Types

| NodeType | Advance condition |
|----------|------------------|
| `sequential` | Single approver signs off → advance to next node |
| `parallel` | ALL nodes at the same `node_order` must reach `Approved` status before the instance advances |
| `conditional` | Evaluated against `context` data using `condition_field` / `condition_operator` (gt, lt, eq, gte, lte) / `condition_value`; skipped if condition is false |

`EloquentWorkflowRepository::allParallelNodesApproved(instanceId, nodeOrder)` counts total vs. Approved nodes at the given order. The instance only advances when total === approved.

### 7.3 Mandatory Reasons

`WorkflowService::reject` and `WorkflowService::reassign` both call `empty(trim($reason))` and throw `ReasonRequiredException` (HTTP 422) before any DB write. This is enforced at the **service layer**, not the form validation layer, so the constraint cannot be bypassed by direct service injection.

### 7.4 SLA Calculation

`SlaDefaults::calculateDueAt(startAt: DateTimeImmutable, businessDays: int): DateTimeImmutable`

- Counts Mon–Fri only; weekends are skipped.
- Default SLA: `SlaDefaults::DEFAULT_SLA_BUSINESS_DAYS = 2`.
- No holiday calendar — public holidays are treated as business days.
- SLA due date stored as `workflow_nodes.sla_due_at`.

### 7.5 To-Do Queue

`TodoService::create(userId, type, title, body, referenceId, dueAt)` is called:
- When a workflow node is first activated (instance started or instance advanced to next node)
- When a node is reassigned (new assignee receives a todo)
- When an approver is added (additional approver receives a todo)

Items are retrieved via `GET /api/v1/todo`. Completed items are excluded by default; pass `?include_completed=true` to include them. `POST /api/v1/todo/{id}/complete` marks an item as done. Ownership is enforced — users can only complete their own items.

---

## 8. Sales and Returns

### 8.1 Sales State Machine

```
draft ──► reviewed ──► completed  (terminal)
     └──────────────► voided      (terminal)
reviewed ──────────► voided      (terminal)
```

`SalesStatus::canTransitionTo(target)` enforces valid transitions. `SalesDocumentService` throws `InvalidSalesTransitionException` (HTTP 409) on illegal moves.

### 8.2 Document Numbering

Format: `SITE-YYYYMMDD-NNNNNN` (e.g., `HQ-20250815-000042`).

`EloquentSalesRepository::nextDocumentNumber(siteCode, businessDate)` wraps a `DB::transaction` with `SELECT ... FOR UPDATE` on the `document_number_sequences` table. The per-`(site_code, business_date)` sequence resets each calendar day. The DB unique index on `(site_code, business_date)` is the collision safety net for concurrent transaction start races.

`DocumentNumberFormat::format(siteCode, date, sequence)` produces the string. `DocumentNumberFormat::isValid(value)` and `parse(value)` are available for external consumers.

### 8.3 Outbound Linkage

Only permitted when `SalesDocument.status === Completed` (`allowsOutboundLinkage()` returns true). Records `outbound_linked_at` and `outbound_linked_by` on the document. Attempting linkage on any other status throws `OutboundLinkageNotAllowedException` (HTTP 409).

### 8.4 Return Processing

`ReturnService::createReturn` validates:
1. `salesDoc.status.allowsReturn()` → only `Completed` qualifies; otherwise `InvalidSalesTransitionException`.
2. Days elapsed from `completed_at` to now.
3. `ReturnReasonCode::isDefective()` → defective returns skip the window check and receive 0 restock fee.
4. Non-defective returns beyond `RestockFeePolicy::QUALIFYING_RETURN_DAYS (30)` → `ReturnWindowExpiredException` (HTTP 422).

Fee calculation:
- `RestockFeePolicy::calculateFee(returnAmount, isDefective, daysElapsed, feePercent)` — returns 0.0 for defective; `round(returnAmount × feePercent / 100, 2)` for non-defective.
- Default `feePercent` = 10.0 (`RestockFeePolicy::DEFAULT_RESTOCK_PERCENT`).
- Caller may override via `restock_fee_percent` in the request body.
- `RestockFeePolicy::calculateRefundAmount(returnAmount, restockFee)` = `max(0, returnAmount - restockFee)`.

Return document numbers use the same `nextDocumentNumber` mechanism with a suffix-R site code (e.g., `ECO1R-20250815-000001`).

### 8.5 Inventory Movements

| Event | `movement_type` | `quantity_delta` |
|-------|----------------|-----------------|
| Sale completion | `sale` | Negative (`-abs(quantity)` per line item) |
| Return completion | `return` | Positive (`+abs(quantity)` per line item of original sale) |

`InventoryMovement` records carry `sales_document_id` (for sale type) or `return_id` (for return type) for audit provenance. All movements are created within the same service method call as the status transition for atomicity.

---

## 9. Backup and Retention

### 9.1 Daily Backup Orchestration

The scheduler (Laravel Scheduler + Docker cron, running every minute) dispatches `RunBackupJob` at 02:00 local time daily. This job:

1. Creates a `BackupJob` record via `BackupMetadataService::startBackup(isManual=false)`.
2. Marks the job as `running`.
3. Builds a manifest: table row-count summary for all 28 application tables + attachment filesystem inventory (file count, total bytes).
4. Calls `BackupMetadataService::completeBackup(jobId, manifest, sizeBytes)` or `failBackup(jobId, error)`.
5. Records a `BackupRun` audit event (actor_id = null = system).

**Manifest structure:**
```json
{
  "created_at": "2025-08-15T02:00:00Z",
  "tables": [{"table": "users", "row_count": 12}, ...],
  "attachment_file_count": 85,
  "attachment_storage_bytes": 47185920
}
```

Manual backup is triggered via `POST /api/v1/admin/backups` (admin only), which dispatches `RunBackupJob(isManual=true)`.

### 9.2 Backup Retention — 14 Days

`BackupJob.retention_expires_at` = `started_at + 14 days` (configurable via `BACKUP_RETENTION_DAYS`).

`PruneBackupsJob` runs daily at 03:00 and deletes all `backup_jobs` rows where `retention_expires_at < now()`. Returns the count of deleted rows to the structured log.

### 9.3 Structured Log Retention — 90 Days

`StructuredLog.retained_until` = `recorded_at + 90 days` (configurable via `LOG_RETENTION_DAYS`).

`PruneRetentionJob` runs daily at 03:30. It calls `StructuredLogger::prune()` (deletes `structured_logs` where `retained_until < now()`) and `MetricsRetentionService::pruneExpired()` in one job.

### 9.4 Metrics Retention — 90 Days

`MetricsSnapshot.retained_until` = `recorded_at + 90 days` (configurable via `METRICS_RETENTION_DAYS`).

Pruned by the same `PruneRetentionJob` as logs. Three metric types:
- `request_timing` — p95 request duration samples (ms)
- `queue_depth` — snapshot of pending queued job count
- `failed_approvals` — count of workflow nodes rejected or expired in a period

### 9.5 Schedule Summary

| Job | Schedule | Purpose |
|-----|----------|---------|
| `RunBackupJob` | 02:00 daily | Backup manifest + DB/filesystem inventory |
| `PruneBackupsJob` | 03:00 daily | Delete backup_jobs records beyond 14-day retention |
| `PruneRetentionJob` | 03:30 daily | Delete structured_logs + metrics_snapshots beyond 90-day retention |
| `ExpireAttachmentsJob` | 04:00 daily | Transition active attachments past validity window to `expired` |
| `ExpireAttachmentLinksJob` | Every 15 min | Delete expired/consumed/revoked share links after 24h grace period |
| `SendSlaRemindersJob` | Every hour | Create SLA reminder to-do items for overdue workflow nodes |

---

## 10. Admin and Audit Surfaces

### 10.1 Audit Event Browsing

`GET /api/v1/audit/events` — admin and auditor roles only (`AuditEventPolicy::viewAny`).
Filters: `actor_id`, `action`, `auditable_type`, `auditable_id`, `date_from`, `date_to`. Ordered by `created_at DESC`. Max 200 per page.

`GET /api/v1/audit/events/{id}` — single event retrieval, same authorization.

`GET /api/v1/admin/config-promotions` — filtered view of `rollout_start`, `rollout_promote`, `rollout_back` actions; admin/auditor only. Useful for offline compliance review of configuration lifecycle.

### 10.2 Failed Login and Lockout Inspection

`GET /api/v1/admin/failed-logins` — admin only. Lists `FailedLoginAttempt` records (actor, IP, timestamp). Filters: `user_id`, `ip_address`, `date_from`, `date_to`.

`GET /api/v1/admin/locked-accounts` — admin only. Lists users where `locked_until > now()`. Returns `locked_until`, `failed_attempt_count`, `last_failed_at`.

### 10.3 Approval Backlog

`GET /api/v1/admin/approval-backlog` — admin or manager only. Lists workflow nodes in `pending` or `in_progress` status. Supports `?overdue_only=1` to filter to nodes past their `sla_due_at`. Returns `is_overdue` flag per node.

### 10.4 Backup History and Retention Status

`GET /api/v1/admin/backups` — admin only. Paginated list of `BackupJob` records ordered by `started_at DESC`. Response includes `meta.retention_days`. Filter by `status`.

`POST /api/v1/admin/backups` — admin only. Dispatches `RunBackupJob(isManual=true)` to the queue. Returns HTTP 202 Accepted.

### 10.5 Metrics Retrieval

`GET /api/v1/admin/metrics` — admin only. Raw metrics snapshot listing. Filters: `metric_type`, `date_from`, `date_to`. Pass `?summary=1` for aggregated view (`sample_count`, `avg_value`, `min_value`, `max_value`, `last_recorded_at`) grouped by `metric_type`.

### 10.6 Structured Logs

`GET /api/v1/admin/logs` — admin or auditor. Filters: `level`, `channel`, `date_from`, `date_to`, `message` (substring), `request_id`. Ordered by `recorded_at DESC`.

### 10.7 Local Health Endpoint

`GET /api/v1/admin/health` — admin only. Returns:
- `database`: DB connectivity status
- `queue`: pending + failed job counts
- `storage`: attachment file count + total bytes
- `backup`: last successful backup timestamp + hours elapsed (warns if > 26h)
- `app`: version, environment, timezone, LAN base URL
- `retention`: configured retention days for backups, logs, metrics

---

## 11. LAN-Local External-Link Governance

### 11.1 URL Generation

All share links are generated using `config('meridian.lan_base_url')` (default: `http://localhost:8000`), which must point to the host:port accessible to LAN clients. The token is appended as the sole path segment: `{LAN_BASE_URL}/api/v1/links/{token}`.

**The LAN_BASE_URL must be set correctly in `.env` for generated URLs to be resolvable by other LAN hosts.** Example: `LAN_BASE_URL=http://192.168.1.100:8000`.

### 11.2 Token Generation and Secrecy

Each share link token is a 64-character cryptographically random hex string (generated via `Str::random(64)`). The token is the sole credential — no Bearer authentication is required at `GET /api/v1/links/{token}`. This makes links self-contained and shareable within the LAN without requiring an account.

### 11.3 TTL Enforcement

- Hard maximum: 72 hours (enforced by `LinkTtlConstraint`). Configurable via `LINK_MAX_TTL_HOURS`.
- `AttachmentLink.expires_at` is checked at every resolution attempt via `ExpiryEvaluator::isLinkExpired()`.
- `ExpireAttachmentLinksJob` physically deletes expired links after a 24-hour grace period (to allow admin queries to see recent terminations).

### 11.4 Single-Use Invalidation

When `is_single_use = true`, the first successful resolution atomically sets `consumed_at = now()`. For token-only public access, `consumed_by` remains `null`; if an authenticated resolver context is explicitly present, `consumed_by` stores that user ID. Subsequent resolutions immediately throw `LinkConsumedException` (HTTP 410). The consumption check is inside a `DB::transaction` in `AttachmentService::resolveLink` to prevent race conditions.

### 11.5 IP Restriction

Optional: `ip_restriction` column on `attachment_links`. When set, the link resolves only for requests whose IP matches the stored restriction. If the IP does not match, the resolution throws `LinkRevokedException` (HTTP 410, code: `link_revoked`).

### 11.6 Administrative Revocation

`POST /api/v1/attachments/{attachment}/links/{link}/revoke` (if implemented) sets `revoked_at + revoked_by`. Revoked links immediately throw `LinkRevokedException` on any resolution attempt. `ExpireAttachmentLinksJob` physically deletes revoked links after the 24h grace period.
