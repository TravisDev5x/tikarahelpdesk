<?php

namespace App\Services\Integrations;

use Throwable;

class AdConnectionTester
{
    public function test(array $config): ConnectionTestResult
    {
        if (! extension_loaded('ldap')) {
            return ConnectionTestResult::failure(
                'Extensión php-ldap no disponible en este servidor -- pide a soporte que la habilite antes de probar esta conexión.'
            );
        }

        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 389);
        $bindDn = trim((string) ($config['bind_dn'] ?? ''));
        $bindPassword = (string) ($config['bind_password'] ?? '');
        $useSsl = (bool) ($config['use_ssl'] ?? false);

        if ($host === '' || $bindDn === '' || $bindPassword === '') {
            return ConnectionTestResult::failure('Faltan host, bind_dn o bind_password.');
        }

        $uri = ($useSsl ? 'ldaps://' : 'ldap://').$host.':'.$port;

        try {
            $connection = ldap_connect($uri);
            if ($connection === false) {
                return ConnectionTestResult::failure("No se pudo iniciar la conexión a {$uri}.");
            }

            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);

            $bound = @ldap_bind($connection, $bindDn, $bindPassword);
            if (! $bound) {
                $error = ldap_error($connection);
                ldap_unbind($connection);

                return ConnectionTestResult::failure("El servidor rechazó el bind: {$error}.");
            }

            ldap_unbind($connection);

            return ConnectionTestResult::success("Conectado a {$uri}.");
        } catch (Throwable $e) {
            return ConnectionTestResult::failure('No se pudo contactar al servidor AD: '.$e->getMessage());
        }
    }
}
