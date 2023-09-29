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
                $item['ip'] = $this->maskIpAddress($item['ip']);
                return $item;
            });

        return response([
            'data' => $record
        ]);
    }

    private function maskIpAddress($ipAddress)
    {
        // Check if the IP address is IPv4
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $parts[2] = '*';
            $parts[3] = '*';
            return implode('.', $parts);
        }

        // Check if the IP address is IPv6
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ipAddress);
            $parts[count($parts) - 2] = '*';
            $parts[count($parts) - 1] = '*';
            return implode(':', $parts);
        }

        // Return the original IP address if it's not valid
        return $ipAddress;
    }


}
