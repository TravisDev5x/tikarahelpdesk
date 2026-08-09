import { useCallback, useEffect, useState } from "react";
import axios from "@/lib/axios";
import { useAuth } from "@/context/AuthContext";
import { useThemeContext } from "@/components/theme-provider";

const STORAGE_LOCALE = "locale";

export const DEFAULT_PREFS = {
    theme: "system",
    ui_density: "normal",
    sidebar_state: "collapsed",
    sidebar_hover_preview: true,
    locale: "es",
};

const setRootLocale = (locale) => {
    if (typeof document !== "undefined") {
        document.documentElement.lang = locale || DEFAULT_PREFS.locale;
    }
};

/**
 * Preferencias de UI (densidad, locale). El tema se controla solo vía ThemeContext.
 * No sincroniza theme desde user en cada render (evita revertir cambios locales).
 */
export function useTheme() {
    const ctx = useThemeContext();
    const { user, updateUserPrefs } = useAuth();

    const [density, setDensityState] = useState(() => {
        if (typeof window === "undefined") return DEFAULT_PREFS.ui_density;
        return user?.ui_density || localStorage.getItem("ui_density") || DEFAULT_PREFS.ui_density;
    });
    const [locale, setLocaleState] = useState(() => {
        if (typeof window === "undefined") return DEFAULT_PREFS.locale;
        return user?.locale || localStorage.getItem(STORAGE_LOCALE) || DEFAULT_PREFS.locale;
    });

    const setTheme = useCallback(
        (next, opts = { persist: true }) => {
            ctx.setTheme(next, { persist: opts.persist !== false });
        },
        [ctx]
    );

    const setThemeColor = useCallback(
        (next, opts = { persist: true }) => {
            ctx.setThemeColor(next, { persist: opts.persist !== false });
        },
        [ctx]
    );

    useEffect(() => {
        if (user?.ui_density && user.ui_density !== density) {
            setDensityState(user.ui_density);
        }
        document.documentElement.dataset.uiDensity = density;
    }, [user?.ui_density, density]);

    useEffect(() => {
        if (user?.locale && user.locale !== locale) {
            setLocaleState(user.locale);
            setRootLocale(user.locale);
        } else {
            setRootLocale(locale);
        }
    }, [user?.locale, locale]);

    const persistPreferences = useCallback(
        async (payload) => {
            if (!user) return;
            try {
                await axios.put("/api/profile/preferences", payload);
                updateUserPrefs(payload);
            } catch {
                // ignore
            }
        },
        [user, updateUserPrefs]
    );

    const applyDensity = useCallback(
        (next, opts = { persist: true }) => {
            if (!["normal", "compact"].includes(next)) return;
            setDensityState(next);
            localStorage.setItem("ui_density", next);
            document.documentElement.dataset.uiDensity = next;
            if (user && opts.persist !== false) {
                persistPreferences({ ui_density: next });
            }
        },
        [user, persistPreferences]
    );

    const applyLocale = useCallback(
        (next, opts = { persist: true }) => {
            const allowed = ["es", "en", "ja", "de", "zh", "fr"];
            if (!allowed.includes(next)) return;
            setLocaleState(next);
            setRootLocale(next);
            localStorage.setItem(STORAGE_LOCALE, next);
            if (user && opts.persist !== false) {
                persistPreferences({ locale: next });
            }
        },
        [user, persistPreferences]
    );

    const toggleTheme = useCallback(() => {
        const order = ctx.themes ?? ["light", "dark", "system"];
        const idx = order.indexOf(ctx.theme);
        const next = order[(idx + 1) % order.length];
        setTheme(next);
    }, [ctx.theme, ctx.themes, setTheme]);

    return {
        theme: ctx.theme,
        setTheme,
        resolvedTheme: ctx.resolvedTheme,
        isDark: ctx.isDark,
        toggleTheme,
        cycleLight: () => setTheme("light"),
        cycleDark: () => setTheme("dark"),
        density,
        setDensity: (next, opts) => applyDensity(next, opts),
        locale,
        setLocale: (next, opts) => applyLocale(next, opts),
        themes: ctx.themes ?? ["light", "dark", "system"],
        themeColor: ctx.themeColor,
        setThemeColor,
        themeColors: ctx.themeColors ?? ["zinc", "rose", "blue", "green", "violet", "orange"],
        defaults: DEFAULT_PREFS,
    };
}

export default useTheme;
