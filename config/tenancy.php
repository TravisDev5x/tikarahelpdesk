<?php

return [

    /*
    | Dominio base (ej. tikara.test). Subdominio = clients.portal_slug
    | URL portal: https://{portal_slug}.{base_domain}/login
    */
    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    'portal_scheme' => env('TENANCY_PORTAL_SCHEME', 'http'),

    /*
    | true: en subdominio de cliente solo datos de ese client_id (cero visibilidad entre empresas).
    | false: subdominio solo branding; scopes de permiso como antes.
    */
    'strict_client_portal' => env('TENANCY_STRICT_CLIENT_PORTAL', true),

    /*
    | false (default): catálogos por plataforma + operador MSP, no por client_id.
    | true: en portal estricto cada empresa ve solo filas con su client_id (modo alternativo).
    */
    'catalog_per_client' => env('TENANCY_CATALOG_PER_CLIENT', false),

    /*
    | Requiere subdominio válido (portal_slug) para rutas autenticadas en producción.
    */
    'enforce_subdomain' => env('TENANCY_ENFORCE_SUBDOMAIN', true),

    /*
    | Fuente única: también la usa TenantContextService para resolver el
    | subdominio de un request (rechaza estos como portal_slug real), y
    | TenantOnboardingController para rechazar el nombre de tenant que
    | generaría uno de estos slugs (Fase 7, sub-paso 7.2) -- no duplicar
    | esta lista en ningún otro lugar.
    */
    'reserved_subdomains' => ['www', 'app', 'api', 'admin', 'mail', 'localhost', 'soporte', 'portal'],

    'pgsql_rls_enabled' => env('TENANCY_PGSQL_RLS', false),

    /*
    | true: en consola raíz, usuarios manage_all sin operador MSP vinculado ven todos los clientes.
    | false (prod): exige is_operator o sede/cliente con operator_user_id. Solo habilitar en local/staging legacy.
    */
    'legacy_msp_wide_access' => env('TENANCY_LEGACY_MSP_WIDE_ACCESS', false),

    /*
    | RBAC v2 (spatie/laravel-permission "teams"): team_id de spatie/laravel-permission
    | para usuarios de plataforma (super_admin) que actúan cross-tenant.
    |
    | NO puede ser NULL: model_has_roles.team_id / model_has_permissions.team_id
    | son parte de una PRIMARY KEY compuesta, y ninguna columna de una PK
    | admite NULL en ningún motor (verificado). "0" es un centinela seguro
    | porque los IDs autoincrementales de clients empiezan en 1 -- nunca va
    | a colisionar con un client_id real. Debe usarse SIEMPRE vía esta
    | constante (nunca un 0 literal repetido) en: la fila del rol
    | super_admin, cada asignación (assignRole) y cada request donde el
    | usuario actuante sea OperatorScopeService::bypassesOperatorScope() --
    | ver App\Http\Middleware\ApplyPgsqlTenantRls.
    */
    'super_admin_team_id' => env('TENANCY_SUPER_ADMIN_TEAM_ID', 0),

];
