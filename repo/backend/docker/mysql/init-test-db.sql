-- =============================================================================
-- Meridian — Test Database Initialization
-- =============================================================================
-- This script is executed automatically by the MySQL 8.0 container on its
-- first boot (mounted into /docker-entrypoint-initdb.d/).
--
-- Creates the `meridian_test` database used by the Pest test suite.
-- The production database (`meridian`) is created separately via the
-- MYSQL_DATABASE environment variable in docker-compose.yml.
--
-- phpunit.xml configures: DB_DATABASE=meridian_test, DB_HOST=mysql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS meridian_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Grant the application user full access to the test database
GRANT ALL PRIVILEGES ON meridian_test.* TO 'meridian'@'%';

FLUSH PRIVILEGES;
