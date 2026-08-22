import { KeyRound, Laptop, Network } from "lucide-react";

/**
 * Fase 4.1 de la auditoría -- integraciones opcionales por tenant. Única
 * fuente de verdad de qué campos pintar por proveedor (mismo espíritu que
 * AssetSpecSchema en el backend) para no duplicar el shape en cada card.
 */
export const INTEGRATION_PROVIDERS = [
    {
        key: "entra_id",
        label: "Microsoft Entra ID",
        icon: KeyRound,
        description: "Autenticación de la organización vía Microsoft Graph (client credentials).",
        fields: [
            { key: "tenant_id", label: "Tenant ID", type: "text", placeholder: "00000000-0000-0000-0000-000000000000" },
            { key: "client_id", label: "Client ID (app registrada)", type: "text", placeholder: "00000000-0000-0000-0000-000000000000" },
            { key: "client_secret", label: "Client secret", type: "password", secret: true },
        ],
    },
    {
        key: "intune",
        label: "Microsoft Intune",
        icon: Laptop,
        description: "Requiere una app registrada en Azure con permiso DeviceManagementManagedDevices.Read.All.",
        fields: [
            { key: "tenant_id", label: "Tenant ID", type: "text", placeholder: "00000000-0000-0000-0000-000000000000" },
            { key: "client_id", label: "Client ID (app registrada)", type: "text", placeholder: "00000000-0000-0000-0000-000000000000" },
            { key: "client_secret", label: "Client secret", type: "password", secret: true },
        ],
    },
    {
        key: "ad",
        label: "Active Directory (LDAP)",
        icon: Network,
        description: "Conexión LDAP a un directorio on-premise. Requiere red accesible desde el servidor.",
        fields: [
            { key: "host", label: "Host", type: "text", placeholder: "dc01.miempresa.local" },
            { key: "port", label: "Puerto", type: "text", placeholder: "389" },
            { key: "base_dn", label: "Base DN", type: "text", placeholder: "DC=miempresa,DC=local" },
            { key: "bind_dn", label: "Bind DN", type: "text", placeholder: "CN=svc-tikara,OU=Service Accounts,DC=miempresa,DC=local" },
            { key: "bind_password", label: "Bind password", type: "password", secret: true },
            { key: "use_ssl", label: "Usar LDAPS (SSL)", type: "checkbox" },
        ],
    },
];
