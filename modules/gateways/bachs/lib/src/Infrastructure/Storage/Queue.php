<?php

namespace Bachs\Whmcs\Infrastructure\Storage;

use Bachs\Whmcs\Domain\Ids;

class Queue implements \Bachs\Whmcs\Domain\JobQueue
{
    private $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public function enqueue($type, $scope, $aggregate, $delay = 0)
    {
        return $this->store->insert(
            'jobs',
            $scope,
            $type . ':' . $aggregate,
            array('type' => $type, 'aggregate' => $aggregate, 'tries' => 0),
            'ready',
            array('next_run' => $this->store->clock->now() + $delay)
        );
    }

    public function wake($job, $includeDead = false)
    {
        $current = $this->store->get('jobs', $job['id']);
        if (!$current || !in_array($current['state'], $includeDead ? array('done','dead','retry') : array('done'), true)) {
            return;
        }
        $data = $current['data'];
        $data['tries'] = 0;
        $data['error_code'] = null;
        $this->store->run("UPDATE mod_bachs_jobs SET state='ready',data=?,next_run=?,updated_at=? WHERE id=? AND state=? AND data=?",
            array(json_encode($data), $this->store->clock->now(), $this->store->clock->now(), $job['id'], $current['state'], json_encode($current['data'])));
    }

    public function claim()
    {
        $now = $this->store->clock->now();
        $rows = $this->store->run("SELECT id FROM mod_bachs_jobs WHERE ((state IN ('ready','retry') AND next_run<=?) OR (state='running' AND lease_until<=?)) ORDER BY next_run,id LIMIT 10", array($now, $now))->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $token = Ids::random();
            if ($this->store->run("UPDATE mod_bachs_jobs SET state='running',lease_token=?,lease_until=? WHERE id=? AND ((state IN ('ready','retry') AND next_run<=?) OR (state='running' AND lease_until<=?))", array($token, $now + 180, $row['id'], $now, $now))->rowCount()) {
                return $this->store->get('jobs', $row['id']);
            }
        }
        return null;
    }

    public function finish($job, $retry = null, $error = null)
    {
        $tries = (int)$job['data']['tries'] + ($retry === null ? 0 : 1);
        $data = $job['data'];
        $data['tries'] = $tries;
        $data['error_code'] = $error;
        $state = $retry === null ? 'done' : ($tries >= 12 ? 'dead' : 'retry');
        $updated = $this->store->run(
            'UPDATE mod_bachs_jobs SET state=?,data=?,lease_until=0,lease_token=\'\',next_run=?,updated_at=? WHERE id=? AND lease_token=?',
            array($state, json_encode($data), $this->store->clock->now() + (int)$retry, $this->store->clock->now(), $job['id'], $job['lease_token'])
        )->rowCount();
        if ($updated && $state === 'dead') {
            $this->store->exception($job['scope'], 'job_exhausted', $job['id'], array('error_code' => $error));
        }
    }
}
