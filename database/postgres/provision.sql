-- Atlantia Finance: PostgreSQL provisioning (run once per server as a superuser, e.g. `postgres`).
--
--   psql -U postgres -h <host> -p <port> -v app_password="'<strong-secret>'" -f database/postgres/provision.sql
--
-- Creates the application role and the production + test databases. The application role owns its
-- schema (so `php artisan migrate` works) but is never SUPERUSER nor BYPASSRLS: Row Level Security is
-- FORCED on every tenant table, so it applies to this role too.

\set ON_ERROR_STOP on

SELECT 'CREATE ROLE atlantia_finance_user LOGIN'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'atlantia_finance_user') \gexec

ALTER ROLE atlantia_finance_user WITH LOGIN PASSWORD :app_password
    NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION
    CONNECTION LIMIT 100;

-- Defensive session defaults for the application role.
ALTER ROLE atlantia_finance_user SET statement_timeout = '60s';
ALTER ROLE atlantia_finance_user SET idle_in_transaction_session_timeout = '120s';
ALTER ROLE atlantia_finance_user SET lock_timeout = '15s';

SELECT 'CREATE DATABASE atlantia_finance OWNER atlantia_finance_user ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'atlantia_finance') \gexec

-- Dedicated database for the automated test suite (tests refuse to run on any name not ending in _test).
SELECT 'CREATE DATABASE atlantia_finance_test OWNER atlantia_finance_user ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'atlantia_finance_test') \gexec

-- Only the application role may connect.
REVOKE ALL ON DATABASE atlantia_finance FROM PUBLIC;
REVOKE ALL ON DATABASE atlantia_finance_test FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE atlantia_finance TO atlantia_finance_user;
GRANT CONNECT, TEMPORARY ON DATABASE atlantia_finance_test TO atlantia_finance_user;

\connect atlantia_finance
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO atlantia_finance_user;

\connect atlantia_finance_test
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO atlantia_finance_user;
