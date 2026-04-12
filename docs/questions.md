# questions.md

## 1) Attachment preview semantics in an offline backend-only delivery

**The Gap**
The prompt requires document APIs to support “online preview metadata,” but it does not define whether the system must render previews, expose derived preview assets, or only provide metadata that a LAN-local consumer can use.

**The Interpretation**
Treat preview support as **metadata-only at the API level** unless a stored derivative already exists. The backend must expose preview-related metadata and availability flags, but it does not need to implement a browser rendering engine or rich document preview service.

**Proposed Implementation**
Store preview metadata fields on documents and versions, such as original filename, MIME type, page count or sheet count when derivable locally, previewable flag, thumbnail/derived-asset availability flag, and archive/download eligibility. Expose these through document/version resources and keep the contract extensible for future derived-preview generation without making fake rendering claims.

## 2) PDF watermark application versus watermark-event recording

**The Gap**
The prompt says watermarking rules are recorded at download time and that watermark text includes username and timestamp for PDFs only, but it does not explicitly say whether the system must generate a newly watermarked PDF file on each download or only log the watermark payload that should be applied.

**The Interpretation**
Implement **real watermark application for PDFs during controlled downloads** when technically feasible in the offline backend, and always record the watermark event. For non-PDF files, do not attempt watermarking; only log the controlled download event.

**Proposed Implementation**
Add a PDF watermark service that streams a derived watermarked response at download time using local server-side PDF tooling, while preserving the original encrypted payload at rest. Record a download audit entry containing actor, timestamp, document/version, and watermark text. Keep the code path explicit so the audit remains truthful even if watermark generation fails and the request is rejected.

## 3) LAN external-link host binding and single-use semantics

**The Gap**
The prompt requires expiring external links for offline LAN sharing with max TTL 72 hours and single-use option, but it does not specify the canonical host/base URL, whether links are token-only or signed routes, or how single-use is enforced under concurrent access.

**The Interpretation**
Assume the system has a configurable LAN base URL and that share links are signed opaque tokens stored server-side. Single-use means the first successful authorized retrieval consumes the link atomically; later attempts fail.

**Proposed Implementation**
Store `attachment_links` with UUID/token, base-URL-independent lookup key, expiry timestamp, single-use flag, consumed-at timestamp, consumed-by metadata when known, and revocation state. Generate links from a configured LAN host value in `.env`. Enforce atomic consumption in the database using a transactional update and respond with 410/404-style API errors for expired, revoked, or consumed links.

## 4) Business-day SLA calculation rules

**The Gap**
Workflow node SLAs default to 2 business days, but the prompt does not define weekend handling, holidays, working hours, or timezone behavior.

**The Interpretation**
Use a pragmatic default: business days are Monday through Friday in the system-local timezone, excluding no custom holidays unless later configured. SLA deadlines expire at the same local clock time on the calculated business day.

**Proposed Implementation**
Implement a reusable SLA calendar service with configurable timezone and optional future holiday support. In the first delivery, compute due dates by skipping weekends only, storing both the raw configured SLA and the resolved due timestamp on nodes and reminder jobs.

## 5) Canary rollout target-selection rules

**The Gap**
Configuration rollout must support canary promotion to a selectable subset of stores/users capped at 10% for 24 hours, but the prompt does not define whether the 10% cap is measured against stores, users, both independently, or whichever target type is selected for a given rollout.

**The Interpretation**
Treat the cap as applying to the **selected target population type for that rollout**. If the rollout is store-targeted, cap against eligible stores. If it is user-targeted, cap against eligible users.

**Proposed Implementation**
Model rollout targets polymorphically with target type (`store` or `user`), eligible population counts, selected count, and computed percentage. Enforce a hard validation rule that selected targets cannot exceed 10% of the relevant eligible population at activation time, and persist promotion timestamps so full rollout is blocked until 24 hours elapse.

## 6) Document-number reset scope for date-prefixed sequential numbering

**The Gap**
Sales document numbers must be unique, date-prefixed, and sequential per site, but the prompt does not define the reset cadence for the sequence portion.

**The Interpretation**
Reset numbering **per site per calendar day** using the date prefix as part of the uniqueness boundary.

**Proposed Implementation**
Create a numbering-sequence table keyed by site and business date. Generate numbers transactionally in the format `SITE-YYYYMMDD-000001` (or a similarly explicit documented variant), backed by a unique index on the final `document_number` and retry-safe locking to prevent duplicate issuance under concurrency.

## 7) Return/exchange inventory rollback depth

**The Gap**
The prompt says returns/exchanges trigger inventory rollback rules, but it does not define whether rollback must restore exact original lot/bin-level allocations or can post summarized compensating inventory movements.

**The Interpretation**
Implement truthful compensating inventory movements at the inventory-movement level, preserving auditability, while allowing allocation detail to be as granular as the available source sales data supports.

**Proposed Implementation**
When a return/exchange is completed, create compensating `inventory_movements` tied to the original sales document and line items. If original stock source metadata exists, restore against that source. If not, route through a configured returns/restock location and record the reason and linkage explicitly so the ledger remains auditable without inventing unavailable provenance.

## 8) Backup content and restore scope

**The Gap**
The prompt requires daily local backups with 14-day retention, but it does not explicitly define whether backups must include only MySQL data or also encrypted attachments, generated artifacts, logs, and metrics snapshots.

**The Interpretation**
Treat backups as **application-consistent local backups of both database and required local business artifacts**, especially encrypted attachments and share-link state, because database-only backups would leave the document/evidence system incomplete.

**Proposed Implementation**
Implement a backup manifest that captures database dump metadata plus filesystem artifact sets required for restore: attachment storage, backup metadata, and any essential local generated assets. Exclude ephemeral caches. Store backup manifests so retention pruning and future restore tooling can reason about full backup completeness.

## 9) Metrics implementation depth for offline troubleshooting

**The Gap**
The prompt requires structured logs plus metrics for request timing, queue depth, and failed approvals retained for 90 days, but it does not define whether metrics must be exposed via Prometheus-style scraping, internal tables, rollups, or admin APIs.

**The Interpretation**
Implement metrics as **locally persisted application metrics with admin/API retrieval**, not as an external monitoring dependency.

**Proposed Implementation**
Persist request timing summaries, queue-depth snapshots, and failed-approval counters in local tables or structured local metric files that are queryable through authorized admin APIs. Add retention jobs for 90-day pruning and document the retrieval endpoints in `docs/api-spec.md` and `repo/README.md`.

## 10) Department scope and cross-role visibility for document and business records

**The Gap**
The prompt requires access control by role and department, but it does not fully specify whether cross-department read access exists for oversight roles or whether every resource is owned by exactly one department.

**The Interpretation**
Assume records are assigned a primary department, and elevated oversight roles can be granted explicit cross-department visibility through role/policy rules rather than implicit unrestricted access.

**Proposed Implementation**
Add department ownership fields on governed records plus policy checks that combine role permissions with department scope rules. Model elevated visibility as explicit permissions/scopes in the authorization layer so cross-department access remains auditable and testable instead of being hidden inside ad hoc conditionals.

## 11) Backup execution vs. manifest-only orchestration

**The Gap**
Prompt 7 requires "daily local backup orchestration" but does not specify whether the application layer must execute `mysqldump` itself, or whether the Docker/cron layer is responsible for the actual dump and the application only tracks the outcome.

**The Interpretation**
Treat backup execution as a **two-layer concern**: the Docker cron layer runs `mysqldump` (or equivalent) and the application layer records the outcome via `RunBackupJob`. This separation keeps the application testable without requiring shell execution and preserves a clear audit trail through `BackupJob` records.

**Proposed Implementation**
`RunBackupJob` builds a manifest (DB row counts + attachment file inventory) and records the result in `backup_jobs`. In a full Docker deployment, a pre-job cron step would run `mysqldump` first and pass the dump path to the job for inclusion in the manifest. The `backup_jobs.manifest` field captures all metadata needed for restore tooling to locate artifacts.

## 12) Single-use link revocation for admin-initiated removal vs. consumption

**The Gap**
Prompt 7 requires LAN-local link governance including "single-use invalidation rules" but the schema only has `consumed_at` (set by download) and `revoked_at` (set by admin). It is ambiguous whether an admin can revoke an already-consumed link or whether revoked/consumed states conflict.

**The Interpretation**
`consumed_at` and `revoked_at` are independent terminal states. `ExpiryEvaluator` checks each independently: consumed links throw `LinkConsumedException`, revoked links throw `LinkRevokedException`. An admin can revoke a link regardless of consumption state. `ExpireAttachmentLinksJob` deletes links once either terminal state is older than the 24h grace period.
