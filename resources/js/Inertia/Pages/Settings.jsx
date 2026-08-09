import { useEffect, useMemo, useState } from "react";
import { Head } from "@inertiajs/react";
import axios from "@/lib/axios";
import { useTheme, DEFAULT_PREFS } from "@/hooks/useTheme";
import { ThemeToggle } from "@/components/ThemeToggle";
import { useAuth } from "@/context/AuthContext";
import { useSidebarPosition } from "@/context/SidebarPositionContext";
import { notify } from "@/lib/notify";
import { hintWarning } from "@/lib/badgeStyles";
import { useI18n } from "@/hooks/useI18n";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Separator } from "@/components/ui/separator";
import {
    Palette,
    Languages,
    LayoutDashboard,
    KeyRound,
    Save,
    RotateCcw,
    BellRing,
    Monitor,
    RefreshCw,
    UserCircle,
    CheckCircle2,
} from "lucide-react";

const OPTIONS_LOCALE = [
    { value: "es", label: "Español", flag: "🇪🇸" },
    { value: "en", label: "English", flag: "🇺🇸" },
    { value: "ja", label: "日本語", flag: "🇯🇵" },
    { value: "de", label: "Deutsch", flag: "🇩🇪" },
    { value: "zh", label: "中文", flag: "🇨🇳" },
    { value: "fr", label: "Français", flag: "🇫🇷" },
];

export default function Settings() {
    const { theme, setTheme, density, setDensity, locale, setLocale, themeColor, setThemeColor, themeColors } = useTheme();
    const { user, updateUserPrefs, can } = useAuth();
    const canManagePasswordResets = can('notifications.manage');
    const { position: sidebarPosition, setPosition: setSidebarPosition } = useSidebarPosition();
    const { t } = useI18n();

    const [pendingTheme, setPendingTheme] = useState(() => theme || DEFAULT_PREFS.theme);
    const [pendingThemeColor, setPendingThemeColor] = useState(() => themeColor || "zinc");
    const [pendingDensity, setPendingDensity] = useState(() => density || DEFAULT_PREFS.ui_density);
    const [pendingLocale, setPendingLocale] = useState(() => locale || DEFAULT_PREFS.locale);
    const [sidebarState, setSidebarState] = useState(() => user?.sidebar_state ?? DEFAULT_PREFS.sidebar_state);
    const [hoverPreview, setHoverPreview] = useState(
        () => user?.sidebar_hover_preview ?? DEFAULT_PREFS.sidebar_hover_preview
    );
    const [saving, setSaving] = useState(false);
    const [notifications, setNotifications] = useState([]);
    const [loadingNotif, setLoadingNotif] = useState(false);
    const [tempPass, setTempPass] = useState("");
    const [tempPassConfirm, setTempPassConfirm] = useState("");
    const [tempUserId, setTempUserId] = useState("");
    const [tempComment, setTempComment] = useState("");
    const [communicationMethod, setCommunicationMethod] = useState("");

    useEffect(() => {
        setPendingTheme(theme || DEFAULT_PREFS.theme);
    }, [theme]);
    useEffect(() => {
        setPendingThemeColor(themeColor || "zinc");
    }, [themeColor]);
    useEffect(() => {
        setPendingDensity(density || DEFAULT_PREFS.ui_density);
    }, [density]);
    useEffect(() => {
        setPendingLocale(locale || DEFAULT_PREFS.locale);
    }, [locale]);

    useEffect(() => {
        if (typeof user?.sidebar_state !== "undefined") {
            setSidebarState(user.sidebar_state || DEFAULT_PREFS.sidebar_state);
        }
        if (typeof user?.sidebar_hover_preview !== "undefined") {
            setHoverPreview(user.sidebar_hover_preview);
        }
    }, [user?.sidebar_state, user?.sidebar_hover_preview]);

    const isDirty = useMemo(() => {
        const baseSidebarState = user?.sidebar_state ?? DEFAULT_PREFS.sidebar_state;
        const baseHover =
            typeof user?.sidebar_hover_preview !== "undefined"
                ? user.sidebar_hover_preview
                : DEFAULT_PREFS.sidebar_hover_preview;
        const baseSidebarPosition = user?.sidebar_position ?? "left";
        const baseLocale = user?.locale ?? DEFAULT_PREFS.locale;
        const baseThemeColor = user?.theme_color ?? "zinc";
        return (
            pendingTheme !== theme ||
            pendingThemeColor !== baseThemeColor ||
            pendingDensity !== density ||
            pendingLocale !== baseLocale ||
            sidebarState !== baseSidebarState ||
            hoverPreview !== baseHover ||
            sidebarPosition !== baseSidebarPosition
        );
    }, [
        pendingTheme,
        theme,
        pendingThemeColor,
        pendingDensity,
        density,
        pendingLocale,
        locale,
        sidebarState,
        hoverPreview,
        sidebarPosition,
        user?.sidebar_state,
        user?.sidebar_hover_preview,
        user?.sidebar_position,
        user?.locale,
        user?.theme_color,
    ]);

    const saveAll = async () => {
        if (!isDirty) return;
        setSaving(true);
        try {
            const payload = {
                theme: pendingTheme,
                theme_color: pendingThemeColor,
                ui_density: pendingDensity,
                sidebar_state: sidebarState,
                sidebar_hover_preview: sidebarState === "collapsed" ? hoverPreview : false,
                sidebar_position: sidebarPosition,
                locale: pendingLocale,
            };
            await axios.put("/api/profile/preferences", { ...payload });
            setTheme(pendingTheme, { persist: false });
            setThemeColor(pendingThemeColor, { persist: false });
            setDensity(pendingDensity, { persist: false });
            setLocale(pendingLocale, { persist: false });
            updateUserPrefs({ ...payload });
            localStorage.setItem("sidebar-collapsed", sidebarState === "collapsed" ? "1" : "0");
            notify.success(t("settings.toast.saved"));
        } catch {
            notify.error(t("settings.toast.failed"));
        } finally {
            setSaving(false);
        }
    };

    const loadNotifications = async () => {
        setLoadingNotif(true);
        try {
            const res = await axios.get("/api/admin/notifications");
            setNotifications(res.data.notifications || []);
        } catch {
            notify.error("No se pudieron cargar las notificaciones");
        } finally {
            setLoadingNotif(false);
        }
    };

    const resolveNotification = async (notif) => {
        if (!tempUserId || !tempPass || !tempPassConfirm) {
            notify.error("ID de usuario, contraseña y confirmación son obligatorios.");
            return;
        }
        if (tempPass !== tempPassConfirm) {
            notify.error("La contraseña y la confirmación no coinciden.");
            return;
        }
        if (!communicationMethod) {
            notify.error("Indica el medio por el que comunicarás la contraseña al empleado.");
            return;
        }
        try {
            await axios.post(`/api/admin/notifications/${notif.id}/resolve-password`, {
                user_id: Number(tempUserId),
                password: tempPass,
                password_confirmation: tempPassConfirm,
                communication_method: communicationMethod,
                comment: tempComment || undefined,
            });
            await axios.post(`/api/admin/notifications/${notif.id}/read`);
            setTempPass("");
            setTempPassConfirm("");
            setTempUserId("");
            setTempComment("");
            setCommunicationMethod("");
            loadNotifications();
            notify.success(
                "Contraseña restablecida. Comunica la nueva contraseña al empleado por el medio indicado."
            );
        } catch (e) {
            notify.error(e?.response?.data?.message || "Error al resolver");
        }
    };

    useEffect(() => {
        if (canManagePasswordResets) loadNotifications();
    }, [canManagePasswordResets]);

    const resetDefaults = () => {
        setPendingTheme(DEFAULT_PREFS.theme);
        setPendingThemeColor("zinc");
        setPendingDensity(DEFAULT_PREFS.ui_density);
        setPendingLocale(DEFAULT_PREFS.locale);
        setSidebarState(DEFAULT_PREFS.sidebar_state);
        setHoverPreview(DEFAULT_PREFS.sidebar_hover_preview);
        setSidebarPosition("left");
        try {
            localStorage.removeItem("sidebar-collapsed");
        } catch {
            // ignore
        }
    };

    if (!user) return null;

    return (
        <AuthenticatedLayout title={t("settings.title")}>
            <Head title={t("settings.title")} />

            <div className="max-w-4xl mx-auto space-y-10 animate-in fade-in slide-in-from-bottom-4 duration-500 pb-content-mobile">
                <header className="flex flex-col md:flex-row justify-between items-start md:items-end gap-4 border-b pb-6 border-border/40">
                    <p className="text-muted-foreground italic">{t("settings.subtitle")}</p>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={resetDefaults}
                            disabled={saving}
                            className="h-9"
                        >
                            <RotateCcw className="mr-2 h-4 w-4" />
                            {t("settings.actions.reset")}
                        </Button>
                        <Button
                            onClick={saveAll}
                            size="sm"
                            disabled={!isDirty || saving}
                            className={`h-9 shadow-lg transition-all ${isDirty ? "bg-primary" : ""}`}
                        >
                            {saving ? (
                                <RefreshCw className="h-4 w-4 animate-spin mr-2" />
                            ) : (
                                <Save className="mr-2 h-4 w-4" />
                            )}
                            {t("settings.actions.save")}
                        </Button>
                    </div>
                </header>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="space-y-6">
                        <Card className="overflow-hidden border-border/50 shadow-sm">
                            <CardHeader className="bg-muted/30 pb-4">
                                <div className="flex items-center gap-2">
                                    <Palette className="h-5 w-5 text-primary" />
                                    <CardTitle className="text-lg">{t("settings.theme.title")}</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-6 space-y-5">
                                <ThemeToggle
                                    variant="select"
                                    value={pendingTheme}
                                    onValueChange={(v) => {
                                        setPendingTheme(v);
                                        setTheme(v, { persist: false });
                                    }}
                                />
                                <div className="space-y-2">
                                    <p className="text-sm font-semibold">{t("settings.themeColor.title")}</p>
                                    <div className="flex flex-wrap gap-2">
                                        {themeColors.map((c) => (
                                            <button
                                                key={c}
                                                type="button"
                                                onClick={() => {
                                                    setPendingThemeColor(c);
                                                    setThemeColor(c, { persist: false });
                                                }}
                                                title={t(`settings.themeColor.${c}`)}
                                                aria-pressed={pendingThemeColor === c}
                                                className={`h-9 w-9 rounded-full border-2 transition-all flex items-center justify-center ${
                                                    pendingThemeColor === c
                                                        ? "border-foreground scale-110 shadow-sm"
                                                        : "border-transparent hover:scale-105"
                                                }`}
                                                style={{ backgroundColor: `hsl(var(--theme-color-swatch-${c}))` }}
                                            >
                                                {pendingThemeColor === c && (
                                                    <CheckCircle2 className="h-4 w-4 text-white drop-shadow" />
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="overflow-hidden border-border/50 shadow-sm">
                            <CardHeader className="bg-muted/30 pb-4">
                                <div className="flex items-center gap-2">
                                    <Languages className="h-5 w-5 text-primary" />
                                    <CardTitle className="text-lg">{t("settings.locale.title")}</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-6">
                                <Select value={pendingLocale} onValueChange={setPendingLocale}>
                                    <SelectTrigger className="w-full h-12 text-left">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {OPTIONS_LOCALE.map((opt) => (
                                            <SelectItem
                                                key={opt.value}
                                                value={opt.value}
                                                className="cursor-pointer"
                                            >
                                                <span className="mr-2 text-lg">{opt.flag}</span>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <Card className="overflow-hidden border-border/50 shadow-sm">
                            <CardHeader className="bg-muted/30 pb-4">
                                <div className="flex items-center gap-2">
                                    <Monitor className="h-5 w-5 text-primary" />
                                    <CardTitle className="text-lg">{t("settings.density.title")}</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-6">
                                <div className="grid grid-cols-2 gap-2 p-1 bg-muted/50 rounded-lg">
                                    {["normal", "compact"].map((d) => (
                                        <Button
                                            key={d}
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setPendingDensity(d)}
                                            className={`h-auto py-2 px-4 rounded-md text-sm font-medium transition-all ${
                                                pendingDensity === d
                                                    ? "bg-background shadow-sm text-primary"
                                                    : "text-muted-foreground hover:bg-background/40 hover:text-muted-foreground"
                                            }`}
                                        >
                                            {t(`density.${d}`)}
                                        </Button>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card className="overflow-hidden border-border/50 shadow-sm">
                            <CardHeader className="bg-muted/30 pb-4">
                                <div className="flex items-center gap-2">
                                    <LayoutDashboard className="h-5 w-5 text-primary" />
                                    <CardTitle className="text-lg">{t("settings.sidebar.title")}</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-6 space-y-6">
                                <div className="flex gap-2">
                                    {["expanded", "collapsed"].map((state) => (
                                        <Button
                                            key={state}
                                            variant={sidebarState === state ? "default" : "outline"}
                                            onClick={() => setSidebarState(state)}
                                            className="flex-1 h-11"
                                        >
                                            {state === "expanded" ? "Expandida" : "Colapsada"}
                                        </Button>
                                    ))}
                                </div>
                                <div className="space-y-2">
                                    <p className="text-sm font-semibold">{t("settings.sidebar.position")}</p>
                                    <div className="flex gap-2">
                                        {["left", "right"].map((pos) => (
                                            <Button
                                                key={pos}
                                                variant={sidebarPosition === pos ? "default" : "outline"}
                                                onClick={() => setSidebarPosition(pos)}
                                                className="flex-1 h-11"
                                            >
                                                {pos === "left"
                                                    ? t("settings.sidebar.positionLeft")
                                                    : t("settings.sidebar.positionRight")}
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                                <Separator className="bg-border/40" />
                                <div className="flex items-center justify-between p-4 bg-muted/20 rounded-xl border border-border/40">
                                    <div className="space-y-0.5 text-left">
                                        <p className="text-sm font-semibold">{t("settings.sidebar.hover.title")}</p>
                                        <p className="text-[11px] text-muted-foreground leading-tight max-w-[180px]">
                                            {t("settings.sidebar.hover.desc")}
                                        </p>
                                    </div>
                                    <Switch
                                        checked={hoverPreview}
                                        onCheckedChange={setHoverPreview}
                                        disabled={sidebarState !== "collapsed"}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {canManagePasswordResets && (
                <Card className="overflow-hidden border-primary/20 bg-primary/[0.01] shadow-xl">
                        <CardHeader className="bg-primary/5 pb-6 border-b border-primary/10">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <div className="bg-primary/10 p-2 rounded-full">
                                        <KeyRound className="h-5 w-5 text-primary" />
                                    </div>
                                    <div className="text-left">
                                        <CardTitle className="text-xl">Gestión de Restablecimientos</CardTitle>
                                        <CardDescription>
                                            Restablece contraseñas y comunica la nueva clave al empleado por
                                            WhatsApp empresarial, teléfono, personal o personalmente.
                                        </CardDescription>
                                    </div>
                                </div>
                                <Badge
                                    variant={notifications.length > 0 ? "destructive" : "secondary"}
                                    className={notifications.length > 0 ? "animate-pulse" : ""}
                                >
                                    {notifications.length} Solicitudes
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-8 space-y-8">
                            <p className="text-sm text-muted-foreground px-1">
                                Tras restablecer la contraseña, deberás comunicarla al empleado por el medio
                                seleccionado (WhatsApp empresarial, teléfono empresarial, personal si está
                                permitido o personalmente).
                            </p>
                            <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-5 gap-4 p-5 bg-card border rounded-2xl shadow-inner group">
                                <div className="space-y-2 text-left">
                                    <label className="text-[10px] uppercase font-bold text-muted-foreground ml-1 italic">
                                        ID Usuario
                                    </label>
                                    <Input
                                        placeholder="Ej: 450"
                                        value={tempUserId}
                                        onChange={(e) => setTempUserId(e.target.value)}
                                        className="bg-muted/30 h-11"
                                    />
                                </div>
                                <div className="space-y-2 text-left">
                                    <label className="text-[10px] uppercase font-bold text-muted-foreground ml-1 italic">
                                        Nueva contraseña
                                    </label>
                                    <Input
                                        placeholder="••••••••"
                                        type="password"
                                        value={tempPass}
                                        onChange={(e) => setTempPass(e.target.value)}
                                        className="bg-muted/30 h-11"
                                    />
                                </div>
                                <div className="space-y-2 text-left">
                                    <label className="text-[10px] uppercase font-bold text-muted-foreground ml-1 italic">
                                        Confirmar contraseña
                                    </label>
                                    <Input
                                        placeholder="••••••••"
                                        type="password"
                                        value={tempPassConfirm}
                                        onChange={(e) => setTempPassConfirm(e.target.value)}
                                        className="bg-muted/30 h-11"
                                    />
                                </div>
                                <div className="space-y-2 text-left">
                                    <label className="text-[10px] uppercase font-bold text-muted-foreground ml-1 italic">
                                        Medio de comunicación al empleado
                                    </label>
                                    <Select value={communicationMethod} onValueChange={setCommunicationMethod}>
                                        <SelectTrigger className="h-11 bg-muted/30">
                                            <SelectValue placeholder="Selecciona..." />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="whatsapp_empresarial">
                                                WhatsApp empresarial
                                            </SelectItem>
                                            <SelectItem value="telefono_empresarial">
                                                Teléfono empresarial
                                            </SelectItem>
                                            <SelectItem value="personal">
                                                Personal (si está permitido)
                                            </SelectItem>
                                            <SelectItem value="personalmente">Personalmente</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2 text-left">
                                    <label className="text-[10px] uppercase font-bold text-muted-foreground ml-1 italic">
                                        Comentario (opcional)
                                    </label>
                                    <Input
                                        placeholder="Ej: Llamada 10:00"
                                        value={tempComment}
                                        onChange={(e) => setTempComment(e.target.value)}
                                        className="bg-muted/30 h-11"
                                    />
                                </div>
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    variant="outline"
                                    onClick={loadNotifications}
                                    disabled={loadingNotif}
                                    className="h-11 flex items-center gap-2 hover:bg-primary hover:text-white transition-all"
                                >
                                    <RefreshCw className={`h-4 w-4 ${loadingNotif ? "animate-spin" : ""}`} />
                                    Refrescar solicitudes
                                </Button>
                            </div>

                            <div className="space-y-4">
                                {loadingNotif ? (
                                    <div className="flex flex-col items-center py-10 space-y-3">
                                        <RefreshCw className="h-8 w-8 animate-spin text-primary/30" />
                                        <p className="text-sm text-muted-foreground italic">
                                            Sincronizando solicitudes...
                                        </p>
                                    </div>
                                ) : notifications.length === 0 ? (
                                    <div className="flex flex-col items-center py-12 border-2 border-dashed rounded-3xl opacity-60">
                                        <CheckCircle2 className="h-10 w-10 text-emerald-700 dark:text-emerald-400 mb-2" />
                                        <p className="text-sm font-medium italic">
                                            Todo al día. No hay solicitudes pendientes.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-1 gap-3">
                                        {notifications.map((n) => {
                                            const payload = n.payload || {};
                                            const suggestedUser = payload.user_id || payload.userId || "";
                                            return (
                                                <div
                                                    key={n.id}
                                                    className="group flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 bg-card border border-border/60 rounded-2xl hover:border-primary/40 hover:shadow-md transition-all gap-4"
                                                >
                                                    <div className="flex items-start gap-4 text-left">
                                                        <div className="mt-1 h-10 w-10 rounded-full bg-muted flex items-center justify-center text-primary">
                                                            <UserCircle className="h-6 w-6" />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <p className="text-sm font-bold flex items-center gap-2">
                                                                {payload.user_name || "Usuario Desconocido"}
                                                                {payload.user_employee_number && (
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className="text-[10px] h-4"
                                                                    >
                                                                        {payload.user_employee_number}
                                                                    </Badge>
                                                                )}
                                                            </p>
                                                            <div className="grid grid-cols-1 gap-0.5 text-[11px] text-muted-foreground italic">
                                                                <span className="flex items-center gap-1 opacity-80">
                                                                    <BellRing className="h-3 w-3" />
                                                                    {n.type === "password_reset_request"
                                                                        ? "Solicitud de restablecimiento (sin correo o por número de empleado)"
                                                                        : n.type === "password_reset_missing_email"
                                                                          ? "Restablecimiento solicitado (correo no encontrado)"
                                                                          : n.type === "account_deletion_request"
                                                                            ? "Solicitud de eliminación de cuenta"
                                                                            : n.type}
                                                                </span>
                                                                {payload.user_email && (
                                                                    <span className="underline decoration-primary/30">
                                                                        {payload.user_email}
                                                                    </span>
                                                                )}
                                                                {!payload.user_email && payload.requested_email && (
                                                                    <span className={hintWarning}>
                                                                        Solicitado: {payload.requested_email}
                                                                    </span>
                                                                )}
                                                                {n.type === "account_deletion_request" && payload.reason && (
                                                                    <span className="not-italic text-foreground/80">
                                                                        Motivo: {payload.reason}
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {suggestedUser && (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setTempUserId(String(suggestedUser))
                                                                    }
                                                                    className="h-auto px-2 py-0.5 text-[10px] text-primary hover:underline font-semibold bg-primary/5 hover:bg-primary/10 rounded-full mt-1"
                                                                >
                                                                    Auto-completar ID: {suggestedUser}
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex gap-2 w-full sm:w-auto">
                                                        {n.type !== "account_deletion_request" && (
                                                            <Button
                                                                size="sm"
                                                                onClick={() => resolveNotification(n)}
                                                                className="flex-1 sm:flex-none h-10 rounded-xl bg-primary/10 text-primary hover:bg-primary hover:text-white border-none transition-all"
                                                            >
                                                                Resolver
                                                            </Button>
                                                        )}
                                                        {!n.read_at && (
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    axios
                                                                        .post(
                                                                            `/api/admin/notifications/${n.id}/read`
                                                                        )
                                                                        .then(loadNotifications)
                                                                }
                                                                className="h-10 w-10 rounded-xl hover:bg-destructive/10 hover:text-destructive"
                                                            >
                                                                <CheckCircle2 className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
