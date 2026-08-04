import { ExternalLink, Navigation } from "lucide-react";

const EMBED_KEY = import.meta.env.VITE_GOOGLE_MAPS_EMBED_KEY;

/**
 * Vista de solo lectura de una ubicación ya geocodificada -- mapa embebido
 * (si VITE_GOOGLE_MAPS_EMBED_KEY está configurada) + link "Cómo llegar"
 * que funciona sin ninguna key (deep link público de Google Maps). Para
 * capturar/editar la ubicación, ver AddressMapField.
 */
export function MapPreview({ lat, lng, address, compact = false }) {
    if (lat == null || lng == null) return null;

    const directionsUrl = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;

    return (
        <div className="space-y-2">
            {!compact && EMBED_KEY && (
                <iframe
                    title={address || "Ubicación"}
                    className="h-40 w-full rounded-lg border border-border/50"
                    loading="lazy"
                    src={`https://www.google.com/maps/embed/v1/place?key=${EMBED_KEY}&q=${lat},${lng}`}
                />
            )}
            <a
                href={directionsUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
            >
                <Navigation className="h-3.5 w-3.5" />
                Cómo llegar
                <ExternalLink className="h-3 w-3" />
            </a>
        </div>
    );
}
