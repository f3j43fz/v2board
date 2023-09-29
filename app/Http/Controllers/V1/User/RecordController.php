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
        // not show for admins
        $user = User::find($request->user['id']);
        if($user->is_admin){
            return response([
                'data' => []
            ]);
        }

        $userID = $user->id;
        $record = Tokenrequest::where('user_id', $userID)
            ->orderBy('requested_at', 'DESC')
            ->get()
            ->map(function ($item) {
                unset($item['user_id']);
                return $item;
            });

        return response([
            'data' => $record
        ]);
    }

}
