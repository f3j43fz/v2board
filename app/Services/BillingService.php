<?php

namespace App\Services;

use App\Models\Plan;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class BillingService
{
    public function getPlan($planId)
    {
        $cacheKey = CacheKey::get('PLAN', $planId);
        return Cache::remember($cacheKey, 60, function () use ($planId) {
            return Plan::find($planId);
        });
    }


    public function calculateCost($user, $upstream, $downstream, $rate)
    {
        $totalData = $upstream + $downstream;
        $plan = $this->getPlan($user->plan_id);
        $transferUnitPriceInCents = $this->getTransferUnitPrice($user, $plan);

        return ($totalData / (1024.0 * 1024.0 * 1024.0)) * $rate * $transferUnitPriceInCents;
    }

    private function getTransferUnitPrice($user, $plan)
    {
        return !empty($user->temporary_transfer_discount)
            ? intval($plan->transfer_unit_price * $user->temporary_transfer_discount / 100)
            : $plan->transfer_unit_price;
    }
}
