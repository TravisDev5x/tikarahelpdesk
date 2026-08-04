import { useState } from "react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ExternalLink, Loader2, MapPin, Search } from "lucide-react";

const EMBED_KEY = import.meta.env.VITE_GOOGLE_MAPS_EMBED_KEY;

function googleMapsSearchUrl(lat, lng) {
    return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
}

/**
 * Dirección + botón "Buscar en el mapa" (POST /api/geocode, server-side --
 * la key de geocodificación nunca llega al navegador) + preview embebido
 * (Maps Embed API, requiere VITE_GOOGLE_MAPS_EMBED_KEY) o, si esa key no
 * está configurada, un link "Ver en Google Maps" que funciona sin ninguna
 * key. Ver docs/GOOGLE_MAPS_SETUP.md.
 */
export function AddressMapField({ label = "Dirección", address, onAddressChange, lat, lng, onLocationChange, disabled }) {
    const [searching, setSearching] = useState(false);

    const search = async () => {
        if (!address?.trim()) return;
        setSearching(true);
        try {
            const { data } = await axios.post("/api/geocode", { address: address.trim() });
            onLocationChange?.({ lat: data.lat, lng: data.lng, formatted_address: data.formatted_address });
            notify.success("Ubicación encontrada");
        } catch (err) {
            if (handleAuthError(err)) return;
            notify.error(getApiErrorMessage(err, "No se pudo ubicar esa dirección"));
        } finally {
            setSearching(false);
        }
    };

    const hasLocation = lat != null && lng != null;

    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <div className="flex gap-2">
                <Input
                    value={address ?? ""}
                    onChange={(e) => onAddressChange?.(e.target.value)}
                    placeholder="Calle, número, colonia, ciudad..."
                    disabled={disabled}
                    onKeyDown={(e) => {
                        if (e.key === "Enter") {
                            e.preventDefault();
                            search();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    className="shrink-0"
                    disabled={disabled || searching || !address?.trim()}
                    onClick={search}
                >
                    {searching ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                        <Search className="h-4 w-4" />
                    )}
                </Button>
            </div>

            {hasLocation && (
                <div className="space-y-2 rounded-lg border border-border/50 bg-muted/20 p-2">
                    {EMBED_KEY ? (
                        <iframe
                            title="Ubicación en el mapa"
                            className="h-48 w-full rounded-md border-0"
                            loading="lazy"
                            src={`https://www.google.com/maps/embed/v1/place?key=${EMBED_KEY}&q=${lat},${lng}`}
                        />
                    ) : null}
                    <a
                        href={googleMapsSearchUrl(lat, lng)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-1.5 text-xs text-primary hover:underline"
                    >
                        <MapPin className="h-3.5 w-3.5" />
                        Ver en Google Maps
                        <ExternalLink className="h-3 w-3" />
                    </a>
                </div>
            )}
        </div>
    );
}
