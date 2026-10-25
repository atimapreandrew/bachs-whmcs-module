<?php
foreach (array('NGN'=>'150000.00','USDT_TRC20'=>'100.012345') as $currency=>$amount) {
    test('Converted hosted collection retains the invoice base allocation: '.$currency,function ()use($currency,$amount){
        list($a,$p,$w)=environment(); $a->settings->set('adaptive_allocation',true); $at=beginPayment($a); $pid=$p->succeed($at);
        $p->payments[$pid]['currency']=$currency; $p->payments[$pid]['amount']=$amount; $p->payments[$pid]['amount_paid']=$amount; $p->payments[$pid]['amount_remaining']='0';
        same('posted',(new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id']));
        $record=$a->store->listing('payments')[0]; same('100.00',$record['data']['amount']); same($currency,$record['data']['collection_currency']); same('100.00',array_values($w->transactions)[0]['amountin']);
    });
}
test('Converted collection cannot change an attempt policy after checkout began',function () {
    list($a,$p,$w)=environment(); $at=beginPayment($a); $pid=$p->succeed($at); $a->settings->set('adaptive_allocation',true); $p->payments[$pid]['currency']='NGN'; $p->payments[$pid]['amount']='150000.00'; $p->payments[$pid]['amount_paid']='150000.00';
    same('review',(new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id'])); same(0,$w->posts);
});
test('Large historical attempt counts cannot hide an unresolved receipt',function () {
    list($a,$p,$w,$scope)=environment(); $at=beginPayment($a);
    for($i=0;$i<510;$i++)$a->store->insert('attempts',$scope['id'],'historic:'.$i,array('snapshot'=>'older','reference'=>'old'.$i),'failed',array('invoice_id'=>42,'client_id'=>1));
    same($at['id'],(new \Bachs\Whmcs\Application\Checkout($a))->start(42,1)['attempt_id']); same(1,$p->writes('/v1/checkout-sessions'));
});
