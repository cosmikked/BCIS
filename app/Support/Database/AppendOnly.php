<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Database guards that make a table append-only (INV-02, INV-04, INV-18).
 * Called from the migration that creates each such table. Two layers:
 *  1. the runtime role bcis_app loses UPDATE, DELETE and TRUNCATE;
 *  2. a trigger rejects them for every role, the table owner included.
 */
final class AppendOnly
{
    public static function installFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bcis_reject_modification() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% on % is not allowed: the table is append-only', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }

    public static function guard(string $table): void
    {
        self::installFunction();

        DB::unprepared(str_replace('__TABLE__', $table, <<<'SQL'
            CREATE TRIGGER __TABLE___no_update_delete
                BEFORE UPDATE OR DELETE ON __TABLE__
                FOR EACH ROW EXECUTE FUNCTION bcis_reject_modification();
            CREATE TRIGGER __TABLE___no_truncate
                BEFORE TRUNCATE ON __TABLE__
                FOR EACH STATEMENT EXECUTE FUNCTION bcis_reject_modification();
            SQL));

        // The runtime role does not exist on a machine set up without
        // scripts/db-setup.sql, so only revoke when it does.
        DB::unprepared(str_replace('__TABLE__', $table, <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'bcis_app') THEN
                    REVOKE UPDATE, DELETE, TRUNCATE ON __TABLE__ FROM bcis_app;
                END IF;
            END
            $$
            SQL));
    }
}
