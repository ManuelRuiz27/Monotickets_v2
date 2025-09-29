<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * Administrative access to subscription plans catalogue.
 */
class AdminPlanController extends Controller
{
    /**
     * Return the catalogue of active plans with limits and features.
     */
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('billing_cycle')
            ->orderBy('price_cents')
            ->orderBy('name')
            ->get()
            ->map(static function (Plan $plan): array {
                return [
                    'id' => (string) $plan->id,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'price_cents' => (int) $plan->price_cents,
                    'billing_cycle' => $plan->billing_cycle,
                    'limits' => is_array($plan->limits_json) ? $plan->limits_json : [],
                    'features' => is_array($plan->features_json) ? $plan->features_json : [],
                ];
            });

        return response()->json([
            'data' => $plans,
        ]);
    }
}
