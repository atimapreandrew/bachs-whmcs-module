<?php

namespace Bachs\Whmcs\Domain;

interface SecretStore
{
    public function seal($plaintext);
    public function open($ciphertext);
}
