-- Included by db-setup.sql for each BCIS database. Not run on its own.
-- Assumes the current database is bcis or bcis_test.

\set ON_ERROR_STOP on

-- Nobody but the owner and bcis_app may connect; PUBLIC loses its defaults.
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO bcis_app', current_database()) \gexec

-- Schema: the owner creates objects, the runtime role can only use them.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO bcis_owner;
GRANT USAGE ON SCHEMA public TO bcis_app;

-- Tables and sequences are created later by bcis_owner migrations. These
-- default privileges give bcis_app data access to each one automatically.
-- Append-only tables get a stricter REVOKE in the migration that creates them.
ALTER DEFAULT PRIVILEGES FOR ROLE bcis_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO bcis_app;
ALTER DEFAULT PRIVILEGES FOR ROLE bcis_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO bcis_app;
