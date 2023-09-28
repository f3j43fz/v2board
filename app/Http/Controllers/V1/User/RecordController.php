<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Tokenrequest;
use App\Models\User;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecordController extends Controller
{
    public function fetch(Request $request)
    {
        $record = Tokenrequest::where('token', $request->user['token'])
            ->orderBy('requested_at', 'DESC')
            ->get();
        return response([
            'data' => $record
        ]);
    }

}
