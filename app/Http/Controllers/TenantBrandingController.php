<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithTenants;
use App\Http\Requests\Tenant\UpdateBrandingRequest;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

class TenantBrandingController extends Controller
{
    use InteractsWithTenants;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to retrieve branding settings.'],
            ]);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return ApiResponse::error('tenant_not_found', 'The selected tenant could not be found.', null, Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $tenant->branding(),
        ]);
    }

    public function update(UpdateBrandingRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->resolveTenantContext($request, $user);

        if ($tenantId === null) {
            $this->throwValidationException([
                'tenant_id' => ['Tenant context is required to update branding settings.'],
            ]);
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return ApiResponse::error('tenant_not_found', 'The selected tenant could not be found.', null, Response::HTTP_NOT_FOUND);
        }

        $payload = $request->validated();

        $settings = $tenant->settings_json;
        $settings = is_array($settings) ? $settings : [];

        $branding = Arr::get($settings, 'branding');
        $branding = is_array($branding) ? $branding : [];

        foreach (['logo_url', 'email_from', 'email_reply_to'] as $field) {
            if (array_key_exists($field, $payload)) {
                $branding[$field] = $payload[$field];
            }
        }

        if (array_key_exists('colors', $payload)) {
            $existingColors = Arr::get($branding, 'colors');
            $existingColors = is_array($existingColors) ? $existingColors : [];
            $providedColors = $payload['colors'] ?? [];

            foreach (['primary', 'accent', 'bg', 'text'] as $colorKey) {
                if (is_array($providedColors) && array_key_exists($colorKey, $providedColors)) {
                    $existingColors[$colorKey] = $providedColors[$colorKey];
                }
            }

            $branding['colors'] = $existingColors;
        }

        $settings['branding'] = $branding;

        $tenant->settings_json = $settings;
        $tenant->save();

        return response()->json([
            'data' => $tenant->branding(),
        ]);
    }
}
