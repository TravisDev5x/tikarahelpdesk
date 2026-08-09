import { useCallback, useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import { formatDistanceToNow } from "date-fns";
import { es } from "date-fns/locale";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import { Skeleton } from "@/components/ui/skeleton";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { GoogleIcon } from "@/components/auth/AuthGoogleSection";
import { MicrosoftIcon } from "@/components/auth/AuthMicrosoftSection";
import { notify } from "@/lib/notify";
import { getApiErrorMessage } from "@/lib/apiErrors";
import { strongPasswordSchema } from "@/lib/passwordSchema";
import {
    AlertTriangle,
    Camera,
    CircleDot,
    History,
    KeyRound,
    Laptop,
    LogOut,
    Loader2,
    Save,
    ShieldAlert,
    Smartphone,
    Trash2,
    UserCog,
} from "lucide-react";

function initials(name) {
    const parts = String(name || "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (!parts.length) return "??";
    return (parts[0][0] + (parts[1]?.[0] || "")).toUpperCase();
}

function avatarSrc(user, preview) {
    if (preview) return preview;
    if (user?.avatar_url) return user.avatar_url;
    if (user?.avatar_path) {
        return `/storage/${String(user.avatar_path).replace(/^\/+/, "")}`;
    }
    return null;
}

function validatePassword(form) {
    const errors = {};
    if (!form.current_password) errors.current_password = "Requerido";
    const passResult = strongPasswordSchema.safeParse(form.password);
    if (!passResult.success) {
        errors.password = passResult.error.errors[0]?.message ?? "Contraseña inválida";
    }
    if (form.password !== form.password_confirmation) {
        errors.password_confirmation = "Las contraseñas no coinciden";
    }
    return errors;
}

function formatLastActivity(unixSeconds) {
    if (!unixSeconds) return "—";
    try {
        return formatDistanceToNow(new Date(unixSeconds * 1000), { addSuffix: true, locale: es });
    } catch {
        return "—";
    }
}

function formatEventDate(iso) {
    if (!iso) return "—";
    try {
        return formatDistanceToNow(new Date(iso), { addSuffix: true, locale: es });
    } catch {
        return "—";
    }
}

const LOGIN_METHOD_LABEL = {
    password: "Contraseña",
    google: "Google",
    microsoft: "Microsoft",
};

export default function Profile() {
    const { user: pageUser, auth, authProviders = {} } = usePage().props;
    const user = pageUser ?? auth?.user;

    const fileInputRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [avatarError, setAvatarError] = useState(false);
    const [profileLoading, setProfileLoading] = useState(false);
    const [passwordLoading, setPasswordLoading] = useState(false);

    const [profileForm, setProfileForm] = useState({
        first_name: user?.first_name ?? "",
        paternal_last_name: user?.paternal_last_name ?? "",
        maternal_last_name: user?.maternal_last_name ?? "",
        email: user?.email ?? "",
        phone: user?.phone ?? "",
        availability: user?.availability ?? "disconnected",
    });
    const [passwordForm, setPasswordForm] = useState({
        current_password: "",
        password: "",
        password_confirmation: "",
    });
    const [passwordErrors, setPasswordErrors] = useState({});

    // Sesiones activas (self-service: solo las del usuario actual)
    const [sessions, setSessions] = useState([]);
    const [sessionsLoading, setSessionsLoading] = useState(true);
    const [revokingId, setRevokingId] = useState(null);
    const [revokingOthers, setRevokingOthers] = useState(false);

    // Actividad reciente (últimos accesos: contraseña / Google / Microsoft)
    const [loginHistory, setLoginHistory] = useState([]);
    const [historyLoading, setHistoryLoading] = useState(true);

    // Cuentas conectadas
    const [unlinkingProvider, setUnlinkingProvider] = useState(null);

    // Zona de peligro
    const [deletionReason, setDeletionReason] = useState("");
    const [requestingDeletion, setRequestingDeletion] = useState(false);

    useEffect(() => {
        if (!user) return;
        setProfileForm({
            first_name: user.first_name ?? "",
            paternal_last_name: user.paternal_last_name ?? "",
            maternal_last_name: user.maternal_last_name ?? "",
            email: user.email ?? "",
            phone: user.phone ?? "",
            availability: user.availability ?? "disconnected",
        });
    }, [user]);

    useEffect(() => {
        return () => {
            if (preview) URL.revokeObjectURL(preview);
        };
    }, [preview]);

    const loadSessions = useCallback(async () => {
        setSessionsLoading(true);
        try {
            const { data } = await axios.get("/api/profile/sessions");
            setSessions(data.sessions ?? []);
        } catch {
            setSessions([]);
        } finally {
            setSessionsLoading(false);
        }
    }, []);

    const loadLoginHistory = useCallback(async () => {
        setHistoryLoading(true);
        try {
            const { data } = await axios.get("/api/profile/login-history");
            setLoginHistory(data.history ?? []);
        } catch {
            setLoginHistory([]);
        } finally {
            setHistoryLoading(false);
        }
    }, []);

    useEffect(() => {
        loadSessions();
        loadLoginHistory();
    }, [loadSessions, loadLoginHistory]);

    const handleFileChange = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            notify.error("La imagen es demasiado pesada. Máximo 5 MB.");
            e.target.value = "";
            return;
        }
        setPreview(URL.createObjectURL(file));
        setAvatarError(false);
    };

    const saveProfile = async (e) => {
        e.preventDefault();
        if (!profileForm.first_name.trim() || !profileForm.paternal_last_name.trim()) {
            notify.error("Nombre y apellido paterno son requeridos");
            return;
        }
        setProfileLoading(true);
        try {
            const formData = new FormData();
            formData.append("first_name", profileForm.first_name.trim());
            formData.append("paternal_last_name", profileForm.paternal_last_name.trim());
            formData.append("maternal_last_name", profileForm.maternal_last_name?.trim() ?? "");
            formData.append("phone", profileForm.phone?.trim() ?? "");
            formData.append("email", profileForm.email?.trim() ?? "");
            formData.append("availability", profileForm.availability ?? "disconnected");
            if (fileInputRef.current?.files?.[0]) {
                formData.append("avatar", fileInputRef.current.files[0]);
            }
            await axios.post("/api/profile", formData);
            notify.success("Perfil actualizado correctamente");
            setPreview(null);
            if (fileInputRef.current) fileInputRef.current.value = "";
            router.reload({ only: ["user"] });
        } catch (err) {
            notify.error(getApiErrorMessage(err, "Error al actualizar perfil"));
        } finally {
            setProfileLoading(false);
        }
    };

    const savePassword = async (e) => {
        e.preventDefault();
        const errors = validatePassword(passwordForm);
        setPasswordErrors(errors);
        if (Object.keys(errors).length) return;

        setPasswordLoading(true);
        try {
            await axios.put("/api/profile/password", passwordForm);
            notify.success("Contraseña actualizada");
            setPasswordForm({
                current_password: "",
                password: "",
                password_confirmation: "",
            });
            setPasswordErrors({});
        } catch (err) {
            const apiErrors = err.response?.data?.errors;
            if (apiErrors) {
                const mapped = {};
                Object.entries(apiErrors).forEach(([k, v]) => {
                    mapped[k] = Array.isArray(v) ? v[0] : v;
                });
                setPasswordErrors(mapped);
            } else {
                notify.error(getApiErrorMessage(err, "Error al cambiar contraseña"));
            }
        } finally {
            setPasswordLoading(false);
        }
    };

    const revokeSession = async (id) => {
        setRevokingId(id);
        try {
            await axios.delete(`/api/profile/sessions/${id}`);
            setSessions((prev) => prev.filter((s) => s.id !== id));
            notify.success("Sesión cerrada");
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudo cerrar la sesión"));
        } finally {
            setRevokingId(null);
        }
    };

    const revokeOtherSessions = async () => {
        setRevokingOthers(true);
        try {
            await axios.post("/api/profile/sessions/revoke-others");
            notify.success("Se cerraron las demás sesiones");
            await loadSessions();
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudieron cerrar las sesiones"));
        } finally {
            setRevokingOthers(false);
        }
    };

    const unlinkConnection = async (provider) => {
        setUnlinkingProvider(provider);
        try {
            await axios.delete(`/api/profile/connections/${provider}`);
            notify.success("Cuenta desvinculada");
            router.reload({ only: ["user"] });
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudo desvincular la cuenta"));
        } finally {
            setUnlinkingProvider(null);
        }
    };

    const requestAccountDeletion = async () => {
        setRequestingDeletion(true);
        try {
            await axios.post("/api/profile/request-deletion", { reason: deletionReason.trim() || undefined });
            notify.success("Tu solicitud fue enviada. Un administrador la revisará.");
            setDeletionReason("");
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudo enviar la solicitud"));
        } finally {
            setRequestingDeletion(false);
        }
    };

    const displayName =
        [user?.first_name, user?.paternal_last_name].filter(Boolean).join(" ") ||
        user?.name ||
        "Usuario";

    const connections = [
        {
            key: "google",
            label: "Google",
            icon: <GoogleIcon />,
            linked: Boolean(user?.google_linked),
            enabled: Boolean(authProviders?.google),
            connectHref: "/auth/google/link",
        },
        {
            key: "microsoft",
            label: "Microsoft",
            icon: <MicrosoftIcon />,
            linked: Boolean(user?.microsoft_linked),
            enabled: Boolean(authProviders?.microsoft),
            connectHref: "/auth/microsoft/link",
        },
    ];

    return (
        <div className="w-full space-y-6 animate-in fade-in pb-content-mobile">
            <div className="grid gap-6 lg:grid-cols-[380px_1fr] items-start">
                {/* Columna izquierda: identidad */}
                <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm lg:sticky lg:top-4">
                    <CardHeader>
                        <CardTitle className="uppercase font-bold text-sm flex items-center gap-2">
                            <UserCog className="h-4 w-4" /> Información personal
                        </CardTitle>
                        <CardDescription>Actualiza tu foto y datos de contacto.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={saveProfile} className="space-y-6">
                            <div className="flex flex-col items-center gap-4 py-4">
                                <div
                                    className="relative group cursor-pointer"
                                    onClick={() => fileInputRef.current?.click()}
                                    onKeyDown={(e) => {
                                        if (e.key === "Enter" || e.key === " ") {
                                            fileInputRef.current?.click();
                                        }
                                    }}
                                    role="button"
                                    tabIndex={0}
                                >
                                    <Avatar className="h-24 w-24 border-2 border-primary/20 group-hover:border-primary transition-colors">
                                        {avatarSrc(user, preview) && !avatarError && (
                                            <AvatarImage
                                                src={avatarSrc(user, preview)}
                                                alt={displayName}
                                                className="object-cover"
                                                onError={() => setAvatarError(true)}
                                            />
                                        )}
                                        <AvatarFallback className="text-2xl font-black bg-muted">
                                            {initials(displayName)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="absolute inset-0 bg-black/40 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                        <Camera className="text-white h-6 w-6" />
                                    </div>
                                </div>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={handleFileChange}
                                />
                                <p className="text-[10px] uppercase font-bold text-muted-foreground">
                                    Click para cambiar foto
                                </p>
                            </div>

                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="first_name" className="text-[10px] font-black uppercase">
                                        Nombre(s)
                                    </Label>
                                    <Input
                                        id="first_name"
                                        className="bg-muted/20"
                                        value={profileForm.first_name}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({ ...p, first_name: e.target.value }))
                                        }
                                        required
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="paternal_last_name"
                                        className="text-[10px] font-black uppercase"
                                    >
                                        Apellido paterno
                                    </Label>
                                    <Input
                                        id="paternal_last_name"
                                        className="bg-muted/20"
                                        value={profileForm.paternal_last_name}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({
                                                ...p,
                                                paternal_last_name: e.target.value,
                                            }))
                                        }
                                        required
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="maternal_last_name"
                                        className="text-[10px] font-black uppercase"
                                    >
                                        Apellido materno (opcional)
                                    </Label>
                                    <Input
                                        id="maternal_last_name"
                                        className="bg-muted/20"
                                        value={profileForm.maternal_last_name}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({
                                                ...p,
                                                maternal_last_name: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="email" className="text-[10px] font-black uppercase">
                                        Correo electrónico
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        className="bg-muted/20"
                                        value={profileForm.email}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({ ...p, email: e.target.value }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="phone" className="text-[10px] font-black uppercase">
                                        Teléfono (opcional)
                                    </Label>
                                    <Input
                                        id="phone"
                                        className="bg-muted/20"
                                        value={profileForm.phone}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({ ...p, phone: e.target.value }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="flex items-center gap-1.5 text-[10px] font-black uppercase">
                                        <CircleDot className="h-3.5 w-3.5" />
                                        Disponibilidad
                                    </Label>
                                    <Select
                                        value={profileForm.availability}
                                        onValueChange={(v) =>
                                            setProfileForm((p) => ({ ...p, availability: v }))
                                        }
                                    >
                                        <SelectTrigger className="bg-muted/20">
                                            <SelectValue placeholder="Seleccionar estado" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="available">Disponible</SelectItem>
                                            <SelectItem value="busy">Ocupado</SelectItem>
                                            <SelectItem value="disconnected">Desconectado</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                disabled={profileLoading}
                                className="w-full font-black uppercase tracking-widest text-xs"
                            >
                                {profileLoading && (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                )}
                                <Save className="mr-2 h-4 w-4" />
                                Guardar cambios
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Columna derecha: seguridad y gobernanza de cuenta */}
                <div className="space-y-6">
                    <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm">
                        <CardHeader>
                            <CardTitle className="uppercase font-bold text-sm flex items-center gap-2">
                                <KeyRound className="h-4 w-4" /> Seguridad
                            </CardTitle>
                            <CardDescription>Cambiar contraseña</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={savePassword} className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="current_password"
                                        className="text-[10px] font-black uppercase"
                                    >
                                        Contraseña actual
                                    </Label>
                                    <Input
                                        id="current_password"
                                        type="password"
                                        className="bg-muted/20"
                                        value={passwordForm.current_password}
                                        onChange={(e) =>
                                            setPasswordForm((p) => ({
                                                ...p,
                                                current_password: e.target.value,
                                            }))
                                        }
                                    />
                                    {passwordErrors.current_password && (
                                        <p className="text-xs text-destructive">
                                            {passwordErrors.current_password}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="password" className="text-[10px] font-black uppercase">
                                        Nueva contraseña
                                    </Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        className="bg-muted/20"
                                        value={passwordForm.password}
                                        onChange={(e) =>
                                            setPasswordForm((p) => ({ ...p, password: e.target.value }))
                                        }
                                    />
                                    {passwordErrors.password && (
                                        <p className="text-xs text-destructive">{passwordErrors.password}</p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="password_confirmation"
                                        className="text-[10px] font-black uppercase"
                                    >
                                        Confirmar nueva contraseña
                                    </Label>
                                    <Input
                                        id="password_confirmation"
                                        type="password"
                                        className="bg-muted/20"
                                        value={passwordForm.password_confirmation}
                                        onChange={(e) =>
                                            setPasswordForm((p) => ({
                                                ...p,
                                                password_confirmation: e.target.value,
                                            }))
                                        }
                                    />
                                    {passwordErrors.password_confirmation && (
                                        <p className="text-xs text-destructive">
                                            {passwordErrors.password_confirmation}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={passwordLoading}
                                    className="font-black uppercase tracking-widest text-xs sm:col-span-3 sm:w-fit sm:justify-self-end"
                                >
                                    {passwordLoading && (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    )}
                                    Actualizar contraseña
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div>
                                <CardTitle className="uppercase font-bold text-sm flex items-center gap-2">
                                    <Laptop className="h-4 w-4" /> Sesiones activas
                                </CardTitle>
                                <CardDescription>Dispositivos con tu sesión abierta.</CardDescription>
                            </div>
                            {sessions.length > 1 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={revokeOtherSessions}
                                    disabled={revokingOthers}
                                >
                                    {revokingOthers ? (
                                        <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" />
                                    ) : (
                                        <LogOut className="h-3.5 w-3.5 mr-1.5" />
                                    )}
                                    Cerrar las demás
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            {sessionsLoading ? (
                                <div className="space-y-2">
                                    {[1, 2].map((i) => (
                                        <Skeleton key={i} className="h-14 w-full rounded-md" />
                                    ))}
                                </div>
                            ) : sessions.length === 0 ? (
                                <p className="text-sm text-muted-foreground py-4 text-center">
                                    No se pudieron determinar tus sesiones activas.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border/60">
                                    {sessions.map((s) => {
                                        const DeviceIcon = s.is_mobile ? Smartphone : Laptop;
                                        return (
                                            <li key={s.id} className="flex items-center justify-between gap-3 py-3">
                                                <div className="flex items-center gap-3 min-w-0">
                                                    <div className="h-9 w-9 rounded-full bg-muted flex items-center justify-center shrink-0">
                                                        <DeviceIcon className="h-4 w-4 text-muted-foreground" />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <span className="text-sm font-medium flex items-center gap-2">
                                                            {s.browser}
                                                            {s.is_current && (
                                                                <Badge variant="secondary" className="text-[10px]">
                                                                    Sesión actual
                                                                </Badge>
                                                            )}
                                                        </span>
                                                        <p className="text-xs text-muted-foreground truncate">
                                                            {s.ip_address || "IP desconocida"} ·{" "}
                                                            {formatLastActivity(s.last_activity)}
                                                        </p>
                                                    </div>
                                                </div>
                                                {!s.is_current && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => revokeSession(s.id)}
                                                        disabled={revokingId === s.id}
                                                        className="text-muted-foreground hover:text-destructive"
                                                    >
                                                        {revokingId === s.id ? (
                                                            <Loader2 className="h-4 w-4 animate-spin" />
                                                        ) : (
                                                            <LogOut className="h-4 w-4" />
                                                        )}
                                                    </Button>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm">
                        <CardHeader>
                            <CardTitle className="uppercase font-bold text-sm flex items-center gap-2">
                                <ShieldAlert className="h-4 w-4" /> Cuentas conectadas
                            </CardTitle>
                            <CardDescription>Inicia sesión más rápido vinculando tu correo.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {connections.map((p) => (
                                <div
                                    key={p.key}
                                    className="flex items-center justify-between gap-3 p-3 rounded-lg border border-border/50 bg-muted/10"
                                >
                                    <div className="flex items-center gap-3 min-w-0">
                                        <div className="h-9 w-9 rounded-full bg-background border border-border/50 flex items-center justify-center shrink-0">
                                            {p.icon}
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium">{p.label}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {p.linked
                                                    ? "Conectada"
                                                    : p.enabled
                                                      ? "No conectada"
                                                      : "No configurado por el administrador"}
                                            </p>
                                        </div>
                                    </div>
                                    {p.linked ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => unlinkConnection(p.key)}
                                            disabled={unlinkingProvider === p.key}
                                        >
                                            {unlinkingProvider === p.key && (
                                                <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" />
                                            )}
                                            Desvincular
                                        </Button>
                                    ) : p.enabled ? (
                                        <Button type="button" variant="outline" size="sm" asChild>
                                            <a href={p.connectHref}>Conectar</a>
                                        </Button>
                                    ) : (
                                        <Button type="button" variant="outline" size="sm" disabled>
                                            Conectar
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm">
                        <CardHeader>
                            <CardTitle className="uppercase font-bold text-sm flex items-center gap-2">
                                <History className="h-4 w-4" /> Actividad reciente
                            </CardTitle>
                            <CardDescription>Tus últimos inicios de sesión.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {historyLoading ? (
                                <div className="space-y-2">
                                    {[1, 2, 3].map((i) => (
                                        <Skeleton key={i} className="h-9 w-full rounded-md" />
                                    ))}
                                </div>
                            ) : loginHistory.length === 0 ? (
                                <p className="text-sm text-muted-foreground py-4 text-center">
                                    Aún no hay actividad registrada.
                                </p>
                            ) : (
                                <ul className="divide-y divide-border/60">
                                    {loginHistory.map((h, i) => (
                                        <li key={i} className="flex items-center justify-between gap-2 py-2.5 text-sm">
                                            <span className="flex items-center gap-2 min-w-0">
                                                <Badge variant="outline" className="text-[10px] shrink-0">
                                                    {LOGIN_METHOD_LABEL[h.method] ?? h.method}
                                                </Badge>
                                                <span className="text-muted-foreground truncate">
                                                    {h.ip_address || "IP desconocida"}
                                                </span>
                                            </span>
                                            <span className="text-xs text-muted-foreground shrink-0">
                                                {formatEventDate(h.created_at)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="border-destructive/30 bg-destructive/[0.02] shadow-sm">
                        <CardHeader>
                            <CardTitle className="uppercase font-bold text-sm text-destructive flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4" /> Zona de peligro
                            </CardTitle>
                            <CardDescription>Acciones irreversibles sobre tu cuenta.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <p className="text-sm font-medium">Eliminar mi cuenta</p>
                                    <p className="text-xs text-muted-foreground max-w-md">
                                        Se envía una solicitud a un administrador para revisarla y ejecutarla. No borra tus datos de inmediato.
                                    </p>
                                </div>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button type="button" variant="destructive" size="sm">
                                            <Trash2 className="h-4 w-4 mr-2" /> Solicitar eliminación
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>¿Solicitar eliminación de tu cuenta?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Un administrador revisará la solicitud antes de ejecutarla. Puedes indicar el motivo (opcional).
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <Textarea
                                            value={deletionReason}
                                            onChange={(e) => setDeletionReason(e.target.value)}
                                            placeholder="Motivo (opcional)"
                                            className="mt-1"
                                        />
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={requestAccountDeletion}
                                                disabled={requestingDeletion}
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                            >
                                                {requestingDeletion && (
                                                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                                                )}
                                                Enviar solicitud
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}

Profile.layout = (page) => <AuthenticatedLayout title="Mi perfil">{page}</AuthenticatedLayout>;
