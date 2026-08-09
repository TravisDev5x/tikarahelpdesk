<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\OnboardingRedirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Verificación de autenticación por sesión (guard web).
 * Solo para consumo del frontend autenticado por cookies.
 * NO usar en rutas API.
 */
class CheckAuthController extends Controller
{
    /**
     * GET /check-auth (ruta web, middleware auth).
     * Retorna JSON con authenticated y user (id, nombre, email, roles, permissions).
     */
    public function __invoke(): JsonResponse
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return response()->json(['authenticated' => false, 'user' => null], 401);
        }

        $user->loadMissing([
            'area:id,name',
            'site:id,name,client_id',
            'site.client:id,name',
            'operatorProfile:id,user_id',
        ]);

        $roles = $user->getCachedRoleNames()->values()->all();
        $permissions = $user->getCachedPermissions()->values()->all();

        $userPayload = [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'paternal_last_name' => $user->paternal_last_name,
            'maternal_last_name' => $user->maternal_last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_url,
            'status' => $user->status,
            'theme' => $user->theme,
            'theme_color' => $user->theme_color,
            'locale' => $user->locale,
            'ui_density' => $user->ui_density,
            'sidebar_state' => $user->sidebar_state,
            'sidebar_position' => $user->sidebar_position,
            'sidebar_hover_preview' => $user->sidebar_hover_preview,
            'is_operator' => $user->is_operator,
            'client_id' => $user->site?->client_id ?? $user->client_id,
            'onboarding_completed' => $user->onboarding_completed,
            'is_blacklisted' => $user->is_blacklisted,
            'force_password_change' => $user->force_password_change ?? false,
            'availability' => $user->availability,
            'area' => $user->area?->name,
            'google_linked' => (bool) $user->google_id,
            'microsoft_linked' => (bool) $user->microsoft_id,
            'site' => $user->site?->name,
            'employee_number' => $user->employee_number,
            'client_name' => $user->site?->client?->name,
        ];

        $onboarding = app(OnboardingRedirectService::class);

        return response()->json([
            'authenticated' => true,
            'user' => $userPayload,
            'roles' => $roles,
            'permissions' => $permissions,
            'onboarding_redirect' => $onboarding->redirectPath($user),
            'flash' => [
                'success' => session()->pull('success'),
                'error' => session()->pull('error'),
                'info' => session()->pull('info'),
                'warning' => session()->pull('warning'),
            ],
        ]);
    }
}
