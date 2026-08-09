<?php

namespace App\Http\Middleware;

use App\Services\OnboardingRedirectService;
use App\Services\TenantContextService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'inertia';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($request->is('check-auth')) {
            return parent::share($request);
        }

        $user = $request->user();

        if ($user) {
            // load() en vez de loadMissing(): otros servicios en el pipeline
            // (p.ej. TenantClientResolver) ya pueden haber cargado 'site' con
            // un subconjunto de columnas (sin 'name'); loadMissing() no
            // recarga relaciones ya marcadas como cargadas, dejando 'name'
            // ausente aquí. load() siempre trae el set completo que este
            // middleware necesita para los props compartidos.
            $user->load([
                'area:id,name',
                'site:id,name,client_id',
                'site.client:id,name,logo_path',
            ]);
        }

        $onboarding = app(OnboardingRedirectService::class);
        $tenant = app(TenantContextService::class)->resolve($request);

        return [
            ...parent::share($request),
            'tenant' => $tenant->brandingForFrontend(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'paternal_last_name' => $user->paternal_last_name,
                    'maternal_last_name' => $user->maternal_last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'employee_number' => $user->employee_number,
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
                    'client_id' => $user->client_id ?? $user->site?->client_id,
                    'client_name' => $user->site?->client?->name,
                    'client_logo_path' => $user->site?->client?->logo_path,
                    'onboarding_completed' => $user->onboarding_completed,
                    'onboarding_redirect' => $onboarding->redirectPath($user),
                    'is_blacklisted' => $user->is_blacklisted,
                    'force_password_change' => $user->force_password_change ?? false,
                    'area' => $user->area?->name,
                    'area_id' => $user->area_id,
                    'google_linked' => (bool) $user->google_id,
                    'microsoft_linked' => (bool) $user->microsoft_id,
                    'site' => $user->site?->name,
                    'site_id' => $user->site_id,
                    'availability' => $user->availability,
                    'roles' => $user->getCachedRoleNames()->values()->all(),
                    'permissions' => $user->getCachedPermissions()->values()->all(),
                ] : null,
            ],
            'notifications' => Inertia::defer(fn () => $user
                ? $user->notifications()
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'data', 'read_at', 'created_at'])
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'data' => $n->data,
                        'read_at' => $n->read_at,
                        'created_at' => $n->created_at,
                    ])
                    ->values()
                    ->all()
                : []),
            'unread_notifications_count' => Inertia::defer(fn () => $user
                ? $user->unreadNotifications()->count()
                : 0),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'authProviders' => [
                'google' => (bool) config('services.google.client_id'),
                'microsoft' => (bool) config('services.azure.client_id'),
            ],
        ];
    }
}
