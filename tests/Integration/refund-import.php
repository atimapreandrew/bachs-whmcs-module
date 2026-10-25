<?php
test('Dashboard discovery and local refund polling share one provider-refund identity',function () {
    list($a,$p,$w,$scope)=environment(); $at=beginPayment($a); $p->succeed($at); (new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id']); $payment=$a->store->listing('payments')[0];
    $service=new \Bachs\Whmcs\Application\Refunds($a); $r=$service->request($payment['id'],'100.00','Synthetic refund ownership','admin:10');
    $resource=$p->refunds[$r['data']['refund_id']]; $imported=$service->import($scope,$payment,$resource);
    same($r['id'],$imported['id']); same(1,$a->store->count('refunds')); same($r['id'],$a->store->byProvider('refunds',$scope['id'],$resource['refund_id'])['id']);
});
test('Lost local refund response is recovered by dashboard discovery without a second ledger owner',function () {
    list($a,$p,$w,$scope)=environment(); $at=beginPayment($a); $pid=$p->succeed($at); (new \Bachs\Whmcs\Application\PaymentRecognition($a))->reconcile($at['id']); $payment=$a->store->listing('payments')[0]; $p->failNextWrite=true;
    fails('transport_failure',function ()use($a,$payment){(new \Bachs\Whmcs\Application\Refunds($a))->request($payment['id'],'100.00','Synthetic unknown refund','admin:10');});
    $local=$a->store->listing('refunds')[0]; $r=(new \Bachs\Whmcs\Application\Refunds($a))->import($scope,$payment,array('refund_id'=>'ref_lost','charge_id'=>$pid,'reference'=>$local['data']['reference'],'status'=>'success','requested_amount'=>'100.00','refunded_amount'=>'100.00'));
    same($local['id'],$r['id']); same(1,$a->store->count('refunds')); same('accounting_pending',$r['state']);
});
