<?php
use Bachs\Whmcs\Domain\Money;
use Bachs\Whmcs\Infrastructure\Bachs\SignatureVerifier;
use Bachs\Whmcs\Infrastructure\Bachs\Urls;
use Bachs\Whmcs\Infrastructure\Storage\EncryptedSecrets;
use Bachs\Whmcs\Infrastructure\SafeData;

test('Decimal arithmetic retains precision beyond machine integer range', function () {
    same('100000000000000000000.01', Money::add('99999999999999999999.99', '0.02'));
    same('0.01', Money::subtract('100000000000000000000.00', '99999999999999999999.99'));
    same(-1, Money::compare('99999999999999999999.99', '100000000000000000000.00'));
    same('20', Money::normalize('20', 0)); same('1.00000001', Money::add('1', '0.00000001', 8));
});
foreach (array('1e2','-1','+1','NaN','Infinity','1,000.00','01.00',' 1.00','1.','0x20','1\n') as $value) {
    test('Reject malformed monetary input ' . json_encode($value), function () use ($value) { fails('invalid_decimal', function () use ($value) { Money::normalize($value); }); });
}
test('Never accepts a PHP float as an authoritative amount', function () { fails('invalid_decimal', function () { Money::normalize(10.25); }); });
test('Reject excess precision, negative subtraction and zero payment', function () {
    fails('unsupported_precision', function () { Money::normalize('1.001'); }); fails('negative_result', function () { Money::subtract('1','2'); }); fails('amount_must_be_positive', function () { Money::positive('0.00'); });
});
test('Huge values overflow the explicit domain bound, not machine integers', function () { fails('invalid_decimal', function () { Money::normalize(str_repeat('9', 37)); }); });
test('Authenticated encryption round trip rejects tampering and wrong keys', function () {
    $v = new EncryptedSecrets(str_repeat('x',32)); $c = $v->seal('sensitive'); same('sensitive', $v->open($c)); truth(strpos($c, 'sensitive') === false);
    $bytes = base64_decode(substr($c,3)); $bytes[40] = chr(ord($bytes[40]) ^ 1);
    fails('invalid_ciphertext', function () use ($v, $bytes) { $v->open('v1:' . base64_encode($bytes)); });
    fails('invalid_ciphertext', function () use ($c) { (new EncryptedSecrets(str_repeat('y',32)))->open($c); });
});
test('Signature verifies exact bytes and every repeated v1 digest', function () {
    $clock = new TestClock(); $raw = '{ "id": "evt_test" }'; $t = $clock->now(); $sig = hash_hmac('sha256', $t . '.' . $raw, 'correct');
    $v = new SignatureVerifier($clock);
    same('v2', $v->verify($raw, array('X-Bachs-Signature-V2' => 't=' . $t . ',v1=' . str_repeat('0',64) . ',v1=' . $sig), array('correct'))['scheme']);
    fails('invalid_signature', function () use ($v,$raw,$t,$sig) { $v->verify(str_replace(' ', '', $raw), array('X-Bachs-Signature-V2' => 't=' . $t . ',v1=' . $sig), array('correct')); });
});
test('Invalid V2 cannot downgrade to valid legacy headers', function () {
    $c = new TestClock(); $t = $c->now(); $raw = '{}';
    fails('invalid_signature', function () use ($c,$t,$raw) { (new SignatureVerifier($c))->verify($raw, array('x-bachs-signature-v2' => 't=' . $t . ',v1=' . str_repeat('0',64), 'x-bachs-timestamp' => (string)$t, 'x-bachs-signature' => hash_hmac('sha256',$t . '.' . $raw,'correct')), array('correct'), true); });
});
foreach (array(-301,301) as $drift) { test('Reject signature clock drift ' . $drift, function () use ($drift) { $c = new TestClock(); $t = $c->now() + $drift; fails('invalid_signature', function () use ($c,$t) { (new SignatureVerifier($c))->verify('{}', array('x-bachs-signature-v2' => 't=' . $t . ',v1=' . hash_hmac('sha256',$t . '.{}','s')), array('s')); }); }); }
foreach (array('t=1,t=1,v1=abc','t=1,v1=abc','t=NaN,v1=abc','v1=abc',str_repeat('x',2100)) as $header) { test('Reject malformed signature header ' . substr($header,0,30), function () use ($header) { fails('invalid_signature', function () use ($header) { (new SignatureVerifier(new TestClock()))->verify('{}',array('x-bachs-signature-v2' => $header),array('s')); }); }); }
test('Legacy signatures require explicit compatibility setting', function () {
    $c = new TestClock(); $t = (string)$c->now(); $h = array('x-bachs-timestamp' => $t,'x-bachs-signature' => hash_hmac('sha256',$t . '.{}','s'));
    fails('invalid_signature', function () use ($c,$h) { (new SignatureVerifier($c))->verify('{}',$h,array('s')); });
    same('legacy', (new SignatureVerifier($c))->verify('{}',$h,array('s'),true)['scheme']);
});
foreach (array('http://checkout.bachs.io/c/a','https://checkout.bachs.io.evil.invalid/c/a','https://evil.bachs.io/c/a','https://user@checkout.bachs.io/c/a','https://checkout.bachs.io:443/c/a','https://checkout.bachs.io/c/a#b','https://checkout.bachs.io\\@evil.invalid/c') as $url) { test('Reject unsafe provider URL ' . $url, function () use ($url) { fails('unsafe_provider_url', function () use ($url) { Urls::hosted($url); }); }); }
test('Only exact approved checkout and portal origins accepted', function () { same('https://checkout.bachs.io/c/a',Urls::hosted('https://checkout.bachs.io/c/a')); same('https://portal.bachs.io/s/a',Urls::hosted('https://portal.bachs.io/s/a','portal')); });
test('Diagnostic allowlist excludes credentials and payer details', function () {
    same(array('amount' => '10.00','status' => 'succeeded'),SafeData::redact(array('api_key' => 'secret','token' => 'opaque','checkout_url' => 'secret','email' => 'x','amount' => '10.00','status' => 'succeeded')));
});
