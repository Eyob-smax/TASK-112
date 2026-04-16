# Test Coverage Audit

## Scope and Method
- Audit mode: static inspection only (no test execution).
- Route source: `repo/backend/routes/api.php` with prefix evidence in `repo/backend/bootstrap/app.php` (`apiPrefix: 'api/v1'`).
- API tests inspected: `repo/backend/api_tests/**`.
- Unit tests inspected: `repo/backend/unit_tests/**`.

## Project Type Detection
- README declaration at top: `Project type: backend` in `repo/README.md`.
- Inferred type from repository structure: backend-only (`repo` contains `backend/` only; no frontend directory).
- Final type used for strict rules: **backend**.

## Backend Endpoint Inventory
Resolved unique endpoints (METHOD + full PATH with `/api/v1` prefix):

1. POST /api/v1/auth/login
2. GET /api/v1/links/{token}
3. POST /api/v1/auth/logout
4. GET /api/v1/auth/me
5. GET /api/v1/roles
6. GET /api/v1/departments
7. POST /api/v1/departments
8. GET /api/v1/departments/{department}
9. PUT /api/v1/departments/{department}
10. PATCH /api/v1/departments/{department}
11. DELETE /api/v1/departments/{department}
12. GET /api/v1/documents
13. POST /api/v1/documents
14. GET /api/v1/documents/{document}
15. PUT /api/v1/documents/{document}
16. PATCH /api/v1/documents/{document}
17. DELETE /api/v1/documents/{document}
18. POST /api/v1/documents/{document}/archive
19. GET /api/v1/documents/{document}/versions
20. POST /api/v1/documents/{document}/versions
21. GET /api/v1/documents/{document}/versions/{versionId}
22. GET /api/v1/documents/{document}/versions/{versionId}/download
23. POST /api/v1/records/{type}/{id}/attachments
24. GET /api/v1/records/{type}/{id}/attachments
25. GET /api/v1/attachments/{attachment}
26. DELETE /api/v1/attachments/{attachment}
27. POST /api/v1/attachments/{attachment}/links
28. GET /api/v1/configuration/sets
29. POST /api/v1/configuration/sets
30. GET /api/v1/configuration/sets/{set}
31. PUT /api/v1/configuration/sets/{set}
32. PATCH /api/v1/configuration/sets/{set}
33. DELETE /api/v1/configuration/sets/{set}
34. GET /api/v1/configuration/sets/{set}/versions
35. POST /api/v1/configuration/sets/{set}/versions
36. GET /api/v1/configuration/versions/{version}
37. POST /api/v1/configuration/versions/{version}/rollout
38. POST /api/v1/configuration/versions/{version}/promote
39. POST /api/v1/configuration/versions/{version}/rollback
40. GET /api/v1/workflow/templates
41. POST /api/v1/workflow/templates
42. GET /api/v1/workflow/templates/{template}
43. PUT /api/v1/workflow/templates/{template}
44. PATCH /api/v1/workflow/templates/{template}
45. DELETE /api/v1/workflow/templates/{template}
46. POST /api/v1/workflow/instances
47. GET /api/v1/workflow/instances/{instance}
48. POST /api/v1/workflow/instances/{instance}/withdraw
49. GET /api/v1/workflow/nodes/{node}
50. POST /api/v1/workflow/nodes/{node}/approve
51. POST /api/v1/workflow/nodes/{node}/reject
52. POST /api/v1/workflow/nodes/{node}/reassign
53. POST /api/v1/workflow/nodes/{node}/add-approver
54. GET /api/v1/todo
55. POST /api/v1/todo/{item}/complete
56. GET /api/v1/sales
57. POST /api/v1/sales
58. GET /api/v1/sales/{document}
59. PUT /api/v1/sales/{document}
60. PATCH /api/v1/sales/{document}
61. DELETE /api/v1/sales/{document}
62. POST /api/v1/sales/{document}/submit
63. POST /api/v1/sales/{document}/complete
64. POST /api/v1/sales/{document}/void
65. POST /api/v1/sales/{document}/link-outbound
66. POST /api/v1/sales/{document}/returns
67. GET /api/v1/sales/{document}/returns
68. POST /api/v1/sales/{document}/exchanges
69. GET /api/v1/sales/{document}/exchanges
70. GET /api/v1/returns/{return}
71. PUT /api/v1/returns/{return}
72. POST /api/v1/returns/{return}/complete
73. POST /api/v1/exchanges/{return}/complete
74. GET /api/v1/audit/events
75. GET /api/v1/audit/events/{event}
76. GET /api/v1/admin/backups
77. POST /api/v1/admin/backups
78. GET /api/v1/admin/metrics
79. GET /api/v1/admin/health
80. GET /api/v1/admin/logs
81. GET /api/v1/admin/failed-logins
82. GET /api/v1/admin/locked-accounts
83. GET /api/v1/admin/approval-backlog
84. GET /api/v1/admin/config-promotions
85. POST /api/v1/admin/users
86. PUT /api/v1/admin/users/{user}/password

## API Test Mapping Table
Legend:
- Test type values: true no-mock HTTP, HTTP with mocking, unit-only/indirect.
- Endpoint marked true no-mock HTTP when at least one HTTP test hits exact route without mocking transport/controller/service/provider in execution path.

| # | Endpoint | Covered | Test type | Test files | Evidence |
|---|---|---|---|---|---|
| 1 | POST /api/v1/auth/login | yes | true no-mock HTTP | Auth/LoginTest.php | `postJson('/api/v1/auth/login')` in `it('authenticates...')` |
| 2 | GET /api/v1/links/{token} | yes | true no-mock HTTP | Attachment/AttachmentNoMockStorageTest.php | `get('/api/v1/links/{token}')` in no-mock attachment flow |
| 3 | POST /api/v1/auth/logout | yes | true no-mock HTTP | Auth/LogoutTest.php | `postJson('/api/v1/auth/logout')` |
| 4 | GET /api/v1/auth/me | yes | true no-mock HTTP | Auth/MeTest.php | `getJson('/api/v1/auth/me')` |
| 5 | GET /api/v1/roles | yes | true no-mock HTTP | Roles/RoleTest.php | `getJson('/api/v1/roles')` |
| 6 | GET /api/v1/departments | yes | true no-mock HTTP | Departments/DepartmentTest.php | `getJson('/api/v1/departments')` |
| 7 | POST /api/v1/departments | yes | true no-mock HTTP | Departments/DepartmentTest.php, Idempotency/IdempotencyKeyTest.php | `postJson('/api/v1/departments')` |
| 8 | GET /api/v1/departments/{department} | yes | true no-mock HTTP | Departments/DepartmentTest.php | `getJson('/api/v1/departments/{id}')` |
| 9 | PUT /api/v1/departments/{department} | yes | true no-mock HTTP | Departments/DepartmentTest.php | `putJson('/api/v1/departments/{id}')` |
| 10 | PATCH /api/v1/departments/{department} | yes | true no-mock HTTP | Departments/DepartmentTest.php | `patchJson('/api/v1/departments/{id}')` |
| 11 | DELETE /api/v1/departments/{department} | yes | true no-mock HTTP | Departments/DepartmentTest.php | `deleteJson('/api/v1/departments/{id}')` |
| 12 | GET /api/v1/documents | yes | true no-mock HTTP | Document/DocumentCrudTest.php, Contract/ErrorEnvelopeTest.php | `getJson('/api/v1/documents')` |
| 13 | POST /api/v1/documents | yes | true no-mock HTTP | Document/DocumentCrudTest.php | `postJson('/api/v1/documents')` |
| 14 | GET /api/v1/documents/{document} | yes | true no-mock HTTP | Document/DocumentCrudTest.php | `getJson('/api/v1/documents/{id}')` |
| 15 | PUT /api/v1/documents/{document} | yes | true no-mock HTTP | Document/DocumentCrudTest.php | `putJson('/api/v1/documents/{id}')` |
| 16 | PATCH /api/v1/documents/{document} | yes | true no-mock HTTP | Document/DocumentCrudTest.php | `patchJson('/api/v1/documents/{id}')` |
| 17 | DELETE /api/v1/documents/{document} | yes | true no-mock HTTP | Document/DocumentCrudTest.php | `deleteJson('/api/v1/documents/{id}')` |
| 18 | POST /api/v1/documents/{document}/archive | yes | true no-mock HTTP | Document/DocumentCrudTest.php | `postJson('/api/v1/documents/{id}/archive')` |
| 19 | GET /api/v1/documents/{document}/versions | yes | true no-mock HTTP | Document/DocumentVersionNoMockStorageTest.php | `getJson('/api/v1/documents/{id}/versions')` |
| 20 | POST /api/v1/documents/{document}/versions | yes | true no-mock HTTP | Document/DocumentVersionNoMockStorageTest.php | `postJson('/api/v1/documents/{id}/versions')` |
| 21 | GET /api/v1/documents/{document}/versions/{versionId} | yes | true no-mock HTTP | Document/DocumentVersionNoMockStorageTest.php | `getJson('/api/v1/documents/{id}/versions/{versionId}')` |
| 22 | GET /api/v1/documents/{document}/versions/{versionId}/download | yes | true no-mock HTTP | Document/DocumentVersionNoMockStorageTest.php | `get('/api/v1/documents/{id}/versions/{versionId}/download')` |
| 23 | POST /api/v1/records/{type}/{id}/attachments | yes | true no-mock HTTP | Attachment/AttachmentNoMockStorageTest.php | `postJson('/api/v1/records/document/{id}/attachments')` |
| 24 | GET /api/v1/records/{type}/{id}/attachments | yes | true no-mock HTTP | Attachment/AttachmentNoMockStorageTest.php | `getJson('/api/v1/records/document/{id}/attachments')` |
| 25 | GET /api/v1/attachments/{attachment} | yes | true no-mock HTTP | Attachment/AttachmentNoMockStorageTest.php | `getJson('/api/v1/attachments/{id}')` |
| 26 | DELETE /api/v1/attachments/{attachment} | yes | true no-mock HTTP | Attachment/AttachmentNoMockStorageTest.php | `deleteJson('/api/v1/attachments/{id}')` |
| 27 | POST /api/v1/attachments/{attachment}/links | yes | true no-mock HTTP | Attachment/AttachmentNoMockStorageTest.php | `postJson('/api/v1/attachments/{id}/links')` |
| 28 | GET /api/v1/configuration/sets | yes | true no-mock HTTP | Configuration/ConfigurationSetCrudTest.php | `getJson('/api/v1/configuration/sets')` |
| 29 | POST /api/v1/configuration/sets | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `postJson('/api/v1/configuration/sets')` |
| 30 | GET /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | Configuration/ConfigurationSetCrudTest.php, Configuration/ConfigurationVersionTest.php | `getJson('/api/v1/configuration/sets/{id}')` |
| 31 | PUT /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | Configuration/ConfigurationSetCrudTest.php, Configuration/ConfigurationVersionTest.php | `putJson('/api/v1/configuration/sets/{id}')` |
| 32 | PATCH /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | Configuration/ConfigurationSetCrudTest.php | `patchJson('/api/v1/configuration/sets/{id}')` |
| 33 | DELETE /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | Configuration/ConfigurationSetCrudTest.php | `deleteJson('/api/v1/configuration/sets/{id}')` |
| 34 | GET /api/v1/configuration/sets/{set}/versions | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `getJson('/api/v1/configuration/sets/{id}/versions')` |
| 35 | POST /api/v1/configuration/sets/{set}/versions | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `postJson('/api/v1/configuration/sets/{id}/versions')` |
| 36 | GET /api/v1/configuration/versions/{version} | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `getJson('/api/v1/configuration/versions/{id}')` |
| 37 | POST /api/v1/configuration/versions/{version}/rollout | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `postJson('/api/v1/configuration/versions/{id}/rollout')` |
| 38 | POST /api/v1/configuration/versions/{version}/promote | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `postJson('/api/v1/configuration/versions/{id}/promote')` |
| 39 | POST /api/v1/configuration/versions/{version}/rollback | yes | true no-mock HTTP | Configuration/ConfigurationVersionTest.php | `postJson('/api/v1/configuration/versions/{id}/rollback')` |
| 40 | GET /api/v1/workflow/templates | yes | true no-mock HTTP | Workflow/WorkflowTemplateCrudTest.php, Workflow/WorkflowApprovalTest.php | `getJson('/api/v1/workflow/templates')` |
| 41 | POST /api/v1/workflow/templates | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `postJson('/api/v1/workflow/templates')` |
| 42 | GET /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `getJson('/api/v1/workflow/templates/{id}')` |
| 43 | PUT /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | Workflow/WorkflowTemplateCrudTest.php, Workflow/WorkflowApprovalTest.php | `putJson('/api/v1/workflow/templates/{id}')` |
| 44 | PATCH /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | Workflow/WorkflowTemplateCrudTest.php | `patchJson('/api/v1/workflow/templates/{id}')` |
| 45 | DELETE /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | Workflow/WorkflowTemplateCrudTest.php | `deleteJson('/api/v1/workflow/templates/{id}')` |
| 46 | POST /api/v1/workflow/instances | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php, Sales/SalesDocumentLifecycleTest.php | `postJson('/api/v1/workflow/instances')` |
| 47 | GET /api/v1/workflow/instances/{instance} | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `getJson('/api/v1/workflow/instances/{id}')` |
| 48 | POST /api/v1/workflow/instances/{instance}/withdraw | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `postJson('/api/v1/workflow/instances/{id}/withdraw')` |
| 49 | GET /api/v1/workflow/nodes/{node} | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `getJson('/api/v1/workflow/nodes/{id}')` |
| 50 | POST /api/v1/workflow/nodes/{node}/approve | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php, Sales/SalesDocumentLifecycleTest.php | `postJson('/api/v1/workflow/nodes/{id}/approve')` |
| 51 | POST /api/v1/workflow/nodes/{node}/reject | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php, Admin/AdminMetricsTest.php | `postJson('/api/v1/workflow/nodes/{id}/reject')` |
| 52 | POST /api/v1/workflow/nodes/{node}/reassign | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `postJson('/api/v1/workflow/nodes/{id}/reassign')` |
| 53 | POST /api/v1/workflow/nodes/{node}/add-approver | yes | true no-mock HTTP | Workflow/WorkflowApprovalTest.php | `postJson('/api/v1/workflow/nodes/{id}/add-approver')` |
| 54 | GET /api/v1/todo | yes | true no-mock HTTP | Workflow/TodoQueueTest.php | `getJson('/api/v1/todo')` |
| 55 | POST /api/v1/todo/{item}/complete | yes | true no-mock HTTP | Workflow/TodoQueueTest.php | `postJson('/api/v1/todo/{id}/complete')` |
| 56 | GET /api/v1/sales | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php | `getJson('/api/v1/sales')` |
| 57 | POST /api/v1/sales | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php, Sales/SalesDocumentUpdateTest.php, Returns/* | `postJson('/api/v1/sales')` |
| 58 | GET /api/v1/sales/{document} | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php | `getJson('/api/v1/sales/{id}')` |
| 59 | PUT /api/v1/sales/{document} | yes | true no-mock HTTP | Sales/SalesDocumentUpdateTest.php | `putJson('/api/v1/sales/{id}')` |
| 60 | PATCH /api/v1/sales/{document} | yes | true no-mock HTTP | Sales/SalesDocumentUpdateTest.php | `patchJson('/api/v1/sales/{id}')` |
| 61 | DELETE /api/v1/sales/{document} | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php | `deleteJson('/api/v1/sales/{id}')` |
| 62 | POST /api/v1/sales/{document}/submit | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php, Returns/* | `postJson('/api/v1/sales/{id}/submit')` |
| 63 | POST /api/v1/sales/{document}/complete | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php, Returns/* | `postJson('/api/v1/sales/{id}/complete')` |
| 64 | POST /api/v1/sales/{document}/void | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php | `postJson('/api/v1/sales/{id}/void')` |
| 65 | POST /api/v1/sales/{document}/link-outbound | yes | true no-mock HTTP | Sales/SalesDocumentLifecycleTest.php | `postJson('/api/v1/sales/{id}/link-outbound')` |
| 66 | POST /api/v1/sales/{document}/returns | yes | true no-mock HTTP | Returns/ReturnProcessingTest.php, Returns/ReturnCrudTest.php | `postJson('/api/v1/sales/{id}/returns')` |
| 67 | GET /api/v1/sales/{document}/returns | yes | true no-mock HTTP | Returns/ReturnCrudTest.php | `getJson('/api/v1/sales/{id}/returns')` |
| 68 | POST /api/v1/sales/{document}/exchanges | yes | true no-mock HTTP | Returns/ExchangeProcessingTest.php, Returns/ReturnCrudTest.php | `postJson('/api/v1/sales/{id}/exchanges')` |
| 69 | GET /api/v1/sales/{document}/exchanges | yes | true no-mock HTTP | Returns/ExchangeProcessingTest.php, Returns/ReturnCrudTest.php | `getJson('/api/v1/sales/{id}/exchanges')` |
| 70 | GET /api/v1/returns/{return} | yes | true no-mock HTTP | Returns/ReturnCrudTest.php | `getJson('/api/v1/returns/{id}')` |
| 71 | PUT /api/v1/returns/{return} | yes | true no-mock HTTP | Returns/ReturnProcessingTest.php, Returns/ReturnCrudTest.php | `putJson('/api/v1/returns/{id}')` |
| 72 | POST /api/v1/returns/{return}/complete | yes | true no-mock HTTP | Returns/ReturnProcessingTest.php | `postJson('/api/v1/returns/{id}/complete')` |
| 73 | POST /api/v1/exchanges/{return}/complete | yes | true no-mock HTTP | Returns/ExchangeProcessingTest.php, Returns/ReturnCrudTest.php | `postJson('/api/v1/exchanges/{id}/complete')` |
| 74 | GET /api/v1/audit/events | yes | true no-mock HTTP | Audit/AuditEventTest.php, Authorization/PolicyEnforcementTest.php | `getJson('/api/v1/audit/events')` |
| 75 | GET /api/v1/audit/events/{event} | yes | true no-mock HTTP | Audit/AuditEventTest.php | `getJson('/api/v1/audit/events/{id}')` |
| 76 | GET /api/v1/admin/backups | yes | true no-mock HTTP | Admin/AdminBackupNoMockStorageTest.php | `getJson('/api/v1/admin/backups')` |
| 77 | POST /api/v1/admin/backups | yes | true no-mock HTTP | Admin/AdminBackupNoMockStorageTest.php | `postJson('/api/v1/admin/backups')` |
| 78 | GET /api/v1/admin/metrics | yes | true no-mock HTTP | Admin/AdminMetricsTest.php | `getJson('/api/v1/admin/metrics')` |
| 79 | GET /api/v1/admin/health | yes | true no-mock HTTP | Admin/AdminMetricsTest.php | `getJson('/api/v1/admin/health')` |
| 80 | GET /api/v1/admin/logs | yes | true no-mock HTTP | Admin/AdminMetricsTest.php | `getJson('/api/v1/admin/logs')` |
| 81 | GET /api/v1/admin/failed-logins | yes | true no-mock HTTP | Admin/AdminMetricsTest.php | `getJson('/api/v1/admin/failed-logins')` |
| 82 | GET /api/v1/admin/locked-accounts | yes | true no-mock HTTP | Admin/AdminMetricsTest.php | `getJson('/api/v1/admin/locked-accounts')` |
| 83 | GET /api/v1/admin/approval-backlog | yes | true no-mock HTTP | Admin/AdminMetricsTest.php | `getJson('/api/v1/admin/approval-backlog')` |
| 84 | GET /api/v1/admin/config-promotions | yes | true no-mock HTTP | Audit/AuditEventTest.php | `getJson('/api/v1/admin/config-promotions')` |
| 85 | POST /api/v1/admin/users | yes | true no-mock HTTP | Admin/AdminUserTest.php | `postJson('/api/v1/admin/users')` |
| 86 | PUT /api/v1/admin/users/{user}/password | yes | true no-mock HTTP | Admin/AdminUserTest.php | `putJson('/api/v1/admin/users/{id}/password')` |

## API Test Classification

### 1) True No-Mock HTTP
- Auth/LoginTest.php
- Auth/LogoutTest.php
- Auth/MeTest.php
- Auth/LockoutProgressionTest.php
- Auth/TokenRevocationTest.php
- Authorization/PolicyEnforcementTest.php
- Departments/DepartmentTest.php
- Document/DocumentCrudTest.php
- Document/DocumentVersionNoMockStorageTest.php
- Attachment/AttachmentNoMockStorageTest.php
- Configuration/ConfigurationSetCrudTest.php
- Configuration/ConfigurationVersionTest.php
- Idempotency/IdempotencyKeyTest.php
- Workflow/WorkflowTemplateCrudTest.php
- Workflow/WorkflowApprovalTest.php
- Workflow/TodoQueueTest.php
- Sales/SalesDocumentLifecycleTest.php
- Sales/SalesDocumentUpdateTest.php
- Returns/ReturnCrudTest.php
- Returns/ReturnProcessingTest.php
- Returns/ExchangeProcessingTest.php
- Audit/AuditEventTest.php
- Roles/RoleTest.php
- Admin/AdminUserTest.php
- Admin/AdminMetricsTest.php
- Admin/AdminBackupNoMockStorageTest.php
- Contract/ErrorEnvelopeTest.php
- Contract/ValidationErrorTest.php

### 2) HTTP with Mocking
- Attachment/AttachmentUploadTest.php
  - mocked component: storage provider via `Storage::fake('local')`.
- Attachment/AttachmentLinkTest.php
  - mocked component: storage provider via `Storage::fake('local')`.
- Document/DocumentVersionTest.php
  - mocked component: storage provider via `Storage::fake('local')`.
- Admin/AdminBackupTest.php
  - mocked component: storage provider via `Storage::fake('local')`.

### 3) Non-HTTP (unit/integration without HTTP)
- Contract/IdempotencyHeaderTest.php
  - uses `Route::getRoutes()` route collection assertions instead of sending HTTP requests.

## Mock Detection (Strict)
- `repo/backend/api_tests/Attachment/AttachmentUploadTest.php`
  - `Storage::fake('local')` in setup.
- `repo/backend/api_tests/Attachment/AttachmentLinkTest.php`
  - `Storage::fake('local')` in setup.
- `repo/backend/api_tests/Document/DocumentVersionTest.php`
  - `Storage::fake('local')` in setup.
- `repo/backend/api_tests/Admin/AdminBackupTest.php`
  - `Storage::fake('local')` in backup trigger tests.
- No evidence found of mocked transport layer, mocked controllers, or DI container service overrides in API tests.

## Coverage Summary
- Total endpoints: **86**
- Endpoints with HTTP tests: **86**
- Endpoints with TRUE no-mock HTTP tests: **86**
- HTTP coverage: **100.0%**
- True API coverage: **100.0%**

## Unit Test Analysis

### Backend Unit Tests
- Unit test files present: 36 files under `repo/backend/unit_tests` (excluding Pest/TestCase bootstrap files).
- Covered backend module areas (from file names and paths):
  - Application services: Auth, Attachment, Backup, Configuration, Document, Idempotency, Logging, Metrics, Sales, Workflow.
  - Domain logic: Auth policies, attachment constraints, audit hashing, configuration canary constraints, sales numbering/restock, workflow SLA.
  - Infrastructure: security (encryption/fingerprint/expiry), HTTP middleware (`IdempotencyMiddleware`, `MaskSensitiveFields`), jobs and config loading.
  - Policies: `DocumentPolicy`, `SalesDocumentPolicy`.

Important backend modules not unit-tested directly (file-level evidence from `app/Http/Controllers/Api` and absence in unit test naming):
- API controllers are primarily covered by HTTP tests, not isolated unit tests:
  - `AuthController`, `DocumentController`, `SalesDocumentController`, `ReturnController`, `Workflow*Controller`, admin controllers.
- Request validation classes in `app/Http/Requests` are not represented as direct unit test files.
- Repository-specific unit tests are not explicitly present as dedicated repository test files.

### Frontend Unit Tests (strict check)
- Frontend test files: **NONE**
- Frontend frameworks/tools detected: **NONE**
- Frontend components/modules covered: **NONE**
- Important frontend components/modules not tested: **Not applicable (backend project type; no frontend module tree detected).**
- Mandatory verdict: **Frontend unit tests: MISSING (not required by declared backend-only scope).**

### Cross-Layer Observation
- Backend-only repository; no frontend layer detected. Cross-layer balance check is not applicable.

## API Observability Check
- Strong in most API tests:
  - endpoint explicitly shown via `getJson/postJson/putJson/patchJson/deleteJson`.
  - request payloads and headers are typically explicit.
  - response assertions often validate structure and key fields.
- Weak areas:
  - `Contract/IdempotencyHeaderTest.php` does not perform request/response assertions (route metadata only).
  - some cases in `Admin/AdminMetricsTest.php` and workflow flows assert mainly status or selected fragments, not full response semantics.

## Test Quality and Sufficiency
- Success paths: strong breadth across auth, CRUD, workflow, sales, returns, admin operations.
- Failure cases and validation: present in login validation, policy enforcement, idempotency, and contract tests.
- Edge cases: present in lockout progression, exchange/return rules, workflow authorization transitions.
- Auth/permissions: strong coverage via authorization and role-based tests.
- Integration boundaries:
  - positive: dedicated no-mock storage tests for attachment/document versions/backups.
  - risk: storage-heavy suites still include parallel mocked storage variants.
- Assertion quality: mostly meaningful field assertions, not only superficial status checks.
- `run_tests.sh` check:
  - Docker-based execution: **OK**.
  - Host-local package install dependency: **none detected**.
  - Note: script requires host shell to invoke (`bash` on Windows), but test execution itself remains containerized.

## End-to-End Expectations
- Fullstack FE<->BE E2E expectation is not applicable (project type backend).

## Tests Check
- Route-to-HTTP test mapping completeness: PASS.
- No-mock HTTP existence per endpoint family: PASS.
- Mock usage transparency: PARTIAL (mixed strategy; mocked and no-mock suites coexist).
- Non-HTTP contract tests mixed into API suite: FLAGGED.

## Test Coverage Score (0-100)
- **92/100**

## Score Rationale
- + Full endpoint HTTP coverage and full true no-mock endpoint coverage.
- + Strong breadth across core business flows and authorization.
- - Presence of mocked storage in several API suites lowers strict confidence for those individual suites.
- - A subset of API-suite tests are route metadata checks (non-HTTP), and some assertions are shallow.

## Key Gaps
1. API suite purity gap: `Contract/IdempotencyHeaderTest.php` is non-HTTP and should be separated from API execution-path coverage metrics.
2. Mixed mocking strategy in API suite: storage-mocked tests coexist with no-mock tests; strict pipelines should prioritize no-mock as canonical evidence.
3. Some endpoint tests emphasize status/shape over richer response semantics and domain invariants.

## Confidence and Assumptions
- Confidence: **high** for endpoint inventory and method/path mapping.
- Assumption: `Route::apiResource` expansion follows Laravel defaults (index/store/show/update/destroy with PUT+PATCH accepted for update).
- Limitation: static-only audit; runtime behavior, flakiness, and data-race issues not evaluated.

## Test Coverage Verdict
- **PASS (strict static audit)**

---

# README Audit

## README Location Check
- Required location: `repo/README.md`
- Result: **present**

## Hard Gate Checks

### Formatting
- Result: **PASS**
- Evidence: structured markdown sections, tables, code blocks, clear headings.

### Startup Instructions (backend/fullstack rule)
- Required: include `docker-compose up`.
- Result: **PASS**
- Evidence: `Quick Start` and `Starting the System` include both `docker compose up -d` and legacy `docker-compose up -d`.

### Access Method
- Required: URL + port for backend/web.
- Result: **PASS**
- Evidence: explicit API port 8000 and base URL format `http://{LAN_HOST}:8000/api/v1`.

### Verification Method
- Required: explain how to confirm system works.
- Result: **PASS**
- Evidence: curl login + health checks + operational checklist (`supervisorctl`, cron log, worker log, health checks).

### Environment Rules (strict Docker containment)
- Disallowed patterns: `npm install`, `pip install`, `apt-get`, runtime installs, manual DB setup.
- Result: **PASS**
- Evidence: setup and tests are Docker Compose based; no host package-manager installation instructions found.

### Demo Credentials (conditional on auth)
- Auth detected: yes (`/auth/login`, Sanctum tokens).
- Required: username/email/password and all roles.
- Result: **PASS**
- Evidence: README includes admin, manager, staff, auditor, viewer credentials with username/email/password.

## Engineering Quality
- Tech stack clarity: strong (Laravel/MySQL/Sanctum/RBAC/queue/storage listed).
- Architecture explanation: strong (runtime architecture and process model documented).
- Testing instructions: strong and Docker-first.
- Security/roles: good role credential disclosure for demo, role model explained.
- Workflows/operations: strong operational verification checklist.
- Presentation quality: high readability.

## High Priority Issues
- None.

## Medium Priority Issues
1. README mixes strict backend operation with substantial optional operational detail; a shorter critical-path quickstart could reduce onboarding risk.

## Low Priority Issues
1. Uses both `docker compose` and legacy `docker-compose`; useful for compatibility but slightly redundant.

## Hard Gate Failures
- None.

## README Verdict
- **PASS**

---

# Final Combined Verdicts
- Test Coverage Audit: **PASS** (score: 92/100)
- README Audit: **PASS**
