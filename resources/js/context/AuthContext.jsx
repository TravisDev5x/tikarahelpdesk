import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { router } from "@inertiajs/react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { isGuestOnlyPath, redirectToLanding, redirectToLogin } from "@/lib/authNavigation";

function showSessionFlash(flash) {
    const hasFlash = Object.values(flash ?? {}).some(Boolean);
    if (!hasFlash) return;
    if (flash.success) notify.success(flash.success);
    if (flash.error) notify.error(flash.error);
    if (flash.info) notify.info(flash.info);
    if (flash.warning) notify.warning(flash.warning);
}

const AuthContext = createContext(null);

function mapAuthPayload(payload) {
    if (!payload?.user) return null;
    return {
        ...payload.user,
        roles: payload.roles ?? payload.user.roles ?? [],
        permissions: payload.permissions ?? payload.user.permissions ?? [],
        onboarding_redirect:
            payload.onboarding_redirect ?? payload.user.onboarding_redirect ?? null,
    };
}

function mapInertiaAuthUser(inertiaUser) {
    if (!inertiaUser) return null;
    return {
        ...inertiaUser,
        roles: inertiaUser.roles ?? [],
        permissions: inertiaUser.permissions ?? [],
        onboarding_redirect: inertiaUser.onboarding_redirect ?? null,
    };
}

export const AuthProvider = ({ children, initialAuthUser = null }) => {
    const [user, setUser] = useState(() => mapInertiaAuthUser(initialAuthUser));
    const [loading, setLoading] = useState(() => !initialAuthUser && !isGuestOnlyPath());

    useEffect(() => {
        if (isGuestOnlyPath()) {
            setUser(null);
            setLoading(false);
            return;
        }

        if (initialAuthUser) {
            window.__auth_user_id = initialAuthUser.id;
            setLoading(false);
            return;
        }

        axios
            .get("/check-auth", { withCredentials: true })
            .then((res) => {
                const mapped = mapAuthPayload(res.data);
                if (mapped) {
                    setUser(mapped);
                    window.__auth_user_id = mapped.id;
                    showSessionFlash(res.data.flash);
                } else {
                    setUser(null);
                    delete window.__auth_user_id;
                }
            })
            .catch(() => setUser(null))
            .finally(() => setLoading(false));
    }, [initialAuthUser]);

    useEffect(() => {
        const off = router.on("navigate", (event) => {
            const nextUser = event.detail.page.props?.auth?.user;
            if (nextUser) {
                setUser(mapInertiaAuthUser(nextUser));
                window.__auth_user_id = nextUser.id;
            } else {
                setUser(null);
                delete window.__auth_user_id;
            }
        });
        return () => off();
    }, []);

    useEffect(() => {
        const onNavigateToLogin = () => redirectToLogin();
        window.addEventListener("navigate-to-login", onNavigateToLogin);
        return () => window.removeEventListener("navigate-to-login", onNavigateToLogin);
    }, []);

    const login = useCallback(async (credentials) => {
        await axios.get('/sanctum/csrf-cookie');
        const { data } = await axios.post('/api/login', credentials);
        setUser({
            ...data.user,
            roles: data.roles || [],
            permissions: data.permissions || [],
            onboarding_redirect: data.onboarding_redirect ?? null,
        });
        window.__auth_user_id = data.user?.id;
        window.location.href = data.onboarding_redirect || "/home";
    }, []);

    const logout = useCallback(async () => {
        try {
            await axios.post("/api/logout");
        } catch (error) {
            console.error("Logout error", error);
        } finally {
            setUser(null);
            delete window.__auth_user_id;
            redirectToLanding();
        }
    }, []);

    const updateUserPrefs = useCallback((prefs) => {
        setUser((prev) => (prev ? { ...prev, ...prefs } : prev));
    }, []);

    const refreshUser = useCallback(() => {
        return axios.get("/check-auth", { withCredentials: true }).then((res) => {
            const payload = res.data;
            if (payload?.user) {
                setUser({
                    ...payload.user,
                    roles: payload.roles || [],
                    permissions: payload.permissions || [],
                    onboarding_redirect: payload.onboarding_redirect ?? null,
                });
                showSessionFlash(payload.flash);
            } else {
                setUser(null);
            }
        }).catch(() => setUser(null));
    }, []);

    const updateUserTheme = useCallback((theme) => {
        setUser((prev) => (prev ? { ...prev, theme } : prev));
    }, []);

    const can = useCallback((permission) => {
        if (!permission) return false;
        return Boolean(user?.permissions?.includes(permission));
    }, [user?.permissions]);

    const hasRole = useCallback((role) => {
        if (!role) return false;
        return Boolean(user?.roles?.includes(role));
    }, [user?.roles]);

    const value = useMemo(() => ({
        user,
        loading,
        login,
        logout,
        updateUserTheme,
        updateUserPrefs,
        refreshUser,
        can,
        hasRole,
    }), [user, loading, login, logout, updateUserTheme, updateUserPrefs, refreshUser, can, hasRole]);

    return (
        <AuthContext.Provider value={value}>
            {!loading && children}
        </AuthContext.Provider>
    );
};

export const useAuth = () => {
    const ctx = useContext(AuthContext);
    if (!ctx) {
        throw new Error("useAuth must be used within AuthProvider");
    }
    return ctx;
};


