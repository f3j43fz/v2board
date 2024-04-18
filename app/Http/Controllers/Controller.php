<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use voku\helper\AntiXSS;

class Controller extends BaseController
{
    use DispatchesJobs, ValidatesRequests;

    protected $antiXss;
    public function __construct(AntiXSS $antiXss)
    {
        $this->antiXss = $antiXss;
    }
}
