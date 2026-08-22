<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\Services\OperatorScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function __construct(protected OperatorScopeService $operatorScope) {}

    /**
     * Auditoría de bugs críticos (2026-08-22): este controlador no tenía
     * NINGÚN scoping de tenant -- index() sin site_id devolvía locations de
     * todos los clientes, y store/update/destroy no verificaban que la
     * sede (ni, en update, la sede nueva) perteneciera al tenant del
     * usuario, permitiendo IDOR cross-tenant total vía site_id/id
     * adivinado. Mismo mecanismo que SiteController::index/authorizeSite.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $siteId = $request->query('site_id');
        $query = Location::with(['site:id,name,type', 'parent:id,name'])
            ->whereHas('site', fn ($q) => $this->operatorScope->applyOnSites($q, $user));
        if ($siteId) {
            $query->where('site_id', $siteId);
        }

        return $query->orderBy('site_id')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $data = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'name' => ['required', 'min:2'],
            'code' => ['nullable', 'max:20', 'unique:locations,code'],
            // Ubicación jerárquica (fase 2.3, auditoría de Inventario) --
            // aditivo, ambos opcionales.
            'parent_id' => ['nullable', 'exists:locations,id'],
            'type' => ['nullable', 'string', 'in:building,floor,room,rack,warehouse,other'],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $data['is_active'] ?? true;

        $this->operatorScope->authorizeSite($user, Site::findOrFail($data['site_id']));
        if (! empty($data['parent_id'])) {
            $this->authorizeParentLocation($user, (int) $data['parent_id']);
        }

        $siteId = $data['site_id'];
        // unique per site
        $request->validate([
            'name' => Rule::unique('locations', 'name')->where('site_id', $siteId),
        ]);

        $location = Location::create($data);

        return response()->json($location, 201);
    }

    public function update(Request $request, Location $location)
    {
        $user = Auth::user();
        $this->operatorScope->authorizeSite($user, $location->site);

        $data = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'name' => ['required', 'min:2'],
            'code' => ['nullable', 'max:20', Rule::unique('locations', 'code')->ignore($location->id)],
            'parent_id' => ['nullable', 'exists:locations,id', Rule::notIn([$location->id])],
            'type' => ['nullable', 'string', 'in:building,floor,room,rack,warehouse,other'],
            'is_active' => ['boolean'],
        ]);

        // La sede pudo cambiar respecto a la actual -- también hay que
        // verificar la nueva, no solo la que ya tenía la location.
        $this->operatorScope->authorizeSite($user, Site::findOrFail($data['site_id']));
        if (! empty($data['parent_id'])) {
            $this->authorizeParentLocation($user, (int) $data['parent_id']);
        }

        $request->validate([
            'name' => Rule::unique('locations', 'name')
                ->where('site_id', $data['site_id'])
                ->ignore($location->id),
        ]);

        $location->update($data);

        return response()->json($location);
    }

    public function destroy(Location $location)
    {
        $this->operatorScope->authorizeSite(Auth::user(), $location->site);

        if ($location->users()->exists()) {
            return response()->json(['message' => 'No se puede eliminar: hay usuarios asignados'], 422);
        }
        $location->delete();

        return response()->noContent();
    }

    /** Evita enlazar la jerarquía a una location de otro tenant vía parent_id. */
    private function authorizeParentLocation(User $user, int $parentId): void
    {
        $parent = Location::with('site')->findOrFail($parentId);
        $this->operatorScope->authorizeSite($user, $parent->site);
    }
}
