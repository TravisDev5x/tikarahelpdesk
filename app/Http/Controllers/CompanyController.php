<?php

namespace App\Http\Controllers;

use App\Models\OperatorProfile;
use App\Models\Plan;
use App\Services\OperatorScopeService;
use App\Services\TenantClientResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function __construct(
        protected OperatorScopeService $operatorScope,
        protected TenantClientResolver $tenantResolver,
    ) {}

    /**
     * Staff de un cliente/tenant (no operador MSP): "Mi empresa" es su propio
     * registro Client (la entidad del tenant), no el OperatorProfile del MSP
     * que administra la plataforma -- son conceptos distintos aunque ambos
     * respondan a "mi empresa" según quién pregunta.
     */
    private function tenantClientRedirect(): ?RedirectResponse
    {
        $user = auth()->user();
        if ($user->is_operator) {
            return null;
        }

        $clientId = $this->tenantResolver->resolve($user);

        return $clientId ? redirect()->route('clients.show', $clientId) : null;
    }

    /**
     * Perfil de la empresa (operador) al que pertenece el usuario autenticado —
     * no necesariamente el usuario mismo (puede ser staff de un cliente/operador).
     * Devuelve [operatorId, profile]; operatorId es null para cuentas de
     * plataforma sin operador resoluble (ej. super-admin).
     *
     * @return array{0: ?int, 1: ?OperatorProfile}
     */
    private function resolveOperatorAndProfile(): array
    {
        $operatorId = $this->operatorScope->resolveOperatorUserId(auth()->user());
        $profile = $operatorId ? OperatorProfile::where('user_id', $operatorId)->first() : null;

        return [$operatorId, $profile];
    }

    public function show(): Response|RedirectResponse
    {
        $user = auth()->user();

        // Un super_admin administra la plataforma, no una empresa propia: "Mi empresa"
        // para esta cuenta es la vista de tenants (clientes) que ya existe en /clients,
        // no un perfil de operador que nunca tendrá.
        if ($user->hasRole('super_admin')) {
            return redirect()->route('clients.index');
        }

        if ($redirect = $this->tenantClientRedirect()) {
            return $redirect;
        }

        [$operatorId, $profile] = $this->resolveOperatorAndProfile();

        if ($operatorId === null) {
            return redirect()->route('home')
                ->with('info', 'Tu cuenta no tiene una empresa asociada.');
        }

        if (! $profile) {
            return redirect()->route('onboarding.show')
                ->with('info', 'Completa tu perfil de empresa primero');
        }

        $profile->load('plan');

        return Inertia::render('Company/Show', [
            'profile' => $profile,
            'plan' => $profile->plan,
            'plans' => Plan::activePublic()->get([
                'id',
                'name',
                'slug',
                'type',
                'price_monthly',
                'trial_days',
                'highlighted',
            ]),
            'can' => [
                'edit' => $user->can('company.edit'),
            ],
        ]);
    }

    public function edit(): Response|RedirectResponse
    {
        abort_unless(auth()->user()->can('company.edit'), 403);

        $user = auth()->user();
        if (! $user->is_operator) {
            $clientId = $this->tenantResolver->resolve($user);
            if ($clientId) {
                return redirect()->route('clients.edit', $clientId);
            }
        }

        [$operatorId, $profile] = $this->resolveOperatorAndProfile();

        if ($operatorId === null) {
            return redirect()->route('home')
                ->with('info', 'Tu cuenta no tiene una empresa asociada.');
        }

        if (! $profile) {
            return redirect()->route('onboarding.show')
                ->with('info', 'Completa tu perfil de empresa primero');
        }

        return Inertia::render('Company/Edit', [
            'profile' => $profile,
            'plans' => Plan::activePublic()->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('company.edit'), 403);

        $validated = $request->validate([
            'business_name' => 'required|string|max:255',
            'rfc' => ['nullable', 'string', 'min:12', 'max:13', 'regex:/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/'],
            'phone' => 'nullable|string|size:10|regex:/^\d{10}$/',
            'website' => 'nullable|url|max:255',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:255',
            'country' => 'required|string|size:2',
            'logo' => 'nullable|image|max:2048',
        ]);

        [$operatorId, $profile] = $this->resolveOperatorAndProfile();

        if ($operatorId === null) {
            return redirect()->route('home')
                ->with('info', 'Tu cuenta no tiene una empresa asociada.');
        }

        if (! $profile) {
            return redirect()->route('onboarding.show')
                ->with('info', 'Completa tu perfil de empresa primero');
        }

        $data = collect($validated)->only([
            'business_name',
            'rfc',
            'phone',
            'website',
            'address',
            'city',
            'country',
        ])->all();

        if ($request->hasFile('logo')) {
            if ($profile->logo_path) {
                Storage::disk('public')->delete($profile->logo_path);
            }

            $data['logo_path'] = $request->file('logo')->store(
                'operator-logos/'.auth()->id(),
                'public'
            );
        }

        $profile->update($data);

        return redirect()->route('company.show')
            ->with('success', 'Perfil de empresa actualizado');
    }

    public function destroyLogo(): RedirectResponse
    {
        abort_unless(auth()->user()->can('company.edit'), 403);

        [, $profile] = $this->resolveOperatorAndProfile();

        if ($profile?->logo_path) {
            Storage::disk('public')->delete($profile->logo_path);
            $profile->update(['logo_path' => null]);
        }

        return back()->with('success', 'Logo eliminado');
    }
}
