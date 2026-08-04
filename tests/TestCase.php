<?php

namespace Tests;

use App\Support\Tenancy\PgsqlRowLevelSecurity;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        if (PgsqlRowLevelSecurity::enabled()) {
            PgsqlRowLevelSecurity::setBypass(true);
        }

        // RBAC v2 (Fase 6): tanto el team_id en memoria de
        // spatie/laravel-permission (DefaultTeamResolver, un singleton que
        // NO se resetea solo entre tests) como su caché de roles/permisos
        // (cache store 'array' en testing, también persiste dentro del
        // mismo proceso de PHPUnit) sobreviven de un test al siguiente si
        // no se limpian explícitamente -- un test que corre DESPUÉS de
        // otro que dejó un team_id o una caché de roles con IDs de una
        // transacción ya revertida por RefreshDatabase puede fallar de
        // forma no determinística según el ORDEN en que corran los tests.
        // Encontrado corriendo la suite completa (pasaba archivo por
        // archivo, fallaba junta) -- no alcanza con resetear esto en cada
        // setUp() de test individual.
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
