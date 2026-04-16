# Requirement-to-Test Traceability Matrix
## Meridian Enterprise Operations & Document Management (TASK-112)

**Version:** 8.0 (Prompt 8 — Test Suite Hardening)  
**Purpose:** Maps each prompt requirement to the PHP class that implements it and the test file(s) that verify it. Intended for static acceptance audits.

---

## Table of Contents

1. [Prompt 1 — Authentication & Roles](#prompt-1--authentication--roles)
2. [Prompt 2 — Document & Materials APIs](#prompt-2--document--materials-apis)
3. [Prompt 3 — Attachment & Evidence APIs](#prompt-3--attachment--evidence-apis)
4. [Prompt 4 — Operations & Configuration Center APIs](#prompt-4--operations--configuration-center-apis)
5. [Prompt 5 — Workflow & Approval APIs](#prompt-5--workflow--approval-apis)
6. [Prompt 6 — Sales Issue & Return/Exchange APIs](#prompt-6--sales-issue--returnexchange-apis)
7. [Prompt 7 — Operational Resilience](#prompt-7--operational-resilience)
8. [Cross-Cutting Concerns](#cross-cutting-concerns)

---

## Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Fully covered by unit + API tests |
| 🔵 | Covered by API/integration test only |
| 🟡 | Covered by unit test only |
| ⚠️ | Partially covered — noted gap |

---

## Prompt 1 — Authentication & Roles

**Domain:** `app/Domain/Auth/`  
**Service:** `app/Application/Auth/AuthenticationService.php`  
**Repository:** `app/Infrastructure/Persistence/EloquentUserRepository.php`  
**Controller:** `app/Http/Controllers/Api/AuthController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Local username/password login | `AuthenticationService::login()` | `unit_tests/Application/Auth/AuthenticationServiceTest.php` | `api_tests/Auth/LoginTest.php` | ✅ |
| 12-char minimum password | `PasswordPolicy::MIN_LENGTH` | `unit_tests/Domain/Auth/PasswordPolicyTest.php` | `api_tests/Auth/LoginTest.php` | ✅ |
| Complexity: 1 uppercase, 1 lowercase, 1 digit | `PasswordPolicy` value object | `unit_tests/Domain/Auth/PasswordPolicyTest.php` | — | 🟡 |
| 5-attempt lockout trigger | `LockoutPolicy::shouldLock()`, `AuthenticationService` | `unit_tests/Domain/Auth/LockoutPolicyTest.php`, `unit_tests/Application/Auth/AuthenticationServiceTest.php` | `api_tests/Auth/LockoutProgressionTest.php` | ✅ |
| 15-minute lockout window | `LockoutPolicy::lockoutUntil()` | `unit_tests/Domain/Auth/LockoutPolicyTest.php` | `api_tests/Auth/LockoutProgressionTest.php` | ✅ |
| 423 account_locked response | `bootstrap/app.php` (AccountLockedException handler) | `unit_tests/Application/Auth/AuthenticationServiceTest.php` | `api_tests/Auth/LoginTest.php`, `api_tests/Auth/LockoutProgressionTest.php` | ✅ |
| Lock clears on successful login | `EloquentUserRepository::clearFailedAttempts()` | `unit_tests/Application/Auth/AuthenticationServiceTest.php` | `api_tests/Auth/LockoutProgressionTest.php` | ✅ |
| Lock expires after 15 minutes | `LockoutPolicy::isLockoutExpired()` | `unit_tests/Domain/Auth/LockoutPolicyTest.php` | `api_tests/Auth/LockoutProgressionTest.php` | ✅ |
| FailedLoginAttempt record created on every failure | `AuthenticationService::recordFailedAttempt()` | — | `api_tests/Auth/LockoutProgressionTest.php` | 🔵 |
| Inactive account → 401 (not 423) | `AuthenticationService` | `unit_tests/Application/Auth/AuthenticationServiceTest.php` | `api_tests/Auth/LoginTest.php` | ✅ |
| Sanctum bearer token issued on success | `AuthenticationService::login()` | `unit_tests/Application/Auth/AuthenticationServiceTest.php` | `api_tests/Auth/LoginTest.php` | ✅ |
| Token revoked on logout | `AuthenticationService::logout()` | — | `api_tests/Auth/LogoutTest.php` | 🔵 |
| GET /auth/me returns user + roles | `AuthController::me()` | — | `api_tests/Auth/MeTest.php` | 🔵 |
| RBAC role assignment (spatie) | `RoleAndPermissionSeeder`, User model | — | `api_tests/Authorization/PolicyEnforcementTest.php` | 🔵 |
| 403 for unauthorized role on protected routes | Laravel Policies | — | `api_tests/Authorization/PolicyEnforcementTest.php` | 🔵 |
| 401 for unauthenticated requests | Sanctum middleware | — | `api_tests/Authorization/PolicyEnforcementTest.php`, `api_tests/Contract/ErrorEnvelopeTest.php` | 🔵 |

---

## Prompt 2 — Document & Materials APIs

**Domain:** `app/Domain/Document/`  
**Service:** `app/Application/Document/DocumentService.php`  
**Repository:** `app/Infrastructure/Persistence/EloquentDocumentRepository.php`  
**Controller:** `app/Http/Controllers/Api/DocumentController.php`, `DocumentVersionController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Create document (CRUD) | `DocumentService::create()` | `unit_tests/Application/Document/DocumentServiceTest.php` | `api_tests/Document/DocumentCrudTest.php` | ✅ |
| Department-scoped access control | `DocumentPolicy`, `DocumentService` | `unit_tests/Application/Document/DocumentServiceTest.php` | `api_tests/Document/DocumentCrudTest.php` | ✅ |
| 403 cross-department read of dept-scoped doc | `DocumentPolicy::view()` | — | `api_tests/Document/DocumentCrudTest.php` | 🔵 |
| Archive freezing (status=archived) | `DocumentService::archive()` | `unit_tests/Application/Document/DocumentServiceTest.php` | `api_tests/Document/DocumentCrudTest.php` | ✅ |
| 409 on upload to archived document | `DocumentVersionController` → `DocumentArchivedException` | — | `api_tests/Document/DocumentVersionTest.php` | 🔵 |
| 409 on update to archived document | `DocumentService::update()` | — | `api_tests/Document/DocumentCrudTest.php` | 🔵 |
| Versioned uploads (version_number auto-increment) | `DocumentService::uploadVersion()` | `unit_tests/Application/Document/DocumentServiceTest.php` | `api_tests/Document/DocumentVersionTest.php` | ✅ |
| List document versions (`GET /documents/{id}/versions`) | `DocumentVersionController::index()` | — | `api_tests/Document/DocumentVersionTest.php` | 🔵 |
| Previous version becomes 'superseded' on new upload | `DocumentService::uploadVersion()` | `unit_tests/Application/Document/DocumentServiceTest.php` | `api_tests/Document/DocumentVersionTest.php` | ✅ |
| Controlled download with watermark recording | `DocumentVersionController::download()`, `WatermarkEventService` | — | `api_tests/Document/DocumentVersionTest.php` | 🔵 |
| Download audit record created on each download | `DocumentDownloadRecord` | — | `api_tests/Document/DocumentVersionTest.php` | 🔵 |
| Preview metadata fields exposed | `DocumentVersion` model attributes | — | `api_tests/Document/DocumentVersionTest.php` | 🔵 |

---

## Prompt 3 — Attachment & Evidence APIs

**Domain:** `app/Domain/Attachment/`  
**Service:** `app/Application/Attachment/AttachmentService.php`  
**Infrastructure:** `EncryptionService`, `FingerprintService`, `ExpiryEvaluator`  
**Controller:** `AttachmentController.php`, `AttachmentLinkController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Allowed MIME types: PDF/DOCX/XLSX/PNG/JPG | `FileConstraints::ALLOWED_MIME_TYPES` | `unit_tests/Domain/Attachment/FileConstraintsTest.php` | `api_tests/Attachment/AttachmentUploadTest.php` | ✅ |
| 25 MB max per file | `FileConstraints::MAX_SIZE_BYTES` | `unit_tests/Domain/Attachment/FileConstraintsTest.php` | `api_tests/Attachment/AttachmentUploadTest.php` | ✅ |
| 20 files per record max | `AttachmentService` (capacity check) | `unit_tests/Application/Attachment/AttachmentServiceTest.php` | `api_tests/Attachment/AttachmentUploadTest.php` | ✅ |
| SHA-256 fingerprint stored | `FingerprintService::compute()` | `unit_tests/Infrastructure/Security/FingerprintServiceTest.php` | `api_tests/Attachment/AttachmentUploadTest.php` | ✅ |
| Duplicate fingerprint rejected (409) | `AttachmentService` → `DuplicateAttachmentException` | `unit_tests/Application/Attachment/AttachmentServiceTest.php` | `api_tests/Attachment/AttachmentUploadTest.php` | ✅ |
| AES-256 encryption at rest | `EncryptionService` (AES-256-CBC) | `unit_tests/Infrastructure/Security/EncryptionServiceTest.php` | — | 🟡 |
| Encryption key required and validated | `EncryptionService` constructor | `unit_tests/Infrastructure/Security/EncryptionServiceTest.php` | — | 🟡 |
| LAN share link generation (token + URL) | `AttachmentService::createLink()` | — | `api_tests/Attachment/AttachmentLinkTest.php` | 🔵 |
| Max TTL 72 hours enforced | `LinkTtlConstraint` | `unit_tests/Domain/Attachment/LinkTtlConstraintTest.php` | — | 🟡 |
| Single-use link consumed on first resolution | `AttachmentService::resolveLink()`, `ExpiryEvaluator::isLinkConsumed()` | `unit_tests/Infrastructure/Security/ExpiryEvaluatorTest.php`, `unit_tests/Application/Attachment/AttachmentServiceTest.php` | `api_tests/Attachment/AttachmentLinkTest.php` | ✅ |
| 410 link_expired on expired link | `ExpiryEvaluator::isLinkExpired()` | `unit_tests/Infrastructure/Security/ExpiryEvaluatorTest.php` | `api_tests/Attachment/AttachmentLinkTest.php` | ✅ |
| 410 link_revoked on revoked link | `ExpiryEvaluator::isLinkRevoked()` | `unit_tests/Infrastructure/Security/ExpiryEvaluatorTest.php` | `api_tests/Attachment/AttachmentLinkTest.php` | ✅ |
| 410 link_consumed on replayed single-use | `ExpiryEvaluator::isLinkConsumed()` | `unit_tests/Application/Attachment/AttachmentServiceTest.php` | `api_tests/Attachment/AttachmentLinkTest.php` | ✅ |
| Non-single-use link allows multiple resolutions | `ExpiryEvaluator` | `unit_tests/Infrastructure/Security/ExpiryEvaluatorTest.php` | `api_tests/Attachment/AttachmentLinkTest.php` | ✅ |
| Attachment TTL auto-expiry (365-day default) | `AttachmentService`, `ExpireAttachmentsJob` | `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | — | 🟡 |

---

## Prompt 4 — Operations & Configuration Center APIs

**Domain:** `app/Domain/Configuration/`  
**Service:** `app/Application/Configuration/ConfigurationService.php`  
**Infrastructure:** `EloquentConfigurationRepository`  
**Controller:** `ConfigurationSetController.php`, `ConfigurationVersionController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Configuration set CRUD | `ConfigurationService::createSet()` | `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php`, `api_tests/Configuration/ConfigurationSetCrudTest.php` | ✅ |
| Config version auto-increment | `EloquentConfigurationRepository::createVersion()` | `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| Config set version listing (`GET /configuration/sets/{set}/versions`) | `ConfigurationVersionController::index()` | — | `api_tests/Configuration/ConfigurationVersionTest.php` | 🔵 |
| Config version show (`GET /configuration/versions/{version}`) | `ConfigurationVersionController::show()` | — | `api_tests/Configuration/ConfigurationVersionTest.php` | 🔵 |
| Canary rollout: 10% cap enforced | `CanaryConstraint::maxTargets()` | `unit_tests/Domain/Configuration/CanaryConstraintTest.php`, `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| 422 canary_cap_exceeded when > 10% | `ConfigurationService::startCanaryRollout()` → `CanaryCapExceededException` | `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| 24h minimum before promotion | `CanaryConstraint::canPromote()` | `unit_tests/Domain/Configuration/CanaryConstraintTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| 409 canary_not_ready if < 24h elapsed | `ConfigurationService::promoteVersion()` → `CanaryNotReadyToPromoteException` | `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| Rollback from canary or promoted | `ConfigurationService::rollbackVersion()` | `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| 409 invalid_rollout_transition | `InvalidRolloutTransitionException` | `unit_tests/Application/Configuration/ConfigurationServiceTest.php` | `api_tests/Configuration/ConfigurationVersionTest.php` | ✅ |
| Policy types (coupon, promotion, etc.) | `PolicyType` enum | — | `api_tests/Configuration/ConfigurationVersionTest.php` | 🔵 |
| Blacklist/whitelist rule types | `ConfigurationRule` model | — | `api_tests/Configuration/ConfigurationVersionTest.php` | 🔵 |
| Purchase limits in rules | `ConfigurationRule` model | — | — | ⚠️ tested via version creation only |

---

## Prompt 5 — Workflow & Approval APIs

**Domain:** `app/Domain/Workflow/`  
**Service:** `app/Application/Workflow/WorkflowService.php`  
**Infrastructure:** `EloquentWorkflowRepository`  
**Controller:** `WorkflowTemplateController.php`, `WorkflowInstanceController.php`, `WorkflowNodeController.php`, `TodoController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Workflow template creation with nodes | `WorkflowService::createTemplate()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Start workflow instance | `WorkflowService::startInstance()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| SLA 2 business days default | `SlaDefaults::calculateDueAt()` | `unit_tests/Domain/Workflow/SlaCalculationTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Approve node (sequential advance) | `WorkflowService::approve()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Reject node (mandatory reason) | `WorkflowService::reject()` → `ReasonRequiredException` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| 422 reason_required on reject without reason | `ReasonRequiredException` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Reassign node (mandatory reason) | `WorkflowService::reassign()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Add approver (new parallel node) | `WorkflowService::addApprover()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Withdraw instance (initiator or manage permission) | `WorkflowInstancePolicy::withdraw()`, `WorkflowService::withdraw()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| 409 workflow_terminated on terminal instance | `WorkflowTerminatedException` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/WorkflowApprovalTest.php` | ✅ |
| Parallel sign-off: all nodes at same order | `EloquentWorkflowRepository::allParallelNodesApproved()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | — | 🟡 |
| To-do queue created for assigned users | `TodoService::create()` | `unit_tests/Application/Workflow/WorkflowServiceTest.php` | `api_tests/Workflow/TodoQueueTest.php` | ✅ |
| SLA reminder to-do for overdue nodes | `SendSlaRemindersJob` | `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | — | 🟡 |
| No duplicate SLA reminder when reminded_at set | `SendSlaRemindersJob` | `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | — | 🟡 |
| Workflow template update/destroy CRUD paths | `WorkflowTemplateController` | — | `api_tests/Workflow/WorkflowTemplateCrudTest.php` | 🔵 |
| To-do queue: list, filter, complete | `TodoController` | — | `api_tests/Workflow/TodoQueueTest.php` | 🔵 |

---

## Prompt 6 — Sales Issue & Return/Exchange APIs

**Domain:** `app/Domain/Sales/`  
**Service:** `app/Application/Sales/SalesDocumentService.php`, `ReturnService.php`  
**Infrastructure:** `EloquentSalesRepository`  
**Controller:** `SalesDocumentController.php`, `ReturnController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Date-prefixed sequential document number | `DocumentNumberFormat::format()`, `EloquentSalesRepository::nextDocumentNumber()` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php`, `unit_tests/Domain/Sales/DocumentNumberTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| Sales show (`GET /sales/{document}`) | `SalesDocumentController::show()` | — | `api_tests/Sales/SalesDocumentLifecycleTest.php` | 🔵 |
| Per-site-per-day sequence reset | `DocumentNumberSequence` model + lockForUpdate | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| State machine: draft → reviewed | `SalesDocumentService::submit()` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| Sales update endpoint (line replacement, validation, transition guards) | `SalesDocumentService::update()`, `UpdateSalesDocumentRequest` | — | `api_tests/Sales/SalesDocumentUpdateTest.php` | 🔵 |
| State machine: reviewed → completed | `SalesDocumentService::complete()` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| State machine: → voided | `SalesDocumentService::void()` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| 409 invalid_sales_transition on illegal transition | `InvalidSalesTransitionException` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| Inventory movements on completion (stock-out) | `SalesDocumentService::complete()` → `InventoryMovement` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| Outbound linkage only on completed docs | `SalesDocumentService::linkOutbound()` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| 409 outbound_linkage_not_allowed | `OutboundLinkageNotAllowedException` | `unit_tests/Application/Sales/SalesDocumentServiceTest.php` | `api_tests/Sales/SalesDocumentLifecycleTest.php` | ✅ |
| Return only on completed sales | `ReturnService::createReturn()` | `unit_tests/Application/Sales/ReturnServiceTest.php` | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |
| Explicit exchange API endpoints | `ReturnController::storeExchange()`, `ReturnController::indexExchanges()`, `ReturnController::completeExchange()` | — | `api_tests/Returns/ExchangeProcessingTest.php` | ✅ |
| Return CRUD read/update coverage (index/show/update/exchange listing) | `ReturnController` | — | `api_tests/Returns/ReturnCrudTest.php` | 🔵 |
| Return creation requires manage-sales privilege | `SalesDocumentPolicy::createReturn()` | — | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |
| 10% restock fee default (non-defective) | `RestockFeePolicy::calculateFee()` | `unit_tests/Domain/Sales/RestockFeePolicyTest.php`, `unit_tests/Application/Sales/ReturnServiceTest.php` | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |
| 0% restock fee for defective | `RestockFeePolicy` + `ReturnReasonCode::isDefective()` | `unit_tests/Application/Sales/ReturnServiceTest.php` | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |
| 30-day qualifying window for non-defective | `RestockFeePolicy::isWithinQualifyingWindow()` | `unit_tests/Domain/Sales/RestockFeePolicyTest.php` | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |
| 422 return_window_expired | `ReturnWindowExpiredException` | `unit_tests/Application/Sales/ReturnServiceTest.php` | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |
| Compensating inventory movements on return | `ReturnService::completeReturn()` → `InventoryMovement` | `unit_tests/Application/Sales/ReturnServiceTest.php` | `api_tests/Returns/ReturnProcessingTest.php` | ✅ |

---

## Prompt 7 — Operational Resilience

**Jobs:** `app/Jobs/` (6 scheduled jobs)  
**Services:** `BackupMetadataService`, `MetricsRetentionService`, `StructuredLogger`  
**Controllers:** `Admin/BackupController.php`, `Admin/MetricsController.php`, `Admin/LogController.php`, `Admin/HealthController.php`, `Admin/FailedLoginController.php`, `Admin/ApprovalBacklogController.php`, `AuditEventController.php`

| Requirement | Implementing Class | Unit Test | API Test | Status |
|------------|-------------------|-----------|----------|--------|
| Daily backup orchestration (02:00) | `RunBackupJob` + `routes/console.php` | — | — | ⚠️ schedule verified in console.php only |
| Backup manifest (table counts + attachments) | `RunBackupJob::buildManifest()` | `unit_tests/Infrastructure/Backup/RunBackupJobTest.php` | — | ✅ |
| 14-day backup retention | `BackupMetadataService::pruneExpired()`, `PruneBackupsJob` | `unit_tests/Application/Backup/BackupMetadataServiceTest.php`, `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | `api_tests/Admin/AdminBackupTest.php` | ✅ |
| GET /admin/backups — backup history | `BackupController::index()` | — | `api_tests/Admin/AdminBackupTest.php` | 🔵 |
| POST /admin/backups — manual backup trigger | `BackupController::store()` | — | `api_tests/Admin/AdminBackupTest.php` | 🔵 |
| 90-day log retention | `StructuredLogger::prune()`, `PruneRetentionJob` | `unit_tests/Application/Logging/StructuredLoggerAuditTest.php`, `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | — | ✅ |
| 90-day metrics retention | `MetricsRetentionService::pruneExpired()`, `PruneRetentionJob` | `unit_tests/Application/Metrics/MetricsRetentionServiceTest.php`, `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | `api_tests/Admin/AdminMetricsTest.php` | ✅ |
| Queue depth metric snapshot job | `RecordQueueDepthJob` | `unit_tests/Infrastructure/Metrics/RecordQueueDepthJobTest.php` | — | 🟡 |
| Technical persistence writes emit audit events (log/metrics/idempotency) | `StructuredLogger`, `MetricsRetentionService`, `IdempotencyService` | `unit_tests/Application/Logging/StructuredLoggerAuditTest.php`, `unit_tests/Application/Metrics/MetricsRetentionServiceTest.php`, `unit_tests/Application/Idempotency/IdempotencyPersistenceAuditTest.php` | — | ✅ |
| Sensitive field redaction in logs | `StructuredLogger::sanitize()` | `unit_tests/Application/Logging/StructuredLoggerTest.php` | — | 🟡 |
| GET /admin/metrics — metrics snapshots | `MetricsController::index()` | — | `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| GET /admin/metrics?summary=1 — aggregation | `MetricsController::index()` | — | `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| GET /admin/logs — structured log browsing | `LogController::index()` | — | `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| GET /admin/health — health check | `HealthController::index()` | — | `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| GET /admin/failed-logins | `FailedLoginController::index()` | — | `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| GET /admin/locked-accounts | `FailedLoginController::lockedAccounts()` | — | — | ⚠️ tested via FailedLoginController unit; no dedicated API test |
| GET /admin/approval-backlog | `ApprovalBacklogController::index()` | — | `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| GET /audit/events — immutable audit browsing | `AuditEventController::index()` | — | `api_tests/Audit/AuditEventTest.php` | 🔵 |
| GET /audit/config-promotions — filtered view | `AuditEventController::configPromotions()` | — | `api_tests/Audit/AuditEventTest.php` | 🔵 |
| Expire attachment links (24h grace) | `ExpireAttachmentLinksJob` | `unit_tests/Infrastructure/Maintenance/MaintenanceJobsTest.php` | — | 🟡 |
| Expire attachments (status flip) | `ExpireAttachmentsJob` | — | — | ⚠️ job exists, tested indirectly via AttachmentService |
| LAN share link URL generation | `AttachmentService::createLink()` | — | `api_tests/Attachment/AttachmentLinkTest.php` | 🔵 |
| Single-use atomic consumption | `AttachmentService::resolveLink()` (DB transaction) | `unit_tests/Application/Attachment/AttachmentServiceTest.php` | `api_tests/Attachment/AttachmentLinkTest.php` | ✅ |
| Admin role required for admin endpoints | `BackupController`, `MetricsController`, etc. | — | `api_tests/Admin/AdminBackupTest.php`, `api_tests/Admin/AdminMetricsTest.php` | 🔵 |
| Auditor role can access audit/log endpoints | `AuditEventController`, `LogController` | — | `api_tests/Audit/AuditEventTest.php` | 🔵 |

---

## Cross-Cutting Concerns

| Concern | Implementing Class | Unit Test | API Test | Status |
|---------|-------------------|-----------|----------|--------|
| Audit event immutability (append-only) | `AuditEvent` model (`save()`, `delete()` throw LogicException) | `unit_tests/Application/Audit/AuditImmutabilityTest.php` | — | 🟡 |
| Audit correlation_id idempotency | `EloquentAuditEventRepository::record()` | `unit_tests/Application/Audit/AuditImmutabilityTest.php` | — | 🟡 |
| X-Idempotency-Key required on mutating routes | `IdempotencyMiddleware` | `unit_tests/Application/Idempotency/IdempotencyServiceTest.php` | `api_tests/Idempotency/IdempotencyKeyTest.php` | ✅ |
| Idempotency replayed response via X-Idempotency-Replay header | `IdempotencyMiddleware` | — | `api_tests/Idempotency/IdempotencyKeyTest.php` | 🔵 |
| Technical persistence writes are auditable | `StructuredLogger::log()/prune()`, `MetricsRetentionService::record()/pruneExpired()`, `IdempotencyService::storeResponse()` | `unit_tests/Application/Logging/StructuredLoggerAuditTest.php`, `unit_tests/Application/Metrics/MetricsRetentionServiceTest.php`, `unit_tests/Application/Idempotency/IdempotencyPersistenceAuditTest.php` | — | ✅ |
| Error envelope format: error.code/message/details | `bootstrap/app.php` exception handlers | — | `api_tests/Contract/ErrorEnvelopeTest.php` | 🔵 |
| No stack trace in error responses | `bootstrap/app.php` | — | `api_tests/Contract/ErrorEnvelopeTest.php` | 🔵 |
| Validation error format (422 + details map) | Laravel validation + exception handler | — | `api_tests/Contract/ValidationErrorTest.php` | 🔵 |
| Prompt-listed business-table PKs are UUIDs; framework-internal compatibility tables documented | UUID migrations for prompt-listed tables; Sanctum `personal_access_tokens` uses UUID morph key (`tokenable_id`) | `unit_tests/Infrastructure/Config/BusinessTableUuidPrimaryKeyTest.php` | `api_tests/Auth/LoginTest.php` | ✅ |
| All config defaults correct and non-null | `config/meridian.php` | `unit_tests/Infrastructure/Config/MeridianConfigLoadingTest.php` | — | 🟡 |
| ATTACHMENT_ENCRYPTION_KEY validated at boot | `EncryptionService` constructor | `unit_tests/Infrastructure/Security/EncryptionServiceTest.php` | — | 🟡 |
| AES-256 roundtrip (encrypt/decrypt) | `EncryptionService` | `unit_tests/Infrastructure/Security/EncryptionServiceTest.php` | — | 🟡 |
| SHA-256 fingerprint determinism and tamper detection | `FingerprintService` | `unit_tests/Infrastructure/Security/FingerprintServiceTest.php` | — | 🟡 |

---

## Coverage Summary

Coverage status in this document is maintained at the requirement-row level above.

Recent additions reflected in this revision:
1. Configuration set CRUD API coverage (`ConfigurationSetCrudTest`)
2. Workflow template CRUD API coverage (`WorkflowTemplateCrudTest`)
3. Sales update API coverage (`SalesDocumentUpdateTest`)
4. Return CRUD and exchange listing API coverage (`ReturnCrudTest`)
5. Backup manifest execution-path unit coverage (`RunBackupJobTest`)
6. Queue-depth metric snapshot coverage (`RecordQueueDepthJobTest`)

Known residual gaps remain those explicitly marked with ⚠️ in the requirement tables.

Note: FailedLoginAttempt record creation was previously a gap (AuthenticationService was not populating the table). This was fixed in Prompt 10: `AuthenticationService::recordFailedAttempt()` now creates a record for every auth failure path, verified by `api_tests/Auth/LockoutProgressionTest.php`.

---

## Test File Index

### Unit Tests (`repo/backend/unit_tests/`)

| File | Domain Coverage |
|------|----------------|
| `Application/Auth/AuthenticationServiceTest.php` | Login flow, lockout trigger, token issuance |
| `Application/Audit/AuditImmutabilityTest.php` | AuditEvent append-only invariant, correlation_id idempotency |
| `Application/Attachment/AttachmentServiceTest.php` | Upload guards, link resolution guards, token generation |
| `Application/Backup/BackupMetadataServiceTest.php` | Backup lifecycle, retention pruning |
| `Application/Configuration/ConfigurationServiceTest.php` | Canary rollout, version transitions |
| `Application/Document/DocumentServiceTest.php` | Document CRUD, version upload, archive |
| `Application/Idempotency/IdempotencyServiceTest.php` | Key validation, hash consistency |
| `Application/Logging/StructuredLoggerTest.php` | Sensitive field redaction |
| `Application/Metrics/MetricsRetentionServiceTest.php` | Snapshot recording, pruning |
| `Application/Sales/SalesDocumentServiceTest.php` | State machine, document numbering |
| `Application/Sales/ReturnServiceTest.php` | Return window, restock fee, inventory |
| `Application/Workflow/WorkflowServiceTest.php` | Approval flow, reject/reassign/withdraw guards |
| `Domain/Attachment/FileConstraintsTest.php` | MIME types, size limits |
| `Domain/Attachment/LinkTtlConstraintTest.php` | TTL 72h max |
| `Domain/Audit/IdempotencyKeyHashTest.php` | SHA-256 key hash |
| `Domain/Auth/LockoutPolicyTest.php` | 5-attempt threshold, 15-min window |
| `Domain/Auth/PasswordPolicyTest.php` | Length, complexity |
| `Domain/Configuration/CanaryConstraintTest.php` | 10% cap, 24h promotion |
| `Domain/Enums/EnumHelpersTest.php` | Enum helper predicates and transition matrices across domains |
| `Domain/Sales/DocumentNumberTest.php` | SITE-YYYYMMDD-NNNNNN format |
| `Domain/Sales/RestockFeePolicyTest.php` | Fee calculation, qualifying window |
| `Domain/Workflow/SlaCalculationTest.php` | Business-day SLA calculation |
| `Infrastructure/Backup/RunBackupJobTest.php` | Backup execution path, manifest, artifact persistence, audit |
| `Infrastructure/Config/MeridianConfigLoadingTest.php` | Config defaults, all critical keys |
| `Infrastructure/Maintenance/MaintenanceJobsTest.php` | All 5 scheduled jobs side-effects |
| `Infrastructure/Metrics/RecordQueueDepthJobTest.php` | Queue depth snapshot metric capture |
| `Infrastructure/Security/EncryptionServiceTest.php` | AES-256-CBC roundtrip, key validation |
| `Infrastructure/Security/ExpiryEvaluatorTest.php` | Link/attachment expiry states |
| `Infrastructure/Security/FingerprintServiceTest.php` | SHA-256 fingerprint determinism |

### API Tests (`repo/backend/api_tests/`)

| File | Domain Coverage |
|------|----------------|
| `Admin/AdminBackupTest.php` | Backup history, manual trigger, admin auth |
| `Admin/AdminMetricsTest.php` | Metrics, logs, health, failed-logins, backlog |
| `Attachment/AttachmentLinkTest.php` | Link lifecycle: create, resolve, expire, revoke, single-use |
| `Attachment/AttachmentUploadTest.php` | Upload, MIME validation, fingerprint, capacity |
| `Audit/AuditEventTest.php` | Audit browsing, filtering, config-promotions view |
| `Auth/LoginTest.php` | Login success/failure, validation, lockout response |
| `Auth/LogoutTest.php` | Token revocation |
| `Auth/LockoutProgressionTest.php` | Progressive lockout: 5 failures → 423 |
| `Auth/MeTest.php` | Authenticated user profile |
| `Authorization/PolicyEnforcementTest.php` | RBAC: 403, 401 enforcement |
| `Configuration/ConfigurationVersionTest.php` | Set/version CRUD, canary rollout lifecycle |
| `Configuration/ConfigurationSetCrudTest.php` | Configuration set index/show/update/destroy paths |
| `Contract/ErrorEnvelopeTest.php` | Error format, no stack trace |
| `Contract/IdempotencyHeaderTest.php` | Route registration, API prefix |
| `Contract/ValidationErrorTest.php` | 422 format and field details |
| `Document/DocumentCrudTest.php` | Document CRUD, archive, dept-scoping |
| `Document/DocumentVersionTest.php` | Version upload, superseded, download |
| `Idempotency/IdempotencyKeyTest.php` | Header enforcement, replay |
| `Returns/ReturnProcessingTest.php` | Return creation, window, restock fee, completion |
| `Returns/ReturnCrudTest.php` | Return and exchange listing/show/update/complete-exchange guards |
| `Sales/SalesDocumentLifecycleTest.php` | Full state machine, numbering, outbound |
| `Sales/SalesDocumentUpdateTest.php` | Sales update endpoint validation and transition behavior |
| `Workflow/TodoQueueTest.php` | To-do listing, filtering, completion |
| `Workflow/WorkflowApprovalTest.php` | Template, instance, approve/reject/reassign/add/withdraw |
| `Workflow/WorkflowTemplateCrudTest.php` | Workflow template index/update/destroy authorization and filters |
