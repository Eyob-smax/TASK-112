# Fix-Check Report (Round 2)

Reference baseline: `.tmp/audit_report-1.md`

## 1. Static Boundary
- Re-check type: **static-only**
- Not executed: app runtime, Docker, tests, migrations
- Evidence basis: current source + current test files

## 2. Round-2 Result Summary
- Previously reported issues reviewed: **8**
- **Fixed**: 8
- **Partially Fixed**: 0
- **Not Fixed**: 0

## 3. Detailed Re-Check

### Issue 1 (Blocker)
**Previous**: Object-level authorization gaps on document/sales/returns mutation paths.

**Round-2 status**: **Fixed**

**Evidence**:
- Document archive now enforces department/cross-scope: `repo/backend/app/Policies/DocumentPolicy.php:65`, `repo/backend/app/Policies/DocumentPolicy.php:68`
- Sales object-level policy methods exist for update/complete/linkOutbound: `repo/backend/app/Policies/SalesDocumentPolicy.php:48`, `repo/backend/app/Policies/SalesDocumentPolicy.php:59`, `repo/backend/app/Policies/SalesDocumentPolicy.php:69`
- Controller enforces policy for complete/link-outbound: `repo/backend/app/Http/Controllers/Api/SalesDocumentController.php:122`, `repo/backend/app/Http/Controllers/Api/SalesDocumentController.php:124`, `repo/backend/app/Http/Controllers/Api/SalesDocumentController.php:157`, `repo/backend/app/Http/Controllers/Api/SalesDocumentController.php:159`
- Returns now policy-authorized for update/complete: `repo/backend/app/Http/Controllers/Api/ReturnController.php:66`, `repo/backend/app/Http/Controllers/Api/ReturnController.php:69`, `repo/backend/app/Http/Controllers/Api/ReturnController.php:83`, `repo/backend/app/Http/Controllers/Api/ReturnController.php:86`, `repo/backend/app/Policies/ReturnRecordPolicy.php:53`, `repo/backend/app/Policies/ReturnRecordPolicy.php:73`

---

### Issue 2 (Blocker)
**Previous**: Attachment upload/delete without parent-record/object scope validation.

**Round-2 status**: **Fixed**

**Evidence**:
- Parent record authorization on attachment upload/list: `repo/backend/app/Http/Controllers/Api/AttachmentController.php:47`, `repo/backend/app/Http/Controllers/Api/AttachmentController.php:73`
- Delete policy includes department scope: `repo/backend/app/Policies/AttachmentPolicy.php:57`, `repo/backend/app/Policies/AttachmentPolicy.php:68`
- Security regression tests added for cross-department upload/delete denial: `repo/backend/api_tests/Attachment/AttachmentUploadTest.php:257`, `repo/backend/api_tests/Attachment/AttachmentUploadTest.php:283`

---

### Issue 3 (Blocker)
**Previous**: Workflow node details used broad `viewAny` instead of instance-level object auth.

**Round-2 status**: **Fixed**

**Evidence**:
- Node show now authorizes against parent instance `view`: `repo/backend/app/Http/Controllers/Api/WorkflowNodeController.php:24`, `repo/backend/app/Http/Controllers/Api/WorkflowNodeController.php:27`
- Regression test for unrelated permitted user denied: `repo/backend/api_tests/Workflow/WorkflowApprovalTest.php:360`

---

### Issue 4 (High)
**Previous**: Canary cap depended on client-supplied `eligible_count`.

**Round-2 status**: **Fixed**

**Evidence**:
- Request schema removed required `eligible_count`: `repo/backend/app/Http/Requests/Configuration/RolloutConfigurationVersionRequest.php:20`
- Eligible count computed server-side in controller: `repo/backend/app/Http/Controllers/Api/ConfigurationVersionController.php:84`
- Tamper test confirms client `eligible_count` is ignored: `repo/backend/api_tests/Configuration/ConfigurationVersionTest.php:187`

---

### Issue 5 (High)
**Previous**: Outbound linkage final-approval semantics reduced to completed status only.

**Round-2 status**: **Fixed**

**Evidence**:
- Service now requires linked workflow instance in Approved state: `repo/backend/app/Application/Sales/SalesDocumentService.php:182`, `repo/backend/app/Application/Sales/SalesDocumentService.php:195`
- Test now expects 409 if no workflow instance linked: `repo/backend/api_tests/Sales/SalesDocumentLifecycleTest.php:198`
- Test covers approved workflow success path: `repo/backend/api_tests/Sales/SalesDocumentLifecycleTest.php:214`

---

### Issue 6 (High)
**Previous**: Multi-file attachment capability missing.

**Round-2 status**: **Fixed**

**Evidence**:
- Request now accepts `files` array and validates `files.*`: `repo/backend/app/Http/Requests/Attachment/StoreAttachmentRequest.php:31`, `repo/backend/app/Http/Requests/Attachment/StoreAttachmentRequest.php:32`
- Controller processes batch files and aggregate cap check: `repo/backend/app/Http/Controllers/Api/AttachmentController.php:75`, `repo/backend/app/Http/Controllers/Api/AttachmentController.php:83`
- API tests now use batch payloads and new response indexing: `repo/backend/api_tests/Attachment/AttachmentUploadTest.php:271`, `repo/backend/api_tests/Attachment/AttachmentUploadTest.php:332`

---

### Issue 7 (Medium)
**Previous**: Metrics producer call sites not evidenced.

**Round-2 status**: **Fixed**

**Evidence**:
- Request timing producer added: `repo/backend/app/Http/Middleware/RecordRequestTimingMiddleware.php:32`
- Timing middleware wired into authenticated API group: `repo/backend/routes/api.php:35`
- Queue depth producer job added: `repo/backend/app/Jobs/RecordQueueDepthJob.php:27`
- Queue depth job scheduled: `repo/backend/routes/console.php:68`
- Failed approvals producer in workflow reject path: `repo/backend/app/Application/Workflow/WorkflowService.php:291`

---

### Issue 8 (Medium)
**Previous**: PasswordPolicy had no enforcement call sites.

**Round-2 status**: **Fixed**

**Evidence**:
- PasswordPolicy enforced on admin user creation: `repo/backend/app/Http/Requests/Admin/StoreAdminUserRequest.php:31`
- PasswordPolicy enforced on password reset: `repo/backend/app/Http/Requests/Admin/UpdateUserPasswordRequest.php:25`
- Admin endpoints wired for these flows: `repo/backend/routes/api.php:175`, `repo/backend/routes/api.php:177`

---

## 4. Round-2 Verdict
All issues listed in `.tmp/audit_report-1.md` are now statically evidenced as remediated in the current codebase.

## 5. Residual Static Notes
- Runtime correctness and performance implications still require manual verification (outside static boundary).
