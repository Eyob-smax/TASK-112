# Meridian Enterprise Operations & Document Management — API Specification

**Version:** 7.0 (Prompts 5/6/7 — Configuration, Workflow, Sales, Returns, Operational Resilience)
**Base URL:** `http://{LAN_HOST}:8000/api/v1`
**Protocol:** HTTP/1.1 (LAN-internal only — no TLS required within trusted LAN)
**Auth:** Laravel Sanctum Bearer token

---

## 1. Conventions

### 1.1 Base URL and Versioning

All endpoints are prefixed with `/api/v1`. The LAN host is set via the `LAN_BASE_URL` environment variable. Example:

```
http://192.168.1.100:8000/api/v1
```

Versioning is URL-based. Breaking changes will increment the version prefix.

### 1.2 Authentication

All endpoints except `POST /auth/login` require a valid Bearer token in the `Authorization` header:

```
Authorization: Bearer {token}
```

Tokens are issued by `POST /auth/login` and stored server-side via Laravel Sanctum. Tokens do not expire automatically but are revoked on logout.

### 1.3 Idempotency

All **authenticated mutating requests** (POST, PUT, PATCH, DELETE) that could produce side effects **must** include an `X-Idempotency-Key` header with a client-generated UUID:

```
X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

The server caches the response for duplicate requests with the same key in the same actor/method/path scope and returns the cached response without re-executing the operation. The TTL for cached idempotency responses is 24 hours.

If the same key is reused in the same scope with a different request payload, the server returns `409 idempotency_key_reused`.

Read-only requests (GET) do not require this header. `POST /auth/login` is a documented exception and does not require idempotency.

### 1.4 Error Envelope

All error responses use a consistent JSON envelope:

```json
{
  "error": {
    "code": "string",
    "message": "string",
    "details": {}
  }
}
```

| HTTP Status | Error Code | Meaning |
|-------------|------------|---------|
| 401 | `unauthenticated` | Missing or invalid bearer token |
| 403 | `forbidden` | Authenticated but not authorized |
| 404 | `not_found` | Resource does not exist |
| 405 | `method_not_allowed` | HTTP method not supported for this route |
| 409 | Various (see below) | State conflict — e.g., `document_archived`, `invalid_rollout_transition`, `workflow_terminated` |
| 410 | Various (see below) | Resource consumed/expired — e.g., `link_expired`, `link_consumed`, `attachment_expired` |
| 422 | `validation_error` | Validation failed; `details` contains per-field error arrays |
| 423 | `account_locked` | Account temporarily locked after 5 consecutive failed login attempts |
| 500 | `server_error` | Unexpected internal error (production only; no stack trace leaked) |

Validation error shape:
```json
{
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "field_name": ["Error message 1", "Error message 2"]
    }
  }
}
```

### 1.5 Pagination

List endpoints support cursor or offset pagination via query parameters:

```
GET /api/v1/documents?page=1&per_page=25&sort=created_at&direction=desc
```

Paginated response envelope:
```json
{
  "data": [...],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 25,
      "total": 312,
      "last_page": 13
    }
  }
}
```

Default `per_page` is 25. Maximum `per_page` is 100.

### 1.6 Filtering and Sorting

Filter parameters are prefixed with `filter[field]`:
```
GET /api/v1/documents?filter[status]=published&filter[department_id]={uuid}
```

Sort is expressed as:
```
?sort=field_name&direction=asc|desc
```

### 1.7 Offline Integration Constraints

- No webhooks — event notification is via the `to_do_items` local queue
- LAN attachment links are served by this same backend process on port 8000
- No cross-host service calls; all operations complete within the single Docker host

---

## 2. API Domains

### 2.1 Authentication & Session

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| POST | `/auth/login` | No | No | Issue a bearer token |
| POST | `/auth/logout` | Yes | Yes | Revoke current token |
| GET | `/auth/me` | Yes | No | Get authenticated user profile |

### 2.2 Roles & Departments

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/roles` | Yes (admin) | List all roles |
| GET | `/departments` | Yes (`view departments` or `manage departments`) | List all departments |
| POST | `/departments` | Yes (`manage departments`) | Create department |
| GET | `/departments/{id}` | Yes (`view departments` or `manage departments`) | Get department detail |
| PUT | `/departments/{id}` | Yes (`manage departments`) | Update department |

### 2.3 Documents & Versions

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| GET | `/documents` | Yes | No | List documents (filtered by role/dept) |
| POST | `/documents` | Yes | Yes | Create a new document |
| GET | `/documents/{id}` | Yes | No | Get document + preview metadata |
| PUT | `/documents/{id}` | Yes | Yes | Update document metadata |
| POST | `/documents/{id}/archive` | Yes | Yes | Archive document (freeze to read-only) |
| GET | `/documents/{id}/versions` | Yes | No | List all versions of a document |
| POST | `/documents/{id}/versions` | Yes | Yes | Upload a new version |
| GET | `/documents/{id}/versions/{versionId}` | Yes | No | Get version metadata |
| GET | `/documents/{id}/versions/{versionId}/download` | Yes | No | Download version (watermarked PDF or raw file) |

### 2.4 Attachments & Evidence

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| POST | `/records/{type}/{id}/attachments` | Yes | Yes | Upload attachment(s) to a record |
| GET | `/records/{type}/{id}/attachments` | Yes | No | List attachments for a record |
| GET | `/attachments/{id}` | Yes | No | Get attachment metadata |
| DELETE | `/attachments/{id}` | Yes | Yes | Revoke/delete an attachment |
| POST | `/attachments/{id}/links` | Yes | Yes | Generate a LAN share link |
| GET | `/links/{token}` | No* | No | Resolve and download a LAN share link |

*Link resolution does not require a bearer token — the opaque token is the credential. However, consumption is logged.

Attachment uploads enforce both:
- server-side magic-byte detection,
- and declared-vs-detected MIME consistency (mismatches return `422 invalid_mime_type`).

### 2.5 Operations & Configuration

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| GET | `/configuration/sets` | Yes | No | List configuration sets |
| POST | `/configuration/sets` | Yes (admin/manager) | Yes | Create a configuration set |
| GET | `/configuration/sets/{id}` | Yes | No | Get configuration set |
| GET | `/configuration/sets/{id}/versions` | Yes | No | List versions of a configuration set |
| POST | `/configuration/sets/{id}/versions` | Yes (admin/manager) | Yes | Create a new version |
| GET | `/configuration/versions/{id}` | Yes | No | Get version detail and rules |
| POST | `/configuration/versions/{id}/rollout` | Yes (admin/manager) | Yes | Start canary rollout (≤10% targets, 24h gate) |
| POST | `/configuration/versions/{id}/promote` | Yes (admin/manager) | Yes | Promote to full rollout (after 24h canary) |
| POST | `/configuration/versions/{id}/rollback` | Yes (`manage rollouts`) | Yes | Rollback a canary or promoted version |

Store-target canary rollouts validate `target_ids` against a server-authoritative eligible store list (`CANARY_STORE_IDS`) and reject unknown targets with 422 validation errors.

### 2.6 Workflow Templates & Instances

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| GET | `/workflow/templates` | Yes | No | List workflow templates (department scope enforced) |
| POST | `/workflow/templates` | Yes (admin/manager) | Yes | Create a workflow template |
| GET | `/workflow/templates/{id}` | Yes | No | Get template with node definitions |
| PUT | `/workflow/templates/{id}` | Yes (admin/manager) | Yes | Update template |
| POST | `/workflow/instances` | Yes | Yes | Start a workflow instance for a record |
| GET | `/workflow/instances/{id}` | Yes | No | Get instance with current node status |
| POST | `/workflow/instances/{id}/withdraw` | Yes (initiator or `manage workflow instances`) | Yes | Withdraw before final approval |
| GET | `/workflow/nodes/{id}` | Yes | No | Get a single workflow node |
| POST | `/workflow/nodes/{id}/approve` | Yes | Yes | Approve a node |
| POST | `/workflow/nodes/{id}/reject` | Yes | Yes | Reject a node (reason required) |
| POST | `/workflow/nodes/{id}/reassign` | Yes | Yes | Reassign a node to another user |
| POST | `/workflow/nodes/{id}/add-approver` | Yes | Yes | Add an additional approver to a node |
| GET | `/todo` | Yes | No | List to-do queue items for authenticated user |
| POST | `/todo/{id}/complete` | Yes | Yes | Mark a to-do item as completed |

### 2.7 Sales Issue & Return/Exchange

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| GET | `/sales` | Yes | No | List sales documents |
| POST | `/sales` | Yes | Yes | Create a sales document (draft) |
| GET | `/sales/{id}` | Yes | No | Get sales document with line items |
| PUT | `/sales/{id}` | Yes | Yes | Update draft sales document |
| POST | `/sales/{id}/submit` | Yes | Yes | Submit for review (draft → reviewed) |
| POST | `/sales/{id}/complete` | Yes (manager) | Yes | Complete (reviewed → completed) |
| POST | `/sales/{id}/void` | Yes (manager) | Yes | Void a document (any non-completed state) |
| POST | `/sales/{id}/link-outbound` | Yes (manager) | Yes | Create outbound linkage (requires final approval) |
| POST | `/sales/{id}/returns` | Yes (manage sales) | Yes | Initiate a return against this document |
| GET | `/sales/{id}/returns` | Yes | No | List returns for a sales document |
| POST | `/sales/{id}/exchanges` | Yes (manage sales) | Yes | Initiate an exchange against this document |
| GET | `/sales/{id}/exchanges` | Yes | No | List exchanges for a sales document |
| GET | `/returns/{id}` | Yes | No | Get return detail |
| PUT | `/returns/{id}` | Yes | Yes | Update a pending return |
| POST | `/returns/{id}/complete` | Yes (manager) | Yes | Complete the return (triggers inventory rollback) |
| POST | `/exchanges/{id}/complete` | Yes (manager) | Yes | Complete the exchange (triggers inventory rollback) |

### 2.8 Audit Events

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/audit/events` | Yes (admin/auditor) | List audit events with filters |
| GET | `/audit/events/{id}` | Yes (admin/auditor) | Get a single audit event |

### 2.9 Admin — Backups & Metrics

| Method | Path | Auth | Idempotency | Description |
|--------|------|------|-------------|-------------|
| GET | `/admin/backups` | Yes (admin) | No | List backup jobs and history |
| POST | `/admin/backups` | Yes (admin) | Yes | Trigger an on-demand backup |
| GET | `/admin/metrics` | Yes (admin) | No | Get retained metrics summary |
| GET | `/admin/health` | Yes (admin) | No | System health check (DB, queue, disk) |
| GET | `/admin/logs` | Yes (admin) | No | Query structured logs |

---

## 3. Key Request/Response Schemas (Initial Definitions)

### 3.1 POST /auth/login

**Auth required:** No | **Idempotency required:** No

**Request:**
```json
{
  "username": "string (required)",
  "password": "string (required)"
}
```

**Response 200:**
```json
{
  "data": {
    "token": "string",
    "user": {
      "id": "uuid",
      "username": "string",
      "display_name": "string",
      "roles": ["string"],
      "department_id": "uuid"
    }
  }
}
```

**Response 401 (invalid credentials):**
```json
{
  "error": {
    "code": "unauthenticated",
    "message": "Invalid username or password.",
    "details": {}
  }
}
```

**Response 423 (account locked):**
```json
{
  "error": {
    "code": "account_locked",
    "message": "Account locked due to failed login attempts. Try again after {datetime}.",
    "details": {
      "locked_until": "ISO8601 datetime"
    }
  }
}
```

### 3.2 Documents

#### POST /documents

**Auth required:** Yes | **Idempotency required:** Yes

**Request:**
```json
{
  "title": "Procurement Policy",
  "document_type": "policy",
  "description": "Governs all procurement activities.",
  "access_control_scope": "department",
  "department_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "title": "Procurement Policy",
    "document_type": "policy",
    "description": "Governs all procurement activities.",
    "status": "draft",
    "is_archived": false,
    "access_control_scope": "department",
    "department_id": "550e8400-e29b-41d4-a716-446655440000",
    "owner_id": "uuid",
    "created_at": "2026-04-11T09:00:00Z"
  }
}
```

**Error codes:** 403 (role not permitted to create documents), 422 (validation)

---

#### GET /documents/{id}

**Auth required:** Yes | **Idempotency required:** No

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "title": "Procurement Policy",
    "document_type": "policy",
    "status": "published",
    "is_archived": false,
    "access_control_scope": "department",
    "department_id": "uuid",
    "owner_id": "uuid",
    "current_version": {
      "id": "uuid",
      "version_number": 2,
      "status": "current",
      "original_filename": "policy_v2.pdf",
      "mime_type": "application/pdf",
      "file_size_bytes": 204800,
      "sha256_fingerprint": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
      "page_count": 12,
      "is_previewable": true,
      "thumbnail_available": false,
      "published_at": "2026-04-11T10:00:00Z"
    },
    "created_at": "2026-04-11T09:00:00Z"
  }
}
```

**Error codes:** 403 (different department / scope mismatch), 404 (not found)

---

#### PUT /documents/{id}

**Auth required:** Yes | **Idempotency required:** Yes

**Request:**
```json
{
  "title": "Updated Procurement Policy",
  "description": "Revised description.",
  "access_control_scope": "cross_department"
}
```

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "title": "Updated Procurement Policy",
    "description": "Revised description.",
    "status": "draft",
    "is_archived": false,
    "updated_at": "2026-04-11T10:30:00Z"
  }
}
```

**Error codes:** 403 (not authorized), 409 (`document_archived`), 422 (validation)

---

#### POST /documents/{id}/archive

**Auth required:** Yes | **Idempotency required:** Yes

**Request:** No body required.

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "title": "Procurement Policy",
    "status": "archived",
    "is_archived": true,
    "archived_at": "2026-04-11T11:00:00Z",
    "archived_by": "uuid"
  }
}
```

**Response 409 (already archived):**
```json
{
  "error": {
    "code": "document_archived",
    "message": "Document is archived and cannot be modified.",
    "details": {}
  }
}
```

---

#### POST /documents/{id}/versions

**Auth required:** Yes | **Idempotency required:** Yes | **Content-Type:** `multipart/form-data`

**Request:**
- `file` — binary (PDF, DOCX, XLSX, PNG, or JPEG; max 25 MB)
- `page_count` — integer (optional, for PDFs)
- `sheet_count` — integer (optional, for XLSX)
- `is_previewable` — boolean (optional)

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "document_id": "uuid",
    "version_number": 1,
    "status": "current",
    "original_filename": "policy.pdf",
    "mime_type": "application/pdf",
    "file_size_bytes": 102400,
    "sha256_fingerprint": "e3b0c44298fc1c149afbf4c8996fb924...",
    "page_count": 5,
    "is_previewable": false,
    "thumbnail_available": false,
    "published_at": "2026-04-11T09:15:00Z"
  }
}
```

**Error codes:** 409 (`document_archived`), 422 (MIME not allowed / file too large)

---

#### GET /documents/{id}/versions/{versionId}/download

**Auth required:** Yes | **Idempotency required:** No

**Response 200 — binary file stream with headers:**
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="policy.pdf"
X-Watermark-Recorded: true
X-Watermark-Applied: false
```

Every download creates a `DocumentDownloadRecord` row. For PDFs, `watermark_text` = `"{display_name} - YYYY-MM-DD HH:MM:SS"` and `watermark_applied = false` (PDF byte-stamping deferred).

**Error codes:** 403 (not authorized), 404 (version not found), 409 (document or version not in a downloadable state)

### 3.3 Attachments

#### POST /records/document/{id}/attachments

**Auth required:** Yes | **Idempotency required:** Yes | **Content-Type:** `multipart/form-data`

**Request:**
- `file` — binary (PDF, DOCX, XLSX, PNG, or JPEG; max 25 MB)
- `validity_days` — integer (optional, default 365)

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "original_filename": "evidence.pdf",
    "mime_type": "application/pdf",
    "file_size_bytes": 512000,
    "sha256_fingerprint": "abc123...",
    "status": "active",
    "expires_at": "2027-04-11T09:00:00Z",
    "uploaded_by": "uuid",
    "department_id": "uuid",
    "created_at": "2026-04-11T09:00:00Z"
  }
}
```

**Error codes:**
- 409 (`duplicate_attachment`) — identical SHA-256 fingerprint exists anywhere in the system
- 422 (`attachment_limit_exceeded`) — record already has 20 active attachments
- 422 — MIME type not in the allowed list

---

#### DELETE /attachments/{id}

**Auth required:** Yes | **Idempotency required:** Yes

**Response 204:** No body.

The attachment status is set to `revoked` and the record is soft-deleted. All associated share links become immediately invalid upon their next resolution attempt.

**Error codes:** 403 (not authorized), 404 (not found)

---

#### POST /attachments/{id}/links

**Auth required:** Yes | **Idempotency required:** Yes

**Request:**
```json
{
  "ttl_hours": 24,
  "is_single_use": false,
  "ip_restriction": "192.168.1.50"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "attachment_id": "uuid",
    "url": "http://192.168.1.100:8000/api/v1/links/a3f2e1b0c9d8e7f6a5b4c3d2e1f0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d4e3f2",
    "expires_at": "2026-04-12T09:00:00Z",
    "is_single_use": false,
    "created_by": "uuid"
  }
}
```

**Error codes:** 403 (not authorized), 404 (attachment not found), 410 (`attachment_expired` or `attachment_revoked`)

---

#### GET /links/{token}

**Auth required:** No (token IS the credential) | **Idempotency required:** No

**Response 200 — binary file stream:**
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="evidence.pdf"
Content-Length: 512000
```

Every resolution records an `AuditAction::LinkConsume` event. For single-use links, `consumed_at` is set atomically on first resolution and `consumed_by` is recorded when resolver authentication is explicitly provided; otherwise `consumed_by` remains null for token-only public resolution. Subsequent calls return 410.

**Error codes:**
- 404 — token not found
- 410 (`link_expired`) — link has passed its `expires_at`
- 410 (`link_revoked`) — link was explicitly revoked via attachment deletion
- 410 (`link_consumed`) — single-use link was already resolved once
- 403 — optional `ip_restriction` mismatch

### 3.4 POST /configuration/versions/{id}/rollout

**Request:**
```json
{
  "target_type": "store | user",
  "target_ids": ["uuid", "uuid", "..."]
}
```
Validation: `target_ids.length / eligible_population_count ≤ 0.10`

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "status": "canary",
    "canary_target_count": 5,
    "canary_eligible_count": 50,
    "canary_percent": 10.0,
    "canary_started_at": "ISO8601 datetime",
    "earliest_promotion_at": "ISO8601 datetime"
  }
}
```

### 3.5 POST /workflow/nodes/{id}/reject

**Request:**
```json
{
  "reason": "string (required, min 10 chars)"
}
```

**Response 200:**
```json
{
  "data": {
    "workflow_node_id": "uuid",
    "action": "reject",
    "reason": "string",
    "actioned_at": "ISO8601 datetime",
    "workflow_status": "rejected"
  }
}
```

---

## 4. Idempotency Semantics for Write APIs

| Scenario | Behavior |
|----------|----------|
| Same `X-Idempotency-Key`, same actor + method + path + payload, within 24h | Return cached response (no re-execution) |
| Same `X-Idempotency-Key`, same actor + method + path, different payload | Return 409 `idempotency_key_reused` |
| Same `X-Idempotency-Key`, different authenticated actor | Treated as a different scope (no cross-user replay) |
| Same `X-Idempotency-Key`, after 24h TTL | Treat as new request; generate new idempotency record |
| Missing `X-Idempotency-Key` on mutating endpoint | Return 422 `idempotency_key_required` |
| Concurrent requests with same key/scope | First persisted response becomes the replay source |

---

## 5. Offline Integration Constraints

| Constraint | Implication |
|------------|-------------|
| No internet access | All URLs must be LAN-local; no external CDN or API calls |
| LAN_BASE_URL configurable | Attachment link URLs use env-configured host |
| No webhooks | External systems poll or query to-do queue via API |
| Single-host deployment | No horizontal scaling — file paths and queue are host-local |
| No external job queue | `QUEUE_CONNECTION=database` — jobs stored in MySQL |
| Port 8000 only | All API traffic, including LAN link resolution, uses port 8000 |

---

## 6. Full Endpoint Schemas (Prompt 2)

### 6.1 POST /documents

**Auth required:** Yes | **Idempotency required:** Yes

**Request body:**
```json
{
  "title": "string (required, max 255)",
  "document_type": "string (required, max 50)",
  "department_id": "uuid (required)",
  "description": "string (optional)",
  "access_control_scope": "own_department | cross_department | system_wide (required)"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "title": "string",
    "document_type": "string",
    "department_id": "uuid",
    "status": "draft",
    "is_archived": false,
    "access_control_scope": "string",
    "created_at": "ISO8601"
  }
}
```

**Error codes:** 422 (validation), 403 (unauthorized department)

---

### 6.2 POST /documents/{id}/versions

**Auth required:** Yes | **Idempotency required:** Yes | **Content-Type:** `multipart/form-data`

**Request:**
- `file` — binary upload (PDF, DOCX, XLSX, PNG, or JPG; max 25 MB)
- `page_count` — integer (optional, for PDFs)
- `sheet_count` — integer (optional, for XLSX)

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "document_id": "uuid",
    "version_number": 2,
    "status": "current",
    "original_filename": "string",
    "mime_type": "string",
    "file_size_bytes": 1048576,
    "sha256_fingerprint": "string (64 hex chars)",
    "is_previewable": true,
    "page_count": 5,
    "created_at": "ISO8601"
  }
}
```

**Error codes:** 409 (document archived), 413 (file too large), 422 (MIME not allowed / header mismatch)

---

### 6.3 GET /documents/{id}/versions/{versionId}/download

**Auth required:** Yes | **Idempotency required:** No

**Response:** Binary file stream

**Headers on response:**
- `Content-Type: application/pdf` (or appropriate MIME)
- `Content-Disposition: attachment; filename="{original_filename}"`
- `X-Watermark-Applied: true|false` (for PDFs)

**Behavior for PDFs:**
1. Decrypt attachment payload
2. Apply username + timestamp watermark using TCPDF/FPDI
3. Stream watermarked PDF
4. Record `document_download_records` entry with `watermark_text`, `watermark_applied = true`

**Error codes:** 403 (unauthorized), 404 (version not found), 410 (version archived and not downloadable)

---

### 6.4 POST /records/{type}/{id}/attachments

**Auth required:** Yes | **Idempotency required:** Yes | **Content-Type:** `multipart/form-data`

**Path parameters:**
- `type` — record type slug (e.g., `sales-documents`, `returns`)
- `id` — UUID of the record

**Request:** One or more file uploads via `files[]`

**Validation:**
- Each file: max 25 MB, MIME in allowed list, magic bytes match declared MIME
- Total active files for record after upload: ≤ 20

**Response 201:**
```json
{
  "data": [
    {
      "id": "uuid",
      "original_filename": "string",
      "mime_type": "string",
      "file_size_bytes": 1048576,
      "sha256_fingerprint": "string",
      "status": "active",
      "expires_at": "ISO8601",
      "created_at": "ISO8601"
    }
  ]
}
```

**Error codes:** 413 (file too large), 422 (MIME not allowed / header mismatch / count exceeded), 404 (record not found)

---

### 6.5 POST /configuration/versions/{id}/rollout

**Auth required:** Yes (admin or manager) | **Idempotency required:** Yes

**Request:**
```json
{
  "target_type": "store | user",
  "target_ids": ["uuid", "uuid"]
}
```

**Validation:**
- `target_ids.count / eligible_count ≤ 0.10` (10% cap enforced)
- Version must be in 'draft' status

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "status": "canary",
    "canary_target_type": "store",
    "canary_target_count": 5,
    "canary_eligible_count": 50,
    "canary_percent": 10.0,
    "canary_started_at": "ISO8601",
    "earliest_promotion_at": "ISO8601"
  }
}
```

**Error codes:** 422 (exceeds 10% cap), 409 (version not in draft status)

---

### 6.6 POST /sales

**Auth required:** Yes | **Idempotency required:** Yes

**Request:**
```json
{
  "site_code": "string (required, 2-10 uppercase alphanumeric)",
  "department_id": "uuid (required)",
  "notes": "string (optional, sensitive — masked for lower roles)",
  "line_items": [
    {
      "product_code": "string (required)",
      "description": "string (required)",
      "quantity": "decimal (required, > 0)",
      "unit_price": "decimal (required, ≥ 0)"
    }
  ]
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "document_number": "SITE01-20240115-000001",
    "site_code": "SITE01",
    "status": "draft",
    "department_id": "uuid",
    "total_amount": "decimal",
    "line_items": [...],
    "created_at": "ISO8601"
  }
}
```

**Error codes:** 422 (validation), 403 (department access denied)

---

### 6.7 POST /sales/{id}/returns

**Auth required:** Yes | **Idempotency required:** Yes

**Request:**
```json
{
  "reason_code": "defective | wrong_item | not_as_described | changed_mind | other",
  "reason_detail": "string (optional)",
  "return_amount": "decimal (required, > 0)",
  "line_items": [
    {
      "sales_line_item_id": "uuid",
      "quantity_returned": "decimal"
    }
  ]
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "return_document_number": "string",
    "sales_document_id": "uuid",
    "reason_code": "string",
    "is_defective": false,
    "restock_fee_percent": 10.0,
    "restock_fee_amount": 50.0,
    "return_amount": 500.0,
    "refund_amount": 450.0,
    "status": "pending",
    "created_at": "ISO8601"
  }
}
```

**Error codes:** 409 (sales document not completed), 422 (validation)

---

### 6.8 GET /audit/events

**Auth required:** Yes (admin or auditor) | **Idempotency required:** No

**Query parameters:**
- `filter[action]` — AuditAction enum value
- `filter[auditable_type]` — model class name filter
- `filter[auditable_id]` — specific record UUID
- `filter[actor_id]` — user UUID
- `filter[from]` / `filter[to]` — ISO8601 date range
- `sort=created_at&direction=desc` — default sort
- `page=1&per_page=25`

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "correlation_id": "string",
      "actor_id": "uuid",
      "action": "string",
      "auditable_type": "string",
      "auditable_id": "uuid",
      "before_hash": "string (64 hex chars)",
      "after_hash": "string (64 hex chars)",
      "payload": {},
      "ip_address": "string",
      "created_at": "ISO8601"
    }
  ],
  "meta": {
    "pagination": { "current_page": 1, "per_page": 25, "total": 4200, "last_page": 168 }
  }
}
```

---

### 6.9 GET /admin/health

**Auth required:** Yes (admin) | **Idempotency required:** No

**Response 200:**
```json
{
  "data": {
    "status": "healthy | degraded | unhealthy",
    "checks": {
      "database": { "status": "ok", "latency_ms": 2 },
      "queue": { "status": "ok", "pending_jobs": 3, "failed_jobs": 0 },
      "disk": { "status": "ok", "attachment_free_bytes": 10737418240, "backup_free_bytes": 10737418240 },
      "scheduler": { "status": "ok", "last_run": "ISO8601" }
    },
    "checked_at": "ISO8601"
  }
}
```

---

## 3.4 Configuration Center

### POST /configuration/sets

Create a new configuration set.

**Request:**
```json
{
  "name": "Pricing Rules 2025",
  "description": "All pricing configuration for Q3",
  "department_id": "uuid"
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "name": "Pricing Rules 2025",
    "description": "All pricing configuration for Q3",
    "department_id": "uuid",
    "is_active": true,
    "created_by": "uuid",
    "created_at": "ISO8601",
    "updated_at": "ISO8601"
  }
}
```

---

### POST /configuration/sets/{id}/versions

Create a new version within a configuration set. The version number is auto-incremented (max + 1) atomically.

**Request:**
```json
{
  "payload": { "max_discount": 50, "allow_stacking": false },
  "change_summary": "Raise max discount to 50%",
  "rules": [
    {
      "rule_type": "coupon",
      "rule_key": "SUMMER10",
      "rule_value": { "discount": 10 },
      "is_active": true,
      "priority": 1,
      "description": "10% summer coupon"
    }
  ]
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "configuration_set_id": "uuid",
    "version_number": 1,
    "status": "draft",
    "payload": { "max_discount": 50, "allow_stacking": false },
    "change_summary": "Raise max discount to 50%",
    "canary_started_at": null,
    "promoted_at": null,
    "rolled_back_at": null,
    "created_at": "ISO8601",
    "updated_at": "ISO8601"
  }
}
```

---

### POST /configuration/versions/{id}/rollout

Start a canary rollout for a draft version. Maximum 10% of eligible population.

**Request:**
```json
{
  "target_type": "store",
  "target_ids": ["uuid1", "uuid2", "uuid3"],
  "eligible_count": 100
}
```

**Response 200** (success):
```json
{
  "data": { "id": "uuid", "status": "canary", "canary_started_at": "ISO8601" }
}
```

**Response 422** (cap exceeded):
```json
{
  "error": {
    "code": "canary_cap_exceeded",
    "message": "Canary rollout cap exceeded: 20 targets requested but maximum allowed is 10 (10% of eligible population).",
    "details": {}
  }
}
```

---

### POST /configuration/versions/{id}/promote

Promote a canary version to full rollout. Requires 24 h to have elapsed since canary start.

**Response 200** (promoted):
```json
{
  "data": { "id": "uuid", "status": "promoted", "promoted_at": "ISO8601" }
}
```

**Response 409** (too early):
```json
{
  "error": {
    "code": "canary_not_ready",
    "message": "Canary rollout cannot be promoted yet. Earliest promotion time: 2025-08-16 14:30:00.",
    "details": {}
  }
}
```

---

### POST /configuration/versions/{id}/rollback

Roll back a canary or promoted version.

**Authorization:** `manageRollout` policy on the parent configuration set (`manage rollouts` permission; admin bypass applies).

**Response 200:**
```json
{
  "data": { "id": "uuid", "status": "rolled_back", "rolled_back_at": "ISO8601" }
}
```

**Response 409** (invalid transition):
```json
{
  "error": {
    "code": "invalid_rollout_transition",
    "message": "Cannot transition rollout status from 'draft' to 'rolled_back'.",
    "details": {}
  }
}
```

---

## 3.5 Workflow Engine

### POST /workflow/templates

Create a workflow approval template with ordered nodes.

**Request:**
```json
{
  "name": "Finance Approval",
  "event_type": "expense_request",
  "department_id": "uuid",
  "nodes": [
    { "node_type": "sequential", "node_order": 1, "sla_business_days": 2, "label": "Manager Review" },
    { "node_type": "sequential", "node_order": 2, "sla_business_days": 3, "label": "Director Approval" }
  ]
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "name": "Finance Approval",
    "event_type": "expense_request",
    "is_active": true,
    "nodes": [
      { "id": "uuid", "node_type": "sequential", "node_order": 1, "sla_business_days": 2, "label": "Manager Review" },
      { "id": "uuid", "node_type": "sequential", "node_order": 2, "sla_business_days": 3, "label": "Director Approval" }
    ]
  }
}
```

---

### POST /workflow/instances

Start a workflow instance against a record.

**Request:**
```json
{
  "workflow_template_id": "uuid",
  "record_type": "document",
  "record_id": "uuid",
  "context": { "amount": 5000 }
}
```

`record_type` must be one of: `document`, `sales_document`, `return`, `configuration_version`.
`record_id` must reference an existing record of the specified type.

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "status": "in_progress",
    "started_at": "ISO8601",
    "nodes": [
      { "id": "uuid", "node_order": 1, "status": "pending", "assigned_to": null, "sla_due_at": "ISO8601" }
    ]
  }
}
```

---

### POST /workflow/nodes/{id}/approve

Approve a workflow node. Advances instance to next node or closes it as approved.

**Response 200:**
```json
{
  "data": { "id": "uuid", "status": "approved", "completed_at": "ISO8601", "instance_status": "approved" }
}
```

---

### POST /workflow/nodes/{id}/reject

Reject a workflow node. Reason is **required**.

**Request:**
```json
{ "reason": "Budget not available for this quarter." }
```

**Response 200:** node and instance both transition to `rejected`.

**Response 422** (no reason):
```json
{
  "error": { "code": "reason_required", "message": "A reason is required for this workflow action.", "details": {} }
}
```

---

### POST /workflow/nodes/{id}/reassign

Reassign the node to another user. Reason is **required**.

**Request:**
```json
{ "target_user_id": "uuid", "reason": "Delegating while on leave." }
```

**Response 200:** node `assigned_to` updated; new assignee receives a to-do item.

---

### POST /workflow/instances/{id}/withdraw

Withdraw the entire instance. Requester must be the initiator or have `manage workflow instances`.

**Request:**
```json
{ "reason": "Request no longer needed." }
```

**Response 200:**
```json
{
  "data": { "id": "uuid", "status": "withdrawn", "withdrawn_at": "ISO8601" }
}
```

**Response 409** (already terminal):
```json
{
  "error": { "code": "workflow_terminated", "message": "This workflow instance has already reached a terminal state and cannot be modified.", "details": {} }
}
```

**Response 403** (not initiator and missing manage permission):
```json
{
  "error": { "code": "forbidden", "message": "This action is unauthorized.", "details": {} }
}
```

---

### GET /todo

List authenticated user's to-do items. Completed items excluded by default.

**Query params:** `include_completed=true`, `per_page=25`

**Response 200:**
```json
{
  "data": [
    {
      "id": "uuid",
      "type": "workflow_approval",
      "title": "Review expense request",
      "body": "Please review and approve the pending expense.",
      "reference_id": "uuid",
      "due_at": "ISO8601",
      "completed_at": null
    }
  ],
  "meta": { "pagination": { "current_page": 1, "per_page": 25, "total": 1, "last_page": 1 } }
}
```

---

### POST /todo/{id}/complete

Mark a to-do item as completed. Users can only complete their own items.

**Response 200:** item with `completed_at` set.
**Response 403:** attempting to complete another user's item.

---

## 3.6 Sales and Returns

### POST /sales

Create a new sales document in Draft status. Document number is atomically assigned.

**Request:**
```json
{
  "site_code": "HQ",
  "department_id": "uuid",
  "notes": "Q3 retail order",
  "line_items": [
    { "product_code": "SKU-001", "description": "Widget A", "quantity": 2, "unit_price": 50.00 },
    { "product_code": "SKU-002", "description": "Widget B", "quantity": 1, "unit_price": 30.00 }
  ]
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "document_number": "HQ-20250815-000001",
    "site_code": "HQ",
    "status": "draft",
    "total_amount": 130.00,
    "created_at": "ISO8601"
  }
}
```

---

### POST /sales/{id}/submit

Transition draft → reviewed.

**Response 200:**
```json
{
  "data": { "id": "uuid", "status": "reviewed" }
}
```

---

### POST /sales/{id}/complete

Transition reviewed → completed. Creates stock-out inventory movements.

**Response 200:**
```json
{
  "data": { "id": "uuid", "status": "completed", "completed_at": "ISO8601" }
}
```

**Response 409** (invalid transition):
```json
{
  "error": { "code": "invalid_sales_transition", "message": "Cannot transition sales document status from 'draft' to 'completed'.", "details": {} }
}
```

---

### POST /sales/{id}/void

Void a draft or reviewed document.

**Request:**
```json
{ "reason": "Customer cancelled order." }
```

**Response 200:**
```json
{
  "data": { "id": "uuid", "status": "voided", "voided_at": "ISO8601", "voided_reason": "Customer cancelled order." }
}
```

---

### POST /sales/{id}/link-outbound

Record outbound linkage on a completed document.

**Response 200:**
```json
{
  "data": { "id": "uuid", "outbound_linked_at": "ISO8601" }
}
```

**Response 409** (not completed):
```json
{
  "error": { "code": "outbound_linkage_not_allowed", "message": "Outbound linkage is only permitted for completed sales documents.", "details": {} }
}
```

---

### POST /sales/{id}/returns

Create a return against a completed sales document.

**Request:**
```json
{
  "reason_code": "changed_mind",
  "reason_detail": "Item did not meet expectations.",
  "return_amount": 90.00,
  "restock_fee_percent": 10
}
```

**Response 201:**
```json
{
  "data": {
    "id": "uuid",
    "return_document_number": "HQR-20250815-000001",
    "status": "pending",
    "reason_code": "changed_mind",
    "is_defective": false,
    "restock_fee_percent": 10,
    "restock_fee_amount": 9.00,
    "refund_amount": 81.00,
    "created_at": "ISO8601"
  }
}
```

**Response 422** (window expired):
```json
{
  "error": {
    "code": "return_window_expired",
    "message": "Return window has expired: 31 days have elapsed since the original sale (qualifying window: 30 days).",
    "details": {}
  }
}
```

---

### POST /returns/{id}/complete

Complete a pending return and create compensating stock-in inventory movements.

**Response 200:**
```json
{
  "data": {
    "id": "uuid",
    "status": "completed",
    "completed_at": "ISO8601"
  }
}
```

---

## 3.7 Admin and Operational Endpoints

All admin endpoints require `admin` role unless otherwise noted. All require Bearer token.

### Audit Events

#### `GET /audit/events`
Browse the immutable audit event log. Admin and auditor roles.

**Query params:** `filter[actor_id]`, `filter[action]`, `filter[auditable_type]`, `filter[auditable_id]`, `filter[date_from]`, `filter[date_to]`, `per_page` (max 200).

```json
GET /api/v1/audit/events?filter[action]=approve
→ 200
{
  "data": [
    {
      "id": "uuid",
      "correlation_id": "uuid",
      "actor_id": "uuid",
      "action": "approve",
      "auditable_type": "App\\Models\\WorkflowNode",
      "auditable_id": "uuid",
      "before_hash": null,
      "after_hash": null,
      "payload": {"reason": null},
      "ip_address": "192.168.1.10",
      "created_at": "ISO8601"
    }
  ],
  "meta": {"pagination": {"current_page": 1, "per_page": 50, "total": 1, "last_page": 1}}
}
```

#### `GET /audit/events/{id}` → 200 (single event)

#### `GET /admin/config-promotions`
Filtered audit view showing only `rollout_start`, `rollout_promote`, `rollout_back` events. Admin and auditor roles.

**Query params:** `filter[auditable_id]` (ConfigurationVersion UUID), `filter[date_from]`, `filter[date_to]`, `per_page`.

```json
GET /api/v1/admin/config-promotions
→ 200
{
  "data": [
    {"id": "uuid", "action": "rollout_promote", "auditable_id": "version-uuid", ...}
  ],
  "meta": {"pagination": {...}}
}
```

---

### Backup History

#### `GET /admin/backups`
List backup job history.

**Query params:** `status` (pending|running|success|failed), `per_page` (max 100).

```json
→ 200
{
  "data": [
    {
      "id": "uuid",
      "status": "success",
      "is_manual": false,
      "started_at": "ISO8601",
      "completed_at": "ISO8601",
      "size_bytes": 47185920,
      "manifest": {"tables": [...], "attachment_file_count": 85},
      "error_message": null,
      "retention_expires_at": "ISO8601"
    }
  ],
  "meta": {"retention_days": 14, "pagination": {...}}
}
```

#### `POST /admin/backups`
Trigger an immediate manual backup (dispatches to queue).

```json
→ 202
{"data": null, "message": "Backup job queued. Check backup history shortly."}
```

---

### Metrics

#### `GET /admin/metrics`
Retrieve metrics snapshots (raw). Admin only.

**Query params:** `metric_type` (request_timing|queue_depth|failed_approvals), `date_from`, `date_to`, `summary` (1/true for aggregated), `per_page` (max 500).

```json
GET /api/v1/admin/metrics?summary=1
→ 200
{
  "data": [
    {
      "metric_type": "request_timing",
      "sample_count": 145,
      "avg_value": 87.43,
      "min_value": 12.1,
      "max_value": 345.8,
      "last_recorded_at": "ISO8601"
    }
  ]
}
```

---

### Structured Logs

#### `GET /admin/logs`
Browse structured application logs. Admin or auditor.

**Query params:** `filter[level]`, `filter[channel]`, `filter[date_from]`, `filter[date_to]`, `filter[message]` (substring), `filter[request_id]`, `per_page` (max 200).

```json
GET /api/v1/admin/logs?filter[level]=error&filter[channel]=backup
→ 200
{
  "data": [
    {
      "id": "uuid",
      "level": "error",
      "message": "Backup failed",
      "context": {"job_id": "uuid", "error": "Disk full"},
      "channel": "backup",
      "request_id": null,
      "recorded_at": "ISO8601",
      "retained_until": "ISO8601"
    }
  ],
  "meta": {"retention_days": 90, "pagination": {...}}
}
```

---

### Security Inspection

#### `GET /admin/failed-logins`
List failed login attempts. Admin only.

**Query params:** `filter[user_id]`, `filter[ip_address]`, `filter[date_from]`, `filter[date_to]`, `per_page` (max 200).

```json
→ 200
{
  "data": [
    {
      "id": "uuid",
      "user_id": "uuid",
      "username_attempted": "alice",
      "ip_address": "192.168.1.5",
      "attempted_at": "ISO8601"
    }
  ],
  "meta": {"pagination": {...}}
}
```

#### `GET /admin/locked-accounts`
List currently locked user accounts. Admin only.

```json
→ 200
{
  "data": [
    {
      "id": "uuid",
      "username": "alice",
      "locked_until": "ISO8601",
      "failed_attempt_count": 5,
      "last_failed_at": "ISO8601"
    }
  ]
}
```

---

### Approval Backlog

#### `GET /admin/approval-backlog`
List pending or in-progress workflow nodes. Admin or manager.

**Query params:** `overdue_only` (1/true), `filter[instance_id]`, `per_page` (max 200).

```json
GET /api/v1/admin/approval-backlog?overdue_only=1
→ 200
{
  "data": [
    {
      "id": "uuid",
      "workflow_instance_id": "uuid",
      "node_order": 1,
      "node_type": "sequential",
      "status": "pending",
      "label": "Manager Approval",
      "assigned_to": "uuid",
      "sla_due_at": "ISO8601",
      "is_overdue": true,
      "reminded_at": "ISO8601",
      "instance_status": "in_progress"
    }
  ],
  "meta": {"pagination": {...}}
}
```

---

### Operational Health

#### `GET /admin/health`
Local health snapshot. Admin only.

```json
→ 200
{
  "data": {
    "status": "ok",
    "checks": {
      "database":  {"status": "ok", "detail": "Connected"},
      "queue":     {"status": "ok", "pending_jobs": 0, "failed_jobs": 0},
      "storage":   {"status": "ok", "attachment_files": 85, "attachment_bytes": 47185920},
      "backup":    {"status": "ok", "last_backup_at": "ISO8601", "hours_ago": 14, "expires_at": "ISO8601"}
    },
    "app": {
      "version": "7.0.0",
      "environment": "production",
      "timezone": "UTC",
      "lan_base_url": "http://192.168.1.100:8000"
    },
    "retention": {
      "backup_days": 14,
      "log_days": 90,
      "metrics_days": 90
    },
    "checked_at": "ISO8601"
  }
}
```
