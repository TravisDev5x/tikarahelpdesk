import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import { ThemeContext } from "@/components/theme-provider";
import {
    applyTheme,
    applyThemeColor,
    readStoredTheme,
    readStoredThemeColor,
    resolveTheme,
    THEME_COLOR_DEFAULT,
    THEME_COLOR_VALUES,
    THEME_VALUES,
    writeStoredTheme,
    writeStoredThemeColor,
} from "@/lib/theme";

/** ThemeContext para páginas Inertia (requiere AuthProvider como padre). */
export function InertiaThemeProvider({ children }) {
    const { user, updateUserTheme, updateUserPrefs } = useAuth();
    const [theme, setThemeState] = useState(readStoredTheme);
    const [themeColor, setThemeColorState] = useState(readStoredThemeColor);
    const mediaRef = useRef(null);
    const initializedRef = useRef(false);

    useEffect(() => {
        const root = document.documentElement;
        if (!initializedRef.current) {
            root.dataset.themeInit = "1";
            initializedRef.current = true;
        }
        applyTheme(theme);
        if (root.dataset.themeInit) {
            window.requestAnimationFrame(() => {
                delete root.dataset.themeInit;
            });
        }
    }, [theme]);

    useEffect(() => {
        applyThemeColor(themeColor);
    }, [themeColor]);

    // Al cargar sesión, la paleta guardada en la cuenta manda sobre la de localStorage.
    useEffect(() => {
        if (user?.theme_color && user.theme_color !== themeColor && THEME_COLOR_VALUES.includes(user.theme_color)) {
            setThemeColorState(user.theme_color);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user?.theme_color]);

    useEffect(() => {
        if (theme !== "system") {
            if (mediaRef.current) {
                const { media, handler } = mediaRef.current;
                media.removeEventListener("change", handler);
                mediaRef.current = null;
            }
            return undefined;
        }

        const media = window.matchMedia("(prefers-color-scheme: dark)");
        const handler = () => applyTheme("system");
        media.addEventListener("change", handler);
        mediaRef.current = { media, handler };

        return () => {
            media.removeEventListener("change", handler);
            mediaRef.current = null;
        };
    }, [theme]);

    const setTheme = useCallback(
        (newTheme, options = { persist: true }) => {
            if (!THEME_VALUES.includes(newTheme)) return;

            writeStoredTheme(newTheme);
            setThemeState(newTheme);

            if (user) {
                updateUserTheme(newTheme);

                if (options.persist !== false) {
                    axios.put("/api/profile/theme", { theme: newTheme }).catch(() => {});
                }
            }
        },
        [user, updateUserTheme]
    );

    const setThemeColor = useCallback(
        (next, options = { persist: true }) => {
            const normalized = THEME_COLOR_VALUES.includes(next) ? next : THEME_COLOR_DEFAULT;

            writeStoredThemeColor(normalized);
            setThemeColorState(normalized);

            // A diferencia de setTheme, no sincroniza user.theme_color en preview
            // (persist:false): Settings.jsx compara pendingThemeColor contra
            // user.theme_color para habilitar "Guardar cambios" -- si se
            // sincronizara aquí, ambos siempre coincidirían y el botón nunca
            // se habilitaría fuera de un guardado real.
            if (user && options.persist !== false) {
                updateUserPrefs({ theme_color: normalized });
                axios.put("/api/profile/preferences", { theme_color: normalized }).catch(() => {});
            }
        },
        [user, updateUserPrefs]
    );

    const resolvedTheme = useMemo(() => resolveTheme(theme), [theme]);

    const value = useMemo(
        () => ({
            theme,
            resolvedTheme,
            setTheme,
            themes: THEME_VALUES,
            isDark: resolvedTheme === "dark",
            themeColor,
            setThemeColor,
            themeColors: THEME_COLOR_VALUES,
        }),
        [theme, resolvedTheme, setTheme, themeColor, setThemeColor]
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
