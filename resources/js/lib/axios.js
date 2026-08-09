import axios from "axios";
import { isExplicitLogoutInProgress } from "./authNavigation";

// Cliente para obtener la cookie CSRF (misma base que la app para que la cookie se envíe después)
const csrfClient = axios.create({
    baseURL: "/",
    withCredentials: true,
    headers: { Accept: "application/json" },
});
let csrfPromise = null;

const ensureCsrf = () => {
    if (!csrfPromise) {
        csrfPromise = csrfClient.get("/sanctum/csrf-cookie").catch((err) => {
            csrfPromise = null; // reintentar en el siguiente intento
            throw err;
        });
    }
    return csrfPromise;
};

const instance = axios.create({
    baseURL: "/",
    withCredentials: true,
    xsrfCookieName: "XSRF-TOKEN",
    xsrfHeaderName: "X-XSRF-TOKEN",
    headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
    },
});

instance.interceptors.request.use(async (config) => {
    const method = (config.method || "get").toLowerCase();
    if (!["get", "head", "options", "trace"].includes(method)) {
        await ensureCsrf();
    }
    return config;
});

instance.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;
        if (status === 419) {
            csrfPromise = null; // permitir obtener de nuevo la cookie CSRF en el siguiente intento
        }
        if (status === 401 || status === 419) {
            if (isExplicitLogoutInProgress()) {
                // El usuario cerró sesión a propósito -- este 401 es de un
                // request que ya estaba en vuelo, no una sesión que expiró
                // sola. redirectToLanding() (logout()) ya decide a dónde ir;
                // no hay que redirigir a /login ni tratarlo como error real.
                error.duringLogout = true;
            } else {
                window.dispatchEvent(new CustomEvent("navigate-to-login"));
            }
        }
        return Promise.reject(error);
    }
);

export default instance;
