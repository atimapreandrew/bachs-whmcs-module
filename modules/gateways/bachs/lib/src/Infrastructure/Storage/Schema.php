<?php

namespace Bachs\Whmcs\Infrastructure\Storage;

class Schema
{
    const VERSION = 1;
    public static $tables = array('settings', 'merchant_scopes', 'customers', 'payment_methods', 'consents',
        'attempts', 'operations', 'payments', 'allocations', 'webhook_events', 'jobs', 'refunds',
        'reconciliation_items', 'audit_log', 'schema_migrations', 'locks');

    public static function ensure(\PDO $pdo)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            $row = $pdo->query('SELECT data FROM mod_bachs_schema_migrations LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
            $data = $row ? json_decode($row['data'], true) : array();
            if (isset($data['version']) && (int)$data['version'] >= self::VERSION) {
                return;
            }
        } catch (\PDOException $error) {
            // Only a missing module table warrants installation. Permission and
            // connection failures must not turn every request into a migration.
            if (!in_array((string)$error->getCode(), array('42S02', 'HY000'), true) ||
                ($error->getCode() === 'HY000' && strpos($error->getMessage(), 'no such table') === false)) {
                throw $error;
            }
        }
        self::migrate($pdo);
    }

    public static function migrate(\PDO $pdo)
    {
        $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach (self::$tables as $table) {
            $name = 'mod_bachs_' . $table;
            $ascii = $mysql ? ' CHARACTER SET ascii COLLATE ascii_bin' : '';
            $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $name . ' (
                id VARCHAR(48)' . $ascii . ' NOT NULL PRIMARY KEY,
                scope VARCHAR(64)' . $ascii . ' NOT NULL,
                identity VARCHAR(64)' . $ascii . ' NOT NULL UNIQUE,
                state VARCHAR(40)' . $ascii . ' NOT NULL,
                invoice_id BIGINT NOT NULL DEFAULT 0,
                client_id BIGINT NOT NULL DEFAULT 0,
                provider_hash VARCHAR(64)' . $ascii . ' NOT NULL,
                data ' . ($mysql ? 'MEDIUMTEXT' : 'TEXT') . ' NOT NULL,
                created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
                next_run BIGINT NOT NULL DEFAULT 0,
                lease_token VARCHAR(48)' . $ascii . ' NOT NULL DEFAULT \'\',
                lease_until BIGINT NOT NULL DEFAULT 0
            )' . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
            foreach (
                array('scope_state' => 'scope,state', 'invoice' => 'invoice_id', 'client' => 'client_id',
                'provider' => 'scope,provider_hash', 'due' => 'state,next_run,lease_until') as $suffix => $columns
            ) {
                $index = $name . '_' . $suffix;
                if ($mysql) {
                    $s = $pdo->query("SHOW INDEX FROM " . $name . " WHERE Key_name = '" . $index . "'");
                    if (!$s->fetch()) {
                        $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $name . ' (' . $columns . ')');
                    }
                } else {
                    $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $name . ' (' . $columns . ')');
                }
            }
        }
        $store = new Store($pdo, new \Bachs\Whmcs\Infrastructure\SystemClock());
        $store->put('schema_migrations', '', 'schema-1', array('version' => 1), 'completed');
    }
}
