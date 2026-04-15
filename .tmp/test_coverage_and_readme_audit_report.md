# Test Coverage Audit

## Scope and Method
- Audit mode: static inspection only.
- Inspected files:
  - `repo/backend/routes/api.php`
  - `repo/backend/bootstrap/app.php`
  - `repo/backend/api_tests/**/*.php`
  - `repo/backend/unit_tests/**/*.php`
  - `repo/run_tests.sh`
- API prefix evidence: `apiPrefix: 'api/v1'` in `repo/backend/bootstrap/app.php`.

## Backend Endpoint Inventory
Resolved from `repo/backend/routes/api.php` with `apiPrefix=api/v1`.

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

Total endpoints: 86

## API Test Mapping Table
Legend:
- Covered: yes/no (exact METHOD + PATH observed in tests)
- Type: true no-mock HTTP / HTTP with mocking / unit-only indirect

| Endpoint | Covered | Type | Test files | Evidence |
|---|---|---|---|---|
| POST /api/v1/auth/login | yes | true no-mock HTTP | `api_tests/Auth/LoginTest.php`, `api_tests/Contract/ValidationErrorTest.php` | `LoginTest.php:32`, `ValidationErrorTest.php:24` |
| GET /api/v1/links/{token} | yes | HTTP with mocking | `api_tests/Attachment/AttachmentLinkTest.php` | `AttachmentLinkTest.php:102` |
| POST /api/v1/auth/logout | yes | true no-mock HTTP | `api_tests/Auth/LogoutTest.php`, `api_tests/Idempotency/IdempotencyKeyTest.php` | `IdempotencyKeyTest.php:37` |
| GET /api/v1/auth/me | yes | true no-mock HTTP | `api_tests/Auth/MeTest.php`, `api_tests/Auth/TokenRevocationTest.php` | `MeTest.php:33`, `TokenRevocationTest.php:69` |
| GET /api/v1/roles | yes | true no-mock HTTP | `api_tests/Roles/RoleTest.php` | `RoleTest.php:47` |
| GET /api/v1/departments | yes | true no-mock HTTP | `api_tests/Departments/DepartmentTest.php` | `DepartmentTest.php:61` |
| POST /api/v1/departments | yes | true no-mock HTTP | `api_tests/Departments/DepartmentTest.php` | `DepartmentTest.php:113` |
| GET /api/v1/departments/{department} | yes | true no-mock HTTP | `api_tests/Departments/DepartmentTest.php` | `DepartmentTest.php:94` |
| PUT /api/v1/departments/{department} | yes | true no-mock HTTP | `api_tests/Departments/DepartmentTest.php` | `DepartmentTest.php:135` |
| PATCH /api/v1/departments/{department} | yes | true no-mock HTTP | `api_tests/Departments/DepartmentTest.php` | `DepartmentTest.php:187` |
| DELETE /api/v1/departments/{department} | yes | true no-mock HTTP | `api_tests/Departments/DepartmentTest.php` | `DepartmentTest.php:206` |
| GET /api/v1/documents | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php`, `api_tests/Contract/ErrorEnvelopeTest.php` | `DocumentCrudTest.php:175`, `ErrorEnvelopeTest.php:48` |
| POST /api/v1/documents | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php` | `DocumentCrudTest.php:68` |
| GET /api/v1/documents/{document} | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php` | `DocumentCrudTest.php:108` |
| PUT /api/v1/documents/{document} | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php` | `DocumentCrudTest.php:201` |
| PATCH /api/v1/documents/{document} | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php` | `DocumentCrudTest.php:246` |
| DELETE /api/v1/documents/{document} | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php` | `DocumentCrudTest.php:366` |
| POST /api/v1/documents/{document}/archive | yes | true no-mock HTTP | `api_tests/Document/DocumentCrudTest.php` | `DocumentCrudTest.php:272` |
| GET /api/v1/documents/{document}/versions | yes | HTTP with mocking | `api_tests/Document/DocumentVersionTest.php` | `DocumentVersionTest.php:141` |
| POST /api/v1/documents/{document}/versions | yes | HTTP with mocking | `api_tests/Document/DocumentVersionTest.php` | `DocumentVersionTest.php:58` |
| GET /api/v1/documents/{document}/versions/{versionId} | yes | HTTP with mocking | `api_tests/Document/DocumentVersionTest.php` | `DocumentVersionTest.php:160` |
| GET /api/v1/documents/{document}/versions/{versionId}/download | yes | HTTP with mocking | `api_tests/Document/DocumentVersionTest.php` | `DocumentVersionTest.php:194` |
| POST /api/v1/records/{type}/{id}/attachments | yes | HTTP with mocking | `api_tests/Attachment/AttachmentUploadTest.php` | `AttachmentUploadTest.php:66` |
| GET /api/v1/records/{type}/{id}/attachments | yes | HTTP with mocking | `api_tests/Attachment/AttachmentUploadTest.php` | `AttachmentUploadTest.php:103` |
| GET /api/v1/attachments/{attachment} | yes | HTTP with mocking | `api_tests/Attachment/AttachmentUploadTest.php` | `AttachmentUploadTest.php:320` |
| DELETE /api/v1/attachments/{attachment} | yes | HTTP with mocking | `api_tests/Attachment/AttachmentUploadTest.php` | `AttachmentUploadTest.php:382` |
| POST /api/v1/attachments/{attachment}/links | yes | HTTP with mocking | `api_tests/Attachment/AttachmentLinkTest.php` | `AttachmentLinkTest.php:69` |
| GET /api/v1/configuration/sets | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationSetCrudTest.php` | `ConfigurationSetCrudTest.php:55` |
| POST /api/v1/configuration/sets | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:61` |
| GET /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationSetCrudTest.php` | `ConfigurationSetCrudTest.php:108` |
| PUT /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationSetCrudTest.php` | `ConfigurationSetCrudTest.php:128` |
| PATCH /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationSetCrudTest.php` | `ConfigurationSetCrudTest.php:149` |
| DELETE /api/v1/configuration/sets/{set} | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationSetCrudTest.php` | `ConfigurationSetCrudTest.php:171` |
| GET /api/v1/configuration/sets/{set}/versions | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:148` |
| POST /api/v1/configuration/sets/{set}/versions | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:87` |
| GET /api/v1/configuration/versions/{version} | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:183` |
| POST /api/v1/configuration/versions/{version}/rollout | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:211` |
| POST /api/v1/configuration/versions/{version}/promote | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:378` |
| POST /api/v1/configuration/versions/{version}/rollback | yes | true no-mock HTTP | `api_tests/Configuration/ConfigurationVersionTest.php` | `ConfigurationVersionTest.php:446` |
| GET /api/v1/workflow/templates | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowTemplateCrudTest.php`, `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowTemplateCrudTest.php:55`, `WorkflowApprovalTest.php:620` |
| POST /api/v1/workflow/templates | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowTemplateCrudTest.php`, `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:107` |
| GET /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:528` |
| PUT /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowTemplateCrudTest.php`, `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowTemplateCrudTest.php:113`, `WorkflowApprovalTest.php:562` |
| PATCH /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowTemplateCrudTest.php` | `WorkflowTemplateCrudTest.php:137` |
| DELETE /api/v1/workflow/templates/{template} | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowTemplateCrudTest.php` | `WorkflowTemplateCrudTest.php:160` |
| POST /api/v1/workflow/instances | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php`, `api_tests/Admin/AdminMetricsTest.php` | `WorkflowApprovalTest.php:143`, `AdminMetricsTest.php:424` |
| GET /api/v1/workflow/instances/{instance} | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:220` |
| POST /api/v1/workflow/instances/{instance}/withdraw | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:684` |
| GET /api/v1/workflow/nodes/{node} | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:278` |
| POST /api/v1/workflow/nodes/{node}/approve | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php`, `api_tests/Sales/SalesDocumentLifecycleTest.php` | `WorkflowApprovalTest.php:213`, `SalesDocumentLifecycleTest.php:351` |
| POST /api/v1/workflow/nodes/{node}/reject | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php`, `api_tests/Admin/AdminMetricsTest.php` | `WorkflowApprovalTest.php:234`, `AdminMetricsTest.php:435` |
| POST /api/v1/workflow/nodes/{node}/reassign | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:270` |
| POST /api/v1/workflow/nodes/{node}/add-approver | yes | true no-mock HTTP | `api_tests/Workflow/WorkflowApprovalTest.php` | `WorkflowApprovalTest.php:307` |
| GET /api/v1/todo | yes | true no-mock HTTP | `api_tests/Workflow/TodoQueueTest.php` | `TodoQueueTest.php:73` |
| POST /api/v1/todo/{item}/complete | yes | true no-mock HTTP | `api_tests/Workflow/TodoQueueTest.php` | `TodoQueueTest.php:112` |
| GET /api/v1/sales | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php` | `SalesDocumentLifecycleTest.php:199` |
| POST /api/v1/sales | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php`, `api_tests/Sales/SalesDocumentUpdateTest.php` | `SalesDocumentLifecycleTest.php:65`, `SalesDocumentUpdateTest.php:39` |
| GET /api/v1/sales/{document} | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php` | `SalesDocumentLifecycleTest.php:169` |
| PUT /api/v1/sales/{document} | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentUpdateTest.php` | `SalesDocumentUpdateTest.php:49` |
| PATCH /api/v1/sales/{document} | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentUpdateTest.php` | `SalesDocumentUpdateTest.php:167` |
| DELETE /api/v1/sales/{document} | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php` | `SalesDocumentLifecycleTest.php:402` |
| POST /api/v1/sales/{document}/submit | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php`, `api_tests/Returns/ReturnProcessingTest.php` | `SalesDocumentLifecycleTest.php:234`, `ReturnProcessingTest.php:53` |
| POST /api/v1/sales/{document}/complete | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php`, `api_tests/Returns/ReturnProcessingTest.php` | `SalesDocumentLifecycleTest.php:251`, `ReturnProcessingTest.php:54` |
| POST /api/v1/sales/{document}/void | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php` | `SalesDocumentLifecycleTest.php:286` |
| POST /api/v1/sales/{document}/link-outbound | yes | true no-mock HTTP | `api_tests/Sales/SalesDocumentLifecycleTest.php` | `SalesDocumentLifecycleTest.php:319` |
| POST /api/v1/sales/{document}/returns | yes | true no-mock HTTP | `api_tests/Returns/ReturnCrudTest.php`, `api_tests/Returns/ReturnProcessingTest.php` | `ReturnCrudTest.php:64`, `ReturnProcessingTest.php:66` |
| GET /api/v1/sales/{document}/returns | yes | true no-mock HTTP | `api_tests/Returns/ReturnCrudTest.php` | `ReturnCrudTest.php:75` |
| POST /api/v1/sales/{document}/exchanges | yes | true no-mock HTTP | `api_tests/Returns/ExchangeProcessingTest.php`, `api_tests/Returns/ReturnCrudTest.php` | `ExchangeProcessingTest.php:67`, `ReturnCrudTest.php:70` |
| GET /api/v1/sales/{document}/exchanges | yes | true no-mock HTTP | `api_tests/Returns/ExchangeProcessingTest.php`, `api_tests/Returns/ReturnCrudTest.php` | `ExchangeProcessingTest.php:91`, `ReturnCrudTest.php:97` |
| GET /api/v1/returns/{return} | yes | true no-mock HTTP | `api_tests/Returns/ReturnCrudTest.php` | `ReturnCrudTest.php:117` |
| PUT /api/v1/returns/{return} | yes | true no-mock HTTP | `api_tests/Returns/ReturnCrudTest.php`, `api_tests/Returns/ReturnProcessingTest.php` | `ReturnCrudTest.php:136`, `ReturnProcessingTest.php:208` |
| POST /api/v1/returns/{return}/complete | yes | true no-mock HTTP | `api_tests/Returns/ReturnProcessingTest.php` | `ReturnProcessingTest.php:164` |
| POST /api/v1/exchanges/{return}/complete | yes | true no-mock HTTP | `api_tests/Returns/ExchangeProcessingTest.php`, `api_tests/Returns/ReturnCrudTest.php` | `ExchangeProcessingTest.php:110`, `ReturnCrudTest.php:174` |
| GET /api/v1/audit/events | yes | true no-mock HTTP | `api_tests/Audit/AuditEventTest.php`, `api_tests/Authorization/PolicyEnforcementTest.php` | `AuditEventTest.php:77`, `PolicyEnforcementTest.php:43` |
| GET /api/v1/audit/events/{event} | yes | true no-mock HTTP | `api_tests/Audit/AuditEventTest.php` | `AuditEventTest.php:154` |
| GET /api/v1/admin/backups | yes | HTTP with mocking | `api_tests/Admin/AdminBackupTest.php` | `AdminBackupTest.php:68` |
| POST /api/v1/admin/backups | yes | HTTP with mocking | `api_tests/Admin/AdminBackupTest.php` | `AdminBackupTest.php:119` |
| GET /api/v1/admin/metrics | yes | true no-mock HTTP | `api_tests/Admin/AdminMetricsTest.php` | `AdminMetricsTest.php:93` |
| GET /api/v1/admin/health | yes | true no-mock HTTP | `api_tests/Admin/AdminMetricsTest.php` | `AdminMetricsTest.php:200` |
| GET /api/v1/admin/logs | yes | true no-mock HTTP | `api_tests/Admin/AdminMetricsTest.php` | `AdminMetricsTest.php:152` |
| GET /api/v1/admin/failed-logins | yes | true no-mock HTTP | `api_tests/Admin/AdminMetricsTest.php` | `AdminMetricsTest.php:237` |
| GET /api/v1/admin/locked-accounts | yes | true no-mock HTTP | `api_tests/Admin/AdminMetricsTest.php` | `AdminMetricsTest.php:325` |
| GET /api/v1/admin/approval-backlog | yes | true no-mock HTTP | `api_tests/Admin/AdminMetricsTest.php` | `AdminMetricsTest.php:261` |
| GET /api/v1/admin/config-promotions | yes | true no-mock HTTP | `api_tests/Audit/AuditEventTest.php` | `AuditEventTest.php:171` |
| POST /api/v1/admin/users | yes | true no-mock HTTP | `api_tests/Admin/AdminUserTest.php` | `AdminUserTest.php:46` |
| PUT /api/v1/admin/users/{user}/password | yes | true no-mock HTTP | `api_tests/Admin/AdminUserTest.php` | `AdminUserTest.php:137` |

## API Test Classification

### 1) True No-Mock HTTP
API tests using real HTTP request helpers and no explicit service/controller/provider mocks in file.

Representative files:
- `repo/backend/api_tests/Auth/LoginTest.php`
- `repo/backend/api_tests/Auth/LogoutTest.php`
- `repo/backend/api_tests/Auth/MeTest.php`
- `repo/backend/api_tests/Sales/SalesDocumentLifecycleTest.php`
- `repo/backend/api_tests/Workflow/WorkflowApprovalTest.php`
- `repo/backend/api_tests/Configuration/ConfigurationSetCrudTest.php`
- `repo/backend/api_tests/Admin/AdminMetricsTest.php`

### 2) HTTP with Mocking
HTTP tests that mock/stub execution-path boundary (filesystem) via `Storage::fake('local')`.

- `repo/backend/api_tests/Attachment/AttachmentUploadTest.php` (line 20)
- `repo/backend/api_tests/Attachment/AttachmentLinkTest.php` (line 21)
- `repo/backend/api_tests/Document/DocumentVersionTest.php` (line 21)
- `repo/backend/api_tests/Admin/AdminBackupTest.php` (lines 114, 144)

### 3) Non-HTTP (unit/integration without HTTP)
- `repo/backend/api_tests/Contract/IdempotencyHeaderTest.php`
  - Uses `Route::getRoutes()` inspection instead of HTTP requests.

## Mock Detection

### API tests
- `Storage::fake('local')`
  - `repo/backend/api_tests/Attachment/AttachmentUploadTest.php:20`
  - `repo/backend/api_tests/Attachment/AttachmentLinkTest.php:21`
  - `repo/backend/api_tests/Document/DocumentVersionTest.php:21`
  - `repo/backend/api_tests/Admin/AdminBackupTest.php:114`
  - `repo/backend/api_tests/Admin/AdminBackupTest.php:144`

### Unit tests (selected high-signal examples)
- `Mockery::mock(...)` + `shouldReceive(...)` across service/repository layer:
  - `repo/backend/unit_tests/Application/Attachment/AttachmentServiceTest.php`
  - `repo/backend/unit_tests/Application/Auth/AuthenticationServiceTest.php`
  - `repo/backend/unit_tests/Application/Document/DocumentServiceTest.php`
  - `repo/backend/unit_tests/Application/Workflow/WorkflowServiceTest.php`
  - `repo/backend/unit_tests/Infrastructure/Http/IdempotencyMiddlewareTest.php`

## Coverage Summary
- Total endpoints: 86
- Endpoints with HTTP tests (any HTTP test type): 86
- Endpoints with true no-mock HTTP tests: 75

Computed metrics:
- HTTP coverage = 86 / 86 = 100.0%
- True API coverage = 75 / 86 = 87.2%

## Unit Test Summary
Unit test inventory (from `repo/backend/unit_tests/**/*.php`): 38 files.

Coverage present by area:
- Application: Attachment, Auth, Backup, Configuration, Idempotency, Logging, Metrics, Policy, Sales, Workflow
- Domain: Attachment, Auth, Audit, Configuration, Enums, Sales, Workflow
- Infrastructure: Backup, Config, Http middleware, Maintenance, Metrics, Security

Important modules likely weakly covered at unit level (relative to API surface):
- Controller-level behavior is mostly API-tested, not unit-tested (`app/Http/Controllers/Api/**`).
- Policy breadth vs endpoint breadth may still rely heavily on API-level checks (unit policy tests exist for selected policies).
- Job-level direct unit tests exist but are not exhaustive for every job under `app/Jobs/**`.

## API Observability Check
Strong observability in most API tests:
- Endpoint is explicit (`/api/v1/...`) in request calls.
- Inputs are explicit (payload/header/query params in test body).
- Response assertions are explicit (`assertStatus`, `assertJson`, `json(...)` checks).

Weak spots:
- Contract-only route-inspection test (`IdempotencyHeaderTest.php`) does not show request/response behavior.
- Some helper-heavy tests require jumping to helper methods to see full request input context.

## Test Quality and Sufficiency
- Success paths: strong coverage across auth, documents, configuration rollout, workflow, sales/returns, admin metrics.
- Failure paths: present and substantial (validation, auth failures, lockout, policy denial, invalid transitions).
- Edge cases: present (idempotency replay/conflict, lockout progression, rollback/promote constraints, attachment lifecycle states).
- Auth/permissions: heavily exercised (`PolicyEnforcementTest.php`, role-specific tests).
- Integration boundaries: HTTP+DB integration is strong; file storage boundary is deliberately faked in 4 API suites.

`run_tests.sh` check:
- Docker-based: PASS (`docker compose --profile test ...` usage throughout).
- Local dependency requirement: no package manager install steps; compliant with Docker-first policy.

## End-to-End Expectation
Project type is backend-only (README explicit). Full FE↔BE E2E expectation does not apply.

## Tests Check
- Static audit found no endpoint gaps at HTTP layer.
- Main strict-mode caveat: 11 endpoints are covered only by HTTP tests that fake storage boundary.

## Test Coverage Score (0-100)
90

## Score Rationale
- + High: full endpoint HTTP coverage (100%).
- + High: broad negative-path and authorization coverage.
- + High: substantial unit test footprint (38 files).
- - Deduction: true no-mock API coverage reduced by storage fakes on 11 endpoints.
- - Deduction: one contract file is non-HTTP route inspection only.

## Key Gaps
1. True no-mock coverage gap on storage-sensitive endpoints:
   - links resolve, attachment upload/list/show/delete/link creation, document version store/list/show/download, admin backup list/trigger.
2. Route-inspection-only contract test (`IdempotencyHeaderTest.php`) does not validate runtime middleware behavior directly.

## Confidence and Assumptions
- Confidence: high for route/test mapping; medium-high for no-mock classification impact.
- Assumptions:
  - `Storage::fake` is treated as mocking a boundary in strict mode.
  - `Sanctum::actingAs` is treated as auth test setup, not service-path mocking.
  - No runtime execution was performed.

## Test Coverage Audit Verdict
PARTIAL PASS (strong overall, but strict true no-mock criterion not fully met)

---

# README Audit

## README Location Check
- Required file exists: `repo/README.md`.

## Project Type Detection
- Declared near top: "backend-only".
- Inferred type: backend.

## Hard Gates

### Formatting
- PASS: structured markdown with clear sections/tables.

### Startup Instructions (Backend/Fullstack)
- PASS: includes both `docker compose up -d` and `docker-compose up -d`.

### Access Method
- PASS: URL + port clearly documented (`http://{LAN_HOST}:8000/api/v1`, ports table present).

### Verification Method
- PASS: explicit curl-based verification and operational checklist provided.

### Environment Rules (Docker-contained, no runtime package installs)
- PASS: no npm/pip/apt/runtime-install requirements in startup path.
- PASS: DB setup is Docker-contained (`docker compose` flows and seeded commands).

### Demo Credentials (auth exists)
- PASS: username/email/password provided for all roles (admin, manager, staff, auditor, viewer).

## Engineering Quality
- Tech stack clarity: strong.
- Architecture explanation: strong (runtime architecture and repository structure documented).
- Testing instructions: strong and explicit (including `run_tests.sh`, suite split, test service profile).
- Security/roles: strong (auth model and role credentials documented).
- Workflow/presentation: strong; long but coherent.

## High Priority Issues
- None.

## Medium Priority Issues
- Verification snippets use `jq` in examples without explicitly marking it optional. This can fail on hosts without `jq` even though core stack is Dockerized.

## Low Priority Issues
- README is large and could be split into operator quickstart vs deep reference to improve scanability.

## Hard Gate Failures
- None.

## README Verdict
PASS

---

# Final Verdicts

1. Test Coverage Audit: PARTIAL PASS
2. README Audit: PASS
