import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import { ThemeContext } from "@/components/theme-provider";
import {
    applyTheme,
    readStoredTheme,
    resolveTheme,
    THEME_VALUES,
    writeStoredTheme,
} from "@/lib/theme";

/** ThemeContext para páginas Inertia (requiere AuthProvider como padre). */
export function InertiaThemeProvider({ children }) {
    const { user, updateUserTheme } = useAuth();
    const [theme, setThemeState] = useState(readStoredTheme);
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

    const resolvedTheme = useMemo(() => resolveTheme(theme), [theme]);

    const value = useMemo(
        () => ({
            theme,
            resolvedTheme,
            setTheme,
            themes: THEME_VALUES,
            isDark: resolvedTheme === "dark",
        }),
        [theme, resolvedTheme, setTheme]
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}
