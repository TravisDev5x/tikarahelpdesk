/**
 * Gráfica decorativa compartida de landing/auth (rediseño 2026-08-11):
 * cintas retorcidas con gradiente de marca, inspirada en el estilo de la
 * imagen de referencia del usuario -- interpretación abstracta propia, no
 * una copia pixel-exacta. Usa hsl(var(--brand)) / hsl(var(--brand-muted))
 * directo en los stops del gradiente, así que sigue el tema claro/oscuro
 * (y cualquier futuro cambio de marca) sin lógica JS, solo CSS.
 *
 * Puramente decorativa -- aria-hidden, sin contenido interactivo.
 */
export function AbstractRibbons({ className = "", gradientId = "abstractRibbons" }) {
    const gradA = `${gradientId}-a`;
    const gradB = `${gradientId}-b`;
    const hatchId = `${gradientId}-hatch`;

    return (
        <svg
            viewBox="0 0 560 560"
            fill="none"
            className={className}
            aria-hidden="true"
            focusable="false"
        >
            <defs>
                <linearGradient id={gradA} x1="120" y1="60" x2="440" y2="480" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stopColor="hsl(var(--brand))" />
                    <stop offset="100%" stopColor="hsl(var(--brand-muted))" />
                </linearGradient>
                <linearGradient id={gradB} x1="160" y1="40" x2="480" y2="440" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stopColor="hsl(var(--brand-muted))" />
                    <stop offset="100%" stopColor="hsl(var(--brand))" />
                </linearGradient>
                <pattern id={hatchId} width="10" height="10" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">
                    <line x1="0" y1="0" x2="0" y2="10" stroke="hsl(var(--brand))" strokeWidth="1.4" opacity="0.55" />
                </pattern>
            </defs>

            {/* Franja diagonal con textura de líneas (esquina superior derecha) */}
            <rect x="330" y="10" width="220" height="150" fill={`url(#${hatchId})`} opacity="0.35" transform="rotate(28 440 85)" />

            {/* Cintas retorcidas -- 5 tiras diagonales paralelas, cada una con un
                "doblez" (curva) cerca de la punta superior, como en la referencia. */}
            <path
                d="M96 120 C60 150 60 190 96 214 L360 460 C382 480 412 478 428 456 C444 434 438 406 416 388 L140 150 C120 132 112 116 96 120 Z"
                fill={`url(#${gradA})`}
                opacity="0.95"
            />
            <path
                d="M146 96 C110 126 110 166 146 190 L410 436 C432 456 462 454 478 432 C494 410 488 382 466 364 L190 126 C170 108 162 92 146 96 Z"
                fill={`url(#${gradB})`}
                opacity="0.9"
            />
            <path
                d="M196 76 C160 106 160 146 196 170 L448 404 C470 424 500 422 516 400 C532 378 526 350 504 332 L240 106 C220 88 212 72 196 76 Z"
                fill={`url(#${gradA})`}
                opacity="0.85"
            />
            <path
                d="M244 58 C208 88 208 128 244 152 L478 368 C500 388 530 386 546 364 C558 346 554 320 534 302 L288 88 C268 70 260 54 244 58 Z"
                fill={`url(#${gradB})`}
                opacity="0.75"
            />
            <path
                d="M288 46 C256 74 256 110 288 132 L470 300 C490 318 518 316 532 296 C544 278 540 254 522 238 L328 76 C310 60 302 42 288 46 Z"
                fill={`url(#${gradA})`}
                opacity="0.6"
            />

            {/* Acentos: círculos sólidos de distintos tamaños/opacidades */}
            <circle cx="120" cy="80" r="17" fill="hsl(var(--brand))" opacity="0.9" />
            <circle cx="70" cy="130" r="8" fill="hsl(var(--brand-muted))" opacity="0.75" />
            <circle cx="500" cy="470" r="12" fill="hsl(var(--brand-muted))" opacity="0.5" />
            <circle cx="60" cy="330" r="6" fill="hsl(var(--brand))" opacity="0.6" />
        </svg>
    );
}
