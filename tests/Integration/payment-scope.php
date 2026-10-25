<?php
test('Old merchant flags and split settings cannot change a WHMCS checkout', function () {
    list($a,$p,$w,$scope)=environment();
    foreach (array('disputes','subscriptions','finance','treasury','permanent_accounts','connect') as $feature) { $a->settings->set('feature_'.$feature,true); }
    $a->settings->set('connect_policy_'.$scope['id'],array('mode'=>'destination','destination'=>'acct_other','platform_fee'=>'10.00'));
    beginPayment($a);
    $calls=array_values(array_filter($p->calls,function($call){return $call[0]==='POST' && $call[1]==='/v1/checkout-sessions';}));
    same(1,count($calls)); same('100.00',$calls[0][2]['pricing']['amount']);
    same(false,isset($calls[0][2]['transfer_data'])); same(false,isset($calls[0][2]['platform_fee'])); same(0,$w->posts);
});
test('Removed merchant actions reject forged requests without provider calls', function () {
    list($a,$p)=environment(); $actions=new \Bachs\Whmcs\Http\AdminActions($a);
    foreach (array('finance_read','payout_preview','payout_confirm','destination_preview','destination_confirm','schedule_preview','schedule_confirm',
        'virtual_preview','virtual_confirm','dispute_refresh','dispute_draft','dispute_upload','dispute_preview','dispute_confirm',
        'subscription_map','subscription_offer','cycle_map','subscription_release','subscription_preview','subscription_confirm',
        'product_preview','product_confirm','connect_sync','transfer_preview','transfer_confirm','split_save','connect_create_preview',
        'connect_create_confirm','onboarding','direct_scope_preview','direct_scope_confirm') as $action) {
        fails('unknown_admin_action',function () use ($actions,$action) { $actions->run(array('action'=>$action)); });
    }
    same(0,count($p->calls));
});
test('Out-of-scope signed events are retained without provider mutations or invoice posting', function () {
    list($a,$p,$w,$scope)=environment(); $webhooks=new \Bachs\Whmcs\Application\Webhooks($a);
    foreach (array('dispute.created','payout.paid','customer.subscription.created','invoice.paid') as $type) {
        list($raw,$headers)=signedEvent($a,$scope,$type,array('payment_id'=>'unknown_payment'),str_replace('.','_',$type));
        $id=$webhooks->ingest('sandbox',$raw,$headers); $webhooks->process($id);
        same('unhandled',$a->store->get('webhook_events',$id)['state']);
    }
    same(0,count($p->calls)); same(0,$w->posts);
});
test('Legacy subscription attempts cannot become ordinary invoice payments', function () {
    list($a,$p,$w)=environment(); $attempt=beginPayment($a); $p->succeed($attempt);
    $a->store->update('attempts',$attempt['id'],array('purpose'=>'subscription'));
    same('review',(new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($attempt['id'])); same(0,$w->posts);
});
test('Scope reduction preserves existing merchant-resource history during schema activation', function () {
    list($a)=environment();
    $a->store->pdo->exec('CREATE TABLE mod_bachs_payouts (id TEXT PRIMARY KEY, data TEXT)');
    $a->store->pdo->exec("INSERT INTO mod_bachs_payouts (id,data) VALUES ('historic','retained financial history')");
    \Bachs\Whmcs\Infrastructure\Storage\Schema::migrate($a->store->pdo);
    same('retained financial history',$a->store->pdo->query("SELECT data FROM mod_bachs_payouts WHERE id='historic'")->fetchColumn());
});
test('Admin settings do not expose removed tools even with their old feature flags enabled', function () {
    list($a)=environment();
    foreach (array('disputes','subscriptions','finance','treasury','permanent_accounts','connect') as $feature) { $a->settings->set('feature_'.$feature,true); }
    $oldGet=$_GET; $oldServer=$_SERVER;
    try {
        $_GET=array('page'=>'settings'); $_SERVER['REQUEST_METHOD']='GET';
        $html=(new \Bachs\Whmcs\Http\AdminView($a,'addonmodules.php?module=bachs_admin'))->render();
        foreach (array('finance','connect','disputes','subscriptions') as $page) { same(false,strpos($html,'&amp;page='.$page)!==false); }
        same(false,strpos($html,'name="feature_')!==false); truth(strpos($html,'Save payment settings')!==false);
    } finally { $_GET=$oldGet; $_SERVER=$oldServer; }
});
