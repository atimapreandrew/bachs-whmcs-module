<?php

namespace Bachs\Whmcs\Domain;

interface JobQueue
{
    public function enqueue($type, $scope, $aggregate, $delay = 0);
    public function claim();
}
