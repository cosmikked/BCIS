-- BCIS database provisioning (run ONCE per PostgreSQL server, as a superuser).
--
--   psql -U postgres -h 127.0.0.1 \
--        -v owner_password="'<choose-owner-password>'" \
--        -v app_password="'<choose-runtime-password>'" \
--        -f scripts/db-setup.sql
--
-- Passwords are passed on the command line and never stored in this file.
-- (Keep the inner single quotes: psql substitutes the value as an SQL literal.)
--
-- Roles
--   bcis_owner  owns the databases and every table. Used ONLY for migrations
--               (php artisan migrate). Never put it in the running app's .env.
--   bcis_app    limited runtime role used by the web app, queue worker and
--               scheduler. Can read and write rows but cannot create, alter or
--               drop anything. Later phases REVOKE UPDATE/DELETE on append-only
--               tables (INV-02, INV-04, INV-18) in their own migrations.
--
-- Databases
--   bcis        development / production
--   bcis_test   Pest suite (RefreshDatabase wipes it; tests refuse other names)

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE bcis_owner LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE', :owner_password)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bcis_owner') \gexec

SELECT format('CREATE ROLE bcis_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT', :app_password)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bcis_app') \gexec

-- Re-running the script rotates the passwords.
ALTER ROLE bcis_owner PASSWORD :owner_password;
ALTER ROLE bcis_app PASSWORD :app_password;

SELECT 'CREATE DATABASE bcis OWNER bcis_owner ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'bcis') \gexec

SELECT 'CREATE DATABASE bcis_test OWNER bcis_owner ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'bcis_test') \gexec

-- Apply the same hardening to both databases.
\connect bcis
\i scripts/db-setup-grants.sql

\connect bcis_test
\i scripts/db-setup-grants.sql
