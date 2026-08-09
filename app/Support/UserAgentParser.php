<?php

namespace App\Support;

/**
 * Nombre corto de navegador desde User-Agent, sin exponer el UA completo.
 * Compartido entre el monitor de sesiones (admin) y "Mis sesiones" (self-service).
 */
class UserAgentParser
{
    public static function browser(?string $ua): string
    {
        if ($ua === null || trim($ua) === '') {
            return '—';
        }
        $ua = trim($ua);

        if (stripos($ua, 'Edg/') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'Chrome') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'Firefox') !== false || stripos($ua, 'FxiOS') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Safari') !== false) {
            return 'Safari';
        }
        if (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR/') !== false) {
            return 'Opera';
        }
        if (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident/') !== false) {
            return 'Internet Explorer';
        }

        return 'Otro';
    }

    public static function isMobile(?string $ua): bool
    {
        return (bool) preg_match('/iphone|android|mobile|ipad/i', (string) $ua);
    }
}
