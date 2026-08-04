<?php

namespace Tests\Feature;

use App\Models\AuthorizationObject;
use App\Models\Client;
use Database\Seeders\AuthorizationObjectSeeder;
use Database\Seeders\FullDemoSeeder;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC v2 (Fase 6), Paso 4: el catálogo es GLOBAL (no por tenant) y es una
 * capa de presentación sobre permisos ya existentes -- no crea permisos
 * nuevos, cada full_permission/read_permission debe existir ya en la
 * tabla `permissions`.
 */
class AuthorizationObjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_module_tree_and_full_read_pairs(): void
    {
        $this->seed(AuthorizationObjectSeeder::class);

        $ticketsParent = AuthorizationObject::where('key', 'tickets')->whereNull('parent_id')->first();
        $this->assertNotNull($ticketsParent);

        $view = AuthorizationObject::where('key', 'tickets.view')->first();
        $this->assertNotNull($view);
        $this->assertSame($ticketsParent->id, $view->parent_id);
        $this->assertSame('tickets.view_area', $view->full_permission);
        $this->assertSame('tickets.view_own', $view->read_permission);

        // Objetos sin variante de solo lectura -- read_permission null.
        $comment = AuthorizationObject::where('key', 'tickets.comment')->first();
        $this->assertNull($comment->read_permission);

        $companyParent = AuthorizationObject::where('key', 'company')->whereNull('parent_id')->first();
        $companyManage = AuthorizationObject::where('key', 'company.manage')->first();
        $this->assertSame($companyParent->id, $companyManage->parent_id);
        $this->assertSame('company.edit', $companyManage->full_permission);
        $this->assertSame('company.view', $companyManage->read_permission);
    }

    /** Cada full_permission/read_permission del catálogo debe existir ya en `permissions` -- no se inventa ninguno. */
    public function test_every_referenced_permission_already_exists_in_the_catalog(): void
    {
        $this->seed(FullDemoSeeder::class);

        $operator = \App\Models\User::where('email', 'admin@tikara.local')->first()
            ?? \App\Models\User::first();
        $client = Client::create(['name' => 'Tenant Catalog', 'operator_user_id' => $operator?->id, 'is_active' => true]);
        setPermissionsTeamId($client->id);
        $this->seed(TenantRoleSeeder::class);

        $this->seed(AuthorizationObjectSeeder::class);

        $names = AuthorizationObject::query()
            ->whereNotNull('full_permission')
            ->pluck('full_permission')
            ->merge(AuthorizationObject::whereNotNull('read_permission')->pluck('read_permission'))
            ->unique();

        foreach ($names as $name) {
            $exists = \Illuminate\Support\Facades\DB::table('permissions')
                ->where('name', $name)->where('guard_name', 'web')->exists();
            $this->assertTrue($exists, "permiso referenciado en el catálogo pero inexistente: {$name}");
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(AuthorizationObjectSeeder::class);
        $this->seed(AuthorizationObjectSeeder::class);

        $this->assertSame(1, AuthorizationObject::where('key', 'tickets.view')->count());
    }
}
