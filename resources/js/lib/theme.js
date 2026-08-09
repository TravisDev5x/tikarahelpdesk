/** Preferencia de tema: light | dark | system (sigue OS). */
export const THEME_STORAGE_KEY = "tikara_theme";
export const THEME_LEGACY_KEY = "theme";
export const THEME_VALUES = ["light", "dark", "system"];

/** Paleta de acento estilo shadcn (ui.shadcn.com/themes), por usuario. */
export const THEME_COLOR_STORAGE_KEY = "tikara_theme_color";
export const THEME_COLOR_VALUES = ["zinc", "rose", "blue", "green", "violet", "orange"];
export const THEME_COLOR_DEFAULT = "zinc";

export function getSystemTheme() {
    if (typeof window === "undefined") return "light";
    return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

export function normalizeTheme(value) {
    if (THEME_VALUES.includes(value)) return value;
    if (typeof value === "string" && value.includes("dark")) return "dark";
    if (typeof value === "string" && value.includes("light")) return "light";
    return "system";
}

export function resolveTheme(theme) {
    const normalized = normalizeTheme(theme);
    if (normalized === "system") return getSystemTheme();
    return normalized;
}

export function readStoredTheme() {
    if (typeof window === "undefined") return "system";
    try {
        const stored =
            localStorage.getItem(THEME_STORAGE_KEY) ?? localStorage.getItem(THEME_LEGACY_KEY);
        if (stored && THEME_VALUES.includes(stored)) {
            if (localStorage.getItem(THEME_LEGACY_KEY) && !localStorage.getItem(THEME_STORAGE_KEY)) {
                localStorage.setItem(THEME_STORAGE_KEY, stored);
                localStorage.removeItem(THEME_LEGACY_KEY);
            }
            return stored;
        }
    } catch {
        // ignore
    }
    return "system";
}

export function writeStoredTheme(theme) {
    if (!THEME_VALUES.includes(theme)) return;
    try {
        localStorage.setItem(THEME_STORAGE_KEY, theme);
        localStorage.removeItem(THEME_LEGACY_KEY);
    } catch {
        // ignore
    }
}

export function readStoredThemeColor() {
    if (typeof window === "undefined") return THEME_COLOR_DEFAULT;
    try {
        const stored = localStorage.getItem(THEME_COLOR_STORAGE_KEY);
        if (stored && THEME_COLOR_VALUES.includes(stored)) return stored;
    } catch {
        // ignore
    }
    return THEME_COLOR_DEFAULT;
}

export function writeStoredThemeColor(color) {
    if (!THEME_COLOR_VALUES.includes(color)) return;
    try {
        localStorage.setItem(THEME_COLOR_STORAGE_KEY, color);
    } catch {
        // ignore
    }
}

/** Solo cambia una variable de dataset -- sin re-render de árbol, evita el costo que tenían los temas anteriores. */
export function applyThemeColor(color, { switching = true } = {}) {
    if (typeof document === "undefined") return;
    const root = document.documentElement;
    const normalized = THEME_COLOR_VALUES.includes(color) ? color : THEME_COLOR_DEFAULT;

    if (switching) root.dataset.themeSwitching = "1";

    if (normalized === THEME_COLOR_DEFAULT) {
        delete root.dataset.themeColor;
    } else {
        root.dataset.themeColor = normalized;
    }

    if (switching) {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                delete root.dataset.themeSwitching;
            });
        });
    }
}

/** Aplica clase .dark/.light y color-scheme en <html>. */
export function applyTheme(theme, { switching = true } = {}) {
    if (typeof document === "undefined") return resolveTheme(theme);

    const resolved = resolveTheme(theme);
    const root = document.documentElement;

    if (switching) {
        root.dataset.themeSwitching = "1";
    }

    root.classList.remove("light", "dark");
    if (resolved === "dark") {
        root.classList.add("dark");
        root.style.colorScheme = "dark";
    } else {
        root.classList.add("light");
        root.style.colorScheme = "light";
    }

    if (switching) {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                delete root.dataset.themeSwitching;
            });
        });
    }

    return resolved;
}
