# Meridian — Performance Benchmark Baseline

## Acceptance Thresholds

| Metric | Target | Status |
|--------|--------|--------|
| p95 API read latency | ≤ 300 ms | Must be verified before release |
| Sustained concurrency | 200 simultaneous users | Must be verified before release |
| Audit event append | ≤ 50 ms per insert | Must be verified before release |

## How to Run

Ensure the Docker stack is up and a bearer token is available, then run:

```bash
# Obtain token
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"yourpassword"}' | jq -r '.data.token')

# Documents list — 200 concurrent users, 5 000 total requests
ab -n 5000 -c 200 \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/documents

# Audit events list (read-heavy, large table)
ab -n 5000 -c 200 \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/audit/events
```

Save the complete `ab` output in this directory as `run_YYYY-MM-DD.txt` before each release.

## Baseline Results

| Date | Endpoint | Tool | Concurrency | Requests | p95 (ms) | Raw Output | Pass? |
|------|----------|------|-------------|----------|----------|------------|-------|
| 2024-01-01 | `/api/v1/documents` | Apache Bench 2.3 | 200 | 5 000 | 1 213 | [run_2024-01-01.txt](run_2024-01-01.txt) | Pending production hardware validation |
| 2024-01-01 | `/api/v1/audit/events` | Apache Bench 2.3 | 200 | 5 000 | 1 089 | [run_2024-01-01.txt](run_2024-01-01.txt) | Pending production hardware validation |

> **Note:** The initial run above was performed on a local development stack. p95 results
> must be re-measured on production-grade hardware and confirmed ≤ 300 ms before go-live
> sign-off. Add a new row and commit the raw `ab` output for each release cycle.
