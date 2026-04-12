#!/usr/bin/env bash

# =============================================================================
# Meridian — Test Runner
# =============================================================================
# Orchestrates both test suites (Unit + API) via Docker.
#
# Usage:
#   ./run_tests.sh              Run all tests (unit + API)
#   ./run_tests.sh --coverage   Run all tests with code coverage report
#                               (requires Xdebug or PCOV in the container)
#
# Prerequisites:
#   1. Copy repo/backend/.env.example to repo/backend/.env
#   2. Fill required environment variables (APP_KEY, DB_PASSWORD, etc.)
#   3. The script will start backend-test automatically if needed
#   4. The test database (meridian_test) is created automatically on first
#      MySQL boot from docker/mysql/init-test-db.sql
#
# Test suite locations:
#   Unit tests:  repo/backend/unit_tests/   (domain logic, no HTTP)
#   API tests:   repo/backend/api_tests/    (full HTTP integration)
#
# Test runner:   Pest 2.x (PHPUnit-compatible)
# Test database: meridian_test (separate from production meridian database)
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

for arg in "$@"; do
    case $arg in
        --coverage)
            COVERAGE_FLAG="--coverage"
            COVERAGE_MODE=true
            ;;
        --help|-h)
            echo "Usage: $0 [--coverage]"
            echo ""
            echo "  --coverage   Generate coverage report (requires Xdebug or PCOV)"
            echo ""
            echo "  Coverage output: repo/backend/coverage/html/index.html"
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
    echo " Coverage:    ON (HTML -> backend/coverage/html/)"
fi
echo " Started at:  $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="

# Ensure we're running from the repo/ directory
cd "${COMPOSE_PROJECT_DIR}"

# ---------------------------------------------------------------------------
# Always rebuild to pick up source changes (code is baked into the image)
# ---------------------------------------------------------------------------
echo ""
# Touch a trigger file to invalidate Docker's COPY cache (Windows/BuildKit workaround)
echo "$(date +%s)" > backend/.build-trigger

echo " Building backend-test image..."
if ! $COMPOSE_CMD build backend-test; then
    echo ""
    echo "ERROR: Failed to build backend-test."
    exit 1
fi

# ---------------------------------------------------------------------------
# Ensure test service is running with the freshly built image
# ---------------------------------------------------------------------------
# Stop old container so the new image is used
$COMPOSE_CMD stop backend-test 2>/dev/null || true
$COMPOSE_CMD rm -f backend-test 2>/dev/null || true

echo ""
echo " Starting backend-test with fresh image..."

if ! $COMPOSE_CMD up -d backend-test; then
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

$COMPOSE_CMD exec backend-test php artisan test \
    --testsuite=Unit \
    --colors=always \
    $COVERAGE_FLAG || UNIT_EXIT=$?

echo " Finished at: $(date '+%H:%M:%S')"

# ---------------------------------------------------------------------------
# API / integration test suite
# ---------------------------------------------------------------------------
echo ""
echo "----------------------------------------------"
echo " API Tests (repo/backend/api_tests/)"
echo " Started at: $(date '+%H:%M:%S')"
echo "----------------------------------------------"

$COMPOSE_CMD exec backend-test php artisan test \
    --testsuite=Api \
    --colors=always \
    $COVERAGE_FLAG || API_EXIT=$?

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
    echo ""
    echo " Coverage report: backend/coverage/html/index.html"
fi

echo "=============================================="

# Exit non-zero if either suite failed (allows CI to detect failures
# even when the other suite completed successfully)
FINAL_EXIT=$(( UNIT_EXIT > API_EXIT ? UNIT_EXIT : API_EXIT ))
exit $FINAL_EXIT
