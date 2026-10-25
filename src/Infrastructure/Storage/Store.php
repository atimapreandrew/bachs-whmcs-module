<?php

namespace Bachs\Whmcs\Infrastructure\Storage;

use Bachs\Whmcs\Domain\Failure;
use Bachs\Whmcs\Domain\Ids;

class Store implements \Bachs\Whmcs\Domain\LockManager
{
    public $pdo;
    public $clock;

    public function __construct(\PDO $pdo, \Bachs\Whmcs\Domain\Clock $clock)
    {
        $this->pdo = $pdo;
        $this->clock = $clock;
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    private function table($table)
    {
        if (!in_array($table, Schema::$tables, true)) {
            throw new Failure('invalid_table');
        }
        return 'mod_bachs_' . $table;
    }

    public function run($sql, $args = array())
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public function identity($scope, $key)
    {
        return hash('sha256', $scope . ':' . $key);
    }

    private function decode($row)
    {
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode($row['data'], true);
        if (!is_array($row['data'])) {
            throw new Failure('corrupt_record');
        }
        return $row;
    }

    public function find($table, $scope, $key)
    {
        return $this->decode($this->run('SELECT * FROM ' . $this->table($table) . ' WHERE identity=?', array($this->identity($scope, $key)))->fetch(\PDO::FETCH_ASSOC));
    }

    public function get($table, $id)
    {
        return $this->decode($this->run('SELECT * FROM ' . $this->table($table) . ' WHERE id=?', array($id))->fetch(\PDO::FETCH_ASSOC));
    }

    public function byProvider($table, $scope, $id)
    {
        $rows = $this->listing($table, array('scope' => $scope, 'provider_hash' => hash('sha256', $id)), 2);
        if (count($rows) > 1) {
            throw new Failure('provider_mapping_conflict');
        }
        return $rows ? $rows[0] : null;
    }

    public function insert($table, $scope, $key, array $data, $state = 'new', array $columns = array())
    {
        $id = Ids::random();
        $now = $this->clock->now();
        $values = array('id' => $id, 'scope' => $scope, 'identity' => $this->identity($scope, $key), 'state' => $state,
            'invoice_id' => 0, 'client_id' => 0, 'provider_hash' => '', 'data' => json_encode($data),
            'created_at' => $now, 'updated_at' => $now, 'next_run' => 0, 'lease_token' => '', 'lease_until' => 0);
        foreach ($columns as $k => $v) {
            if (!in_array($k, array('invoice_id', 'client_id', 'provider_hash', 'next_run'), true)) {
                throw new Failure('invalid_column');
            }
            $values[$k] = $v;
        }
        if ($values['data'] === false) {
            throw new Failure('json_encoding_failed');
        }
        try {
            $this->run('INSERT INTO ' . $this->table($table) . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')', array_values($values));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || $e->getCode() === '23505' || $e->getCode() === '19') {
                $existing = $this->find($table, $scope, $key);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
        return $this->get($table, $id);
    }

    public function put($table, $scope, $key, array $data, $state = 'new', array $columns = array())
    {
        $row = $this->find($table, $scope, $key);
        return $row ? $this->update($table, $row['id'], $data, $state, $columns) : $this->insert($table, $scope, $key, $data, $state, $columns);
    }

    public function update($table, $id, array $data, $state = null, array $columns = array())
    {
        $row = $this->get($table, $id);
        if (!$row) {
            throw new Failure('record_not_found');
        }
        $merged = array_merge($row['data'], $data);
        $json = json_encode($merged);
        if ($json === false) {
            throw new Failure('json_encoding_failed');
        }
        $values = array('data' => $json, 'updated_at' => $this->clock->now());
        if ($state !== null) {
            $values['state'] = $state;
        }
        foreach ($columns as $k => $v) {
            if (!in_array($k, array('provider_hash', 'client_id', 'invoice_id', 'next_run', 'lease_token', 'lease_until'), true)) {
                throw new Failure('invalid_column');
            }
            $values[$k] = $v;
        }
        $set = array();
        foreach ($values as $k => $v) {
            $set[] = $k . '=?';
        }
        $args = array_values($values);
        $args[] = $id;
        $this->run('UPDATE ' . $this->table($table) . ' SET ' . implode(',', $set) . ' WHERE id=?', $args);
        return $this->get($table, $id);
    }

    public function listing($table, array $filters = array(), $limit = 50, $offset = 0)
    {
        $where = array();
        $args = array();
        foreach ($filters as $k => $v) {
            if (!in_array($k, array('scope', 'state', 'client_id', 'invoice_id', 'provider_hash'), true)) {
                throw new Failure('invalid_filter');
            }
            $where[] = $k . '=?';
            $args[] = $v;
        }
        $sql = 'SELECT * FROM ' . $this->table($table) . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC,id DESC LIMIT ' . max(1, min(500, (int)$limit)) . ' OFFSET ' . max(0, (int)$offset);
        return array_map(array($this, 'decode'), $this->run($sql, $args)->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function count($table, $state = null)
    {
        return (int)$this->run('SELECT COUNT(*) FROM ' . $this->table($table) . ($state === null ? '' : ' WHERE state=?'), $state === null ? array() : array($state))->fetchColumn();
    }

    public function unfinishedAttempts($invoiceId, $clientId = null, $scopeId = null)
    {
        $sql = "SELECT * FROM mod_bachs_attempts WHERE invoice_id=? AND state NOT IN ('failed','expired_verified','cancelled_verified','posted','setup_completed')";
        $args = array((int)$invoiceId);
        if ($clientId !== null) {
            $sql .= ' AND client_id=?';
            $args[] = (int)$clientId;
        }
        if ($scopeId !== null) {
            $sql .= ' AND scope=?';
            $args[] = $scopeId;
        }
        $rows = $this->run($sql . ' ORDER BY created_at,id LIMIT 501', $args)->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) > 500) {
            throw new Failure('too_many_unresolved_attempts');
        }
        return array_map(array($this, 'decode'), $rows);
    }

    public function acquire($name, $seconds = 120)
    {
        $row = $this->insert('locks', '', $name, array(), 'lock');
        $token = Ids::random();
        $ok = $this->run(
            'UPDATE mod_bachs_locks SET lease_token=?,lease_until=? WHERE id=? AND lease_until<=?',
            array($token, $this->clock->now() + $seconds, $row['id'], $this->clock->now())
        )->rowCount();
        if (!$ok) {
            throw new Failure('operation_in_progress');
        }
        return $token;
    }

    public function release($name, $token)
    {
        $this->run('UPDATE mod_bachs_locks SET lease_until=0,lease_token=\'\' WHERE identity=? AND lease_token=?', array($this->identity('', $name), $token));
    }

    public function atomic($callback)
    {
        $this->pdo->beginTransaction();
        try {
            $result = call_user_func($callback);
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } throw $e;
        }
    }

    public function exception($scope, $type, $target, array $evidence = array())
    {
        return $this->insert(
            'reconciliation_items',
            $scope,
            $type . ':' . $target,
            array('type' => $type, 'target' => $target, 'evidence' => $evidence),
            'review'
        );
    }

    public function audit($actor, $action, $scope, $target, array $details = array())
    {
        return $this->insert('audit_log', $scope, Ids::random(), array('actor' => $actor, 'action' => $action,
            'target' => $target, 'details' => \Bachs\Whmcs\Infrastructure\SafeData::redact($details)), 'recorded');
    }
}
