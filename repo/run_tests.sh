#!/usr/bin/env bash

# =============================================================================
# Meridian — Test Runner
# =============================================================================
# Orchestrates both test suites (Unit + API) via Docker.
#
# Usage:
#   ./run_tests.sh              Run all tests (unit + API)
#   ./run_tests.sh --skip-build Skip Docker image rebuild (CI mode)
#   ./run_tests.sh --coverage   Run all tests with code coverage report
# =============================================================================

COMPOSE_PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_CMD="docker compose --profile test"

# Suppress orphan warnings during scripted compose runs.
export COMPOSE_IGNORE_ORPHANS=True

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------
COVERAGE_FLAG=""
COVERAGE_MODE=false
SKIP_BUILD=false
UNIT_LOG=""
API_LOG=""

for arg in "$@"; do
    case $arg in
        --coverage)
            COVERAGE_FLAG="--coverage"
            COVERAGE_MODE=true
            ;;
        --skip-build)
            SKIP_BUILD=true
            ;;
        --help|-h)
            echo "Usage: $0 [--skip-build] [--coverage]"
            echo ""
            echo "  --skip-build Skip Docker image rebuild (use in CI where image is pre-built)"
            echo "  --coverage   Generate coverage report (requires Xdebug or PCOV)"
            exit 0
            ;;
        *)
            echo "Unknown argument: $arg"
            echo "Run '$0 --help' for usage."
            exit 1
            ;;
    esac
done

echo "=============================================="
echo " Meridian Test Runner"
echo " Working dir: ${COMPOSE_PROJECT_DIR}"
if [ "$COVERAGE_MODE" = true ]; then
    echo " Coverage:    ON"
else
    echo " Coverage:    OFF"
fi
echo " Started at:  $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="

if [ "$COVERAGE_MODE" = true ]; then
    set -o pipefail
    UNIT_LOG="$(mktemp)"
    API_LOG="$(mktemp)"
fi

cleanup() {
    [ -n "$UNIT_LOG" ] && [ -f "$UNIT_LOG" ] && rm -f "$UNIT_LOG"
    [ -n "$API_LOG" ] && [ -f "$API_LOG" ] && rm -f "$API_LOG"
}

start_backend_test_with_retry() {
    local max_attempts=3
    local attempt=1
    local up_output

    while [ "$attempt" -le "$max_attempts" ]; do
        if up_output="$($COMPOSE_CMD up -d backend-test 2>&1)"; then
            echo "$up_output"
            return 0
        fi

        echo "$up_output"
        echo ""
        echo "WARN: backend-test start attempt ${attempt}/${max_attempts} failed."

        if [ "$attempt" -lt "$max_attempts" ]; then
            echo " Cleaning up stale backend-test container and retrying..."
            $COMPOSE_CMD rm -f backend-test >/dev/null 2>&1 || true
            sleep 2
        fi

        attempt=$((attempt + 1))
    done

    return 1
}

trap cleanup EXIT

print_coverage_summary() {
    local suite_name="$1"
    local log_file="$2"

    [ -f "$log_file" ] || return

    local coverage_lines
    coverage_lines="$(grep -E "^[[:space:]]*(Lines|Functions|Methods|Classes|Traits):[[:space:]]+[0-9]+(\.[0-9]+)?%" "$log_file" | sed -E 's/^[[:space:]]*//')"

    echo ""
    echo " Coverage (${suite_name}):"
    if [ -n "$coverage_lines" ]; then
        while IFS= read -r line; do
            echo "  - ${line}"
        done <<< "$coverage_lines"
    else
        echo "  - unavailable (no coverage driver or summary not emitted)"
    fi
}

# Ensure we're running from the repo/ directory
cd "${COMPOSE_PROJECT_DIR}"

# ---------------------------------------------------------------------------
# Build (skippable for CI where the image is already built)
# ---------------------------------------------------------------------------
if [ "$SKIP_BUILD" = false ]; then
    echo ""
    # Touch a trigger file to invalidate Docker's COPY cache (Windows/BuildKit workaround)
    echo "$(date +%s)" > backend/.build-trigger

    echo " Building backend-test image..."
    if ! $COMPOSE_CMD build backend-test; then
        echo ""
        echo "ERROR: Failed to build backend-test."
        exit 1
    fi
fi

# ---------------------------------------------------------------------------
# Ensure test service is running with the freshly built image
# ---------------------------------------------------------------------------
# Stop the production backend if running — tests only need mysql + backend-test
# and the extra PHP processes waste ~200 MB that the CI runner needs for tests.
docker compose stop backend 2>/dev/null || true

# Stop old container so the new image is used
$COMPOSE_CMD stop backend-test 2>/dev/null || true
$COMPOSE_CMD rm -f backend-test 2>/dev/null || true

echo ""
echo " Starting backend-test with fresh image..."

if ! start_backend_test_with_retry; then
    echo ""
    echo "ERROR: Failed to start backend-test."
    $COMPOSE_CMD ps || true
    exit 1
fi

echo " Waiting for backend-test to be running..."
ATTEMPTS=0
MAX_ATTEMPTS=30
until $COMPOSE_CMD ps --services --filter "status=running" 2>/dev/null | grep -q "^backend-test$"; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge "$MAX_ATTEMPTS" ]; then
        echo ""
        echo "ERROR: backend-test did not reach running state in time."
        $COMPOSE_CMD ps || true
        echo ""
        echo "Recent backend-test logs:"
        $COMPOSE_CMD logs --tail 40 backend-test || true
        exit 1
    fi
    sleep 2
done

echo " backend-test is running."

echo " Waiting for backend-test entrypoint to finish..."
ATTEMPTS=0
MAX_ATTEMPTS=60
until $COMPOSE_CMD logs --tail 200 backend-test 2>/dev/null | grep -q "Entrypoint complete"; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge "$MAX_ATTEMPTS" ]; then
        echo ""
        echo "ERROR: backend-test entrypoint did not complete in time."
        echo ""
        echo "Recent backend-test logs:"
        $COMPOSE_CMD logs --tail 80 backend-test || true
        exit 1
    fi
    sleep 2
done

echo " backend-test entrypoint completed."

UNIT_EXIT=0
API_EXIT=0

# ---------------------------------------------------------------------------
# Unit test suite
# ---------------------------------------------------------------------------
echo ""
echo "----------------------------------------------"
echo " Unit Tests (repo/backend/unit_tests/)"
echo " Started at: $(date '+%H:%M:%S')"
echo "----------------------------------------------"

if [ "$COVERAGE_MODE" = true ]; then
    $COMPOSE_CMD exec backend-test php artisan test \
        --testsuite=Unit \
        $COVERAGE_FLAG | tee "$UNIT_LOG" || UNIT_EXIT=$?
else
    $COMPOSE_CMD exec backend-test php artisan test \
        --testsuite=Unit \
        $COVERAGE_FLAG || UNIT_EXIT=$?
fi

echo " Finished at: $(date '+%H:%M:%S')"

# ---------------------------------------------------------------------------
# API / integration test suite
# ---------------------------------------------------------------------------
echo ""
echo "----------------------------------------------"
echo " API Tests (repo/backend/api_tests/)"
echo " Started at: $(date '+%H:%M:%S')"
echo "----------------------------------------------"

if [ "$COVERAGE_MODE" = true ]; then
    $COMPOSE_CMD exec backend-test php artisan test \
        --testsuite=Api \
        $COVERAGE_FLAG | tee "$API_LOG" || API_EXIT=$?
else
    $COMPOSE_CMD exec backend-test php artisan test \
        --testsuite=Api \
        $COVERAGE_FLAG || API_EXIT=$?
fi

echo " Finished at: $(date '+%H:%M:%S')"

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "=============================================="
echo " Test Run Summary"
echo " Completed at: $(date '+%Y-%m-%d %H:%M:%S')"
echo "----------------------------------------------"

if [ "$UNIT_EXIT" -eq 0 ]; then
    echo " Unit suite:  PASSED"
else
    echo " Unit suite:  FAILED (exit code ${UNIT_EXIT})"
fi

if [ "$API_EXIT" -eq 0 ]; then
    echo " API suite:   PASSED"
else
    echo " API suite:   FAILED (exit code ${API_EXIT})"
fi

if [ "$COVERAGE_MODE" = true ]; then
    print_coverage_summary "Unit" "$UNIT_LOG"
    print_coverage_summary "API" "$API_LOG"
fi

echo "=============================================="

# Exit non-zero if either suite failed
FINAL_EXIT=$(( UNIT_EXIT > API_EXIT ? UNIT_EXIT : API_EXIT ))
exit $FINAL_EXIT
