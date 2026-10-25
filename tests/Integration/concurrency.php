<?php

test('Eight concurrent processes cannot post one receipt twice', function () {
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('pcntl is required for the concurrency acceptance test');
    }
    $path = tempnam(sys_get_temp_dir(), 'bachs-concurrency-');
    $dsn = 'sqlite:' . $path;
    list($app,$provider,$oldBridge,$scope,$clock) = environment($dsn);
    $app->store->pdo->exec('PRAGMA journal_mode=WAL');
    $attempt = beginPayment($app);
    $provider->succeed($attempt);
    $app->store->pdo->exec('CREATE TABLE test_native_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, transid TEXT, invoiceid INTEGER, userid INTEGER, gateway TEXT, amountin TEXT, amountout TEXT, refundid INTEGER)');
    $app->store->pdo->exec('CREATE TABLE test_native_effects (count INTEGER)');
    $app->store->pdo->exec('INSERT INTO test_native_effects VALUES (0)');
    $children = array();
    for ($i = 0; $i < 8; ++$i) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout=5000');
            $store = new \Bachs\Whmcs\Infrastructure\Storage\Store($pdo, $clock);
            $settings = new \Bachs\Whmcs\Application\Settings($store, $app->settings->secrets);
            $bridge = new SharedNativeBridge($pdo);
            $engine = new \Bachs\Whmcs\Application\Engine($store, $settings, $bridge, function ($s) use ($provider) {
                return $provider;
            });
            try {
                (new \Bachs\Whmcs\Application\PaymentRecognition($engine))->reconcile($attempt['id']);
                exit(0);
            } catch (\Bachs\Whmcs\Domain\Failure $e) {
                exit($e->reason === 'operation_in_progress' ? 0 : 2);
            } catch (Throwable $e) {
                exit(3);
            }
        }
        if ($pid < 0) {
            throw new RuntimeException('fork failed');
        } $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        same(0, pcntl_wexitstatus($status));
    }
    same(1, (int)$app->store->pdo->query('SELECT COUNT(*) FROM test_native_transactions')->fetchColumn());
    same(1, (int)$app->store->pdo->query('SELECT count FROM test_native_effects')->fetchColumn());
    same(1, $app->store->count('payments', 'posted'));
    unset($app);
    unlink($path);
    foreach (array('-wal','-shm') as $suffix) {
        if (is_file($path . $suffix)) {
            unlink($path . $suffix);
        }
    }
});
class SharedNativeBridge extends FakeWhmcs
{
    private $pdo;
    public function __construct($pdo)
    {
        parent::__construct();
        $this->pdo = $pdo;
    }
    public function transaction($id)
    {
        $s = $this->pdo->prepare('SELECT * FROM test_native_transactions WHERE transid=?');
        $s->execute(array($id));
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function post($invoice, $transid, $amount, $fee, $gateway)
    {
        $this->pdo->exec('UPDATE test_native_effects SET count=count+1');
        $s = $this->pdo->prepare('INSERT INTO test_native_transactions (transid,invoiceid,userid,gateway,amountin,amountout,refundid) VALUES (?,?,1,?,?,\'0.00\',0)');
        $s->execute(array($transid,$invoice,$gateway,$amount));
    }
}
