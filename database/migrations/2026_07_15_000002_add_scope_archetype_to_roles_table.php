<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC v2, Paso 1: separa el NOMBRE visible de una plantilla (editable
 * libremente por tenant) del "arquetipo" fijo que gobierna el scoping de
 * tickets. TicketPolicy::siteScopeType() lee esta columna en vez de
 * comparar hasRole('supervisor')/'agente'/'solicitante' -- así una
 * plantilla custom con un nombre distinto sigue funcionando siempre que
 * tenga el scope_archetype correcto.
 *
 * Nullable: es obligatorio para las 4 plantillas de tenant
 * (admin/supervisor/agente/solicitante), pero super_admin (rol de
 * plataforma, no participa del scoping de tickets por site) no tiene uno
 * -- ver auditoría Paso 0.5. La obligatoriedad para las 4 plantillas se
 * valida a nivel de aplicación (RoleTemplateService), no con NOT NULL,
 * porque cualquier rol futuro fuera del RBAC de tenant (como super_admin)
 * necesita poder quedar en NULL legítimamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->enum('scope_archetype', ['admin', 'supervisor', 'agente', 'solicitante'])
                ->nullable()
                ->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('scope_archetype');
        });
    }
};
