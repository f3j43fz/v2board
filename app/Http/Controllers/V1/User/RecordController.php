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

    private function expandIPv6Address($ip)
    {
        // Check if the input is a valid IPv6 address
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        // Split the IPv6 address into groups
        $groups = explode(':', $ip);

        // Count the number of groups
        $groupCount = count($groups);

        // Find the index of the compressed group (::)
        $compressedGroupIndex = array_search('', $groups);

        // Calculate the number of groups needed to expand the address
        $expandedGroupCount = 8 - $groupCount;

        // Expand the compressed group (::) with zeros
        if ($compressedGroupIndex !== false) {
            $expandedGroups = array_merge(
                array_slice($groups, 0, $compressedGroupIndex),
                array_fill(0, $expandedGroupCount, '0000'),
                array_slice($groups, $compressedGroupIndex + 1)
            );
        } else {
            $expandedGroups = $groups;
        }

        // Pad each group with zeros to ensure four hexadecimal digits
        $expandedGroups = array_map(function ($group) {
            return str_pad($group, 4, '0', STR_PAD_LEFT);
        }, $expandedGroups);

        // Join the expanded groups and format the IPv6 address
        $expandedIp = implode(':', $expandedGroups);

        return $expandedIp;
    }

    private function maskIpAddress($ipAddress)
    {
        // Check if the IP address is IPv4
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $parts[1] = '*';
            $parts[2] = '*';
            return implode('.', $parts);
        }

        // expand ipv6
        $ipv6 = $this->expandIPv6Address($ipAddress);
        $parts = explode(':', $ipv6);
        $parts[count($parts) - 4] = '*';
        $parts[count($parts) - 3] = '*';
        $parts[count($parts) - 2] = '*';
        $parts[count($parts) - 1] = '*';
        return implode(':', $parts);
    }



}
