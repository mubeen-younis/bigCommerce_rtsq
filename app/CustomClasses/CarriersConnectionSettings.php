<?php

namespace App\CustomClasses;

use App\Endpoints\Endpoints;

class CarriersConnectionSettings
{
    public $testConnectionUrl;

    public function __construct()
    {
        $this->testConnectionUrl = Endpoints::testConnectionEndpoint();
    }
}
