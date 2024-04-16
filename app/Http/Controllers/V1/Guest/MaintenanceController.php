<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Response;

class MaintenanceController extends Controller
{
    /**
     * 显示维护模式页面。
     *
     * @return Response
     */
    public function index()
    {
        return view('errors.maintenance');
    }
}
