import { useCallback, useEffect, useRef, useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import axios from "@/lib/axios";
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    LogOut,
    Maximize2,
    Menu,
    Minimize2,
    ShieldCheck,
    Smartphone,
    Square,
    SquareDashed,
    X,
} from "lucide-react";
import { useAuth } from "@/context/AuthContext";
import { useSidebarPosition } from "@/context/SidebarPositionContext";
import { useI18n } from "@/hooks/useI18n";
import { useSwipeToClose } from "@/hooks/useSwipeToClose";
import { cn } from "@/lib/utils";
import { noticeWarningBanner, noticeWarningBtnOutline } from "@/lib/badgeStyles";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Sheet, SheetClose, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { Sidebar } from "@/components/Sidebar";
import { ThemeToggle } from "@/components/ThemeToggle";
import { NotificationBell } from "@/components/NotificationBell";
import { MobileBottomBar } from "@/components/MobileBottomBar";
import { TenantBrandMark } from "@/components/TenantBrand";
import {
    isClientPortalTenant,
    resolveTenantBrandCssVars,
} from "@/lib/tenantBranding";

const TITLE_MAP = {
    "/": "Inicio",
    "/home": "Inicio",
    "/calendar": "Calendario",
    "/profile": "Mi perfil",
    "/company": "Mi empresa",
    "/company/edit": "Editar empresa",
    "/clients": "Clientes",
    "/clients/create": "Nuevo cliente",
    "/onboarding": "Configuración inicial",
    "/resolbeb": "Dashboard operativo",
    "/resolbeb/tickets": "Tickets",
    "/resolbeb/mis-tickets": "Mis tickets",
    "/resolbeb/tickets/new": "Nuevo ticket",
    "/users": "Usuarios",
    "/users/invitations": "Invitaciones",
    "/settings": "Configuración",
    "/audit-command": "Auditoría",
    "/sessions": "Sesiones activas",
    "/roles": "Roles",
    "/permissions": "Permisos",
};

function getPageTitle(pathname, titleProp) {
    if (titleProp) return titleProp;
    if (TITLE_MAP[pathname]) return TITLE_MAP[pathname];
    if (/^\/clients\/\d+\/edit/.test(pathname)) return "Editar cliente";
    if (/^\/clients\/\d+/.test(pathname)) return "Detalle cliente";
    if (/^\/resolbeb\/tickets\/\d+/.test(pathname)) return "Detalle ticket";
    if (/^\/users\/\d+/.test(pathname)) return "Detalle usuario";
    return "Panel";
}

export default function AuthenticatedLayout({ children, title: titleProp }) {
    const { url, props: pageProps } = usePage();
    const { t } = useI18n();
    const { user, logout, refreshUser } = useAuth();
    const { position: sidebarPosition } = useSidebarPosition();
    const currentPath = url.split("?")[0];

    const initialNotifications =
        pageProps.notifications != null ? pageProps.notifications : [];
    const initialUnreadCount =
        pageProps.unread_notifications_count != null
            ? pageProps.unread_notifications_count
            : 0;
    const inertiaUser = pageProps.auth?.user;
    const tenant = pageProps.tenant ?? {};
    const isPortalBrand = isClientPortalTenant(tenant);
    const tenantBrandStyle = resolveTenantBrandCssVars(tenant);

    const [collapsed, setCollapsed] = useState(() => {
        if (typeof window === "undefined") return false;
        const local = localStorage.getItem("sidebar-collapsed");
        if (local !== null) return local === "1";
        if (inertiaUser?.sidebar_state !== undefined && inertiaUser?.sidebar_state !== null) {
            return inertiaUser.sidebar_state === "collapsed";
        }
        return false;
    });
    const [focused, setFocused] = useState(() => {
        if (typeof window === "undefined") return false;
        return localStorage.getItem("layout-focused") === "1";
    });
    const [fullscreen, setFullscreen] = useState(() => {
        if (typeof document === "undefined") return false;
        return !!document.fullscreenElement;
    });
    const [forceDeviceView, setForceDeviceView] = useState(() => {
        if (typeof window === "undefined") return false;
        return localStorage.getItem("layout-force-device-view") === "1";
    });
    const [hoverPreviewEnabled, setHoverPreviewEnabled] = useState(
        () => inertiaUser?.sidebar_hover_preview ?? user?.sidebar_hover_preview ?? true
    );
    const hoverTempExpandRef = useRef(false);
    const isHydrated = useRef(false);
    const prevCollapsed = useRef(null);
    const prevHoverPreview = useRef(null);

    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [mobileMenuMounted, setMobileMenuMounted] = useState(false);
    const [notifUnreadCount, setNotifUnreadCount] = useState(initialUnreadCount);
    const mobileSheetRef = useRef(null);
    const mainScrollRef = useRef(null);
    const [scrollFadeOpacity, setScrollFadeOpacity] = useState(0);

    const handleMobileMenuOpenChange = useCallback((open) => {
        setMobileMenuOpen(open);
        if (open) setMobileMenuMounted(true);
    }, []);

    useSwipeToClose(mobileSheetRef, () => handleMobileMenuOpenChange(false), mobileMenuOpen, {
        side: "left",
        threshold: 72,
        velocityThreshold: 0.35,
    });

    const mustChangePassword = Boolean(user?.force_password_change);
    const pendingAdmin =
        user?.status === "pending_admin" &&
        !(user?.roles?.length === 1 && user?.roles?.[0] === "visitante");

    const handleSidebarNavigate = useCallback(() => {
        hoverTempExpandRef.current = false;
        handleMobileMenuOpenChange(false);
        if (!focused) setCollapsed(false);
    }, [focused, handleMobileMenuOpenChange]);

    const handleSidebarToggle = useCallback(() => {
        hoverTempExpandRef.current = false;
        setCollapsed((v) => !v);
    }, []);

    useEffect(() => {
        if (!tenantBrandStyle?.["--brand"]) {
            return undefined;
        }

        const root = document.documentElement;
        const previous = root.style.getPropertyValue("--brand");
        root.style.setProperty("--brand", tenantBrandStyle["--brand"]);

        return () => {
            if (previous) {
                root.style.setProperty("--brand", previous);
            } else {
                root.style.removeProperty("--brand");
            }
        };
    }, [tenantBrandStyle]);

    useEffect(() => {
        if (localStorage.getItem("sidebar-collapsed") !== null) return;
        const source = inertiaUser ?? user;
        if (typeof source?.sidebar_state !== "undefined") {
            setCollapsed(source.sidebar_state === "collapsed");
        }
    }, [user?.sidebar_state, inertiaUser?.sidebar_state]);

    useEffect(() => {
        const source = pageProps.auth?.user ?? user;
        if (typeof source?.sidebar_hover_preview !== "undefined") {
            setHoverPreviewEnabled(Boolean(source.sidebar_hover_preview));
        }
    }, [pageProps.auth?.user?.sidebar_hover_preview, user?.sidebar_hover_preview]);

    useEffect(() => {
        if (typeof window !== "undefined") {
            localStorage.setItem("sidebar-collapsed", collapsed ? "1" : "0");
            localStorage.setItem("layout-focused", focused ? "1" : "0");
            localStorage.setItem("layout-force-device-view", forceDeviceView ? "1" : "0");
        }
    }, [collapsed, focused, forceDeviceView]);

    useEffect(() => {
        if (!user || isHydrated.current) return;

        const id = window.setTimeout(() => {
            if (isHydrated.current) return;
            prevCollapsed.current = collapsed;
            prevHoverPreview.current = hoverPreviewEnabled;
            isHydrated.current = true;
        }, 0);

        return () => window.clearTimeout(id);
    }, [user, collapsed, hoverPreviewEnabled]);

    useEffect(() => {
        if (!isHydrated.current) return;
        if (!user) return;
        if (hoverTempExpandRef.current) return;

        const collapsedChanged =
            prevCollapsed.current !== null &&
            prevCollapsed.current !== collapsed;
        const hoverChanged =
            prevHoverPreview.current !== null &&
            prevHoverPreview.current !== hoverPreviewEnabled;

        if (!collapsedChanged && !hoverChanged) return;

        prevCollapsed.current = collapsed;
        prevHoverPreview.current = hoverPreviewEnabled;

        axios
            .put("/api/profile/sidebar", {
                sidebar_state: collapsed ? "collapsed" : "expanded",
                sidebar_hover_preview: hoverPreviewEnabled,
            })
            .catch(() => {});
    }, [collapsed, hoverPreviewEnabled, user]);

    useEffect(() => {
        const el = mainScrollRef.current;
        if (!el) return;
        const onScroll = () => {
            const top = el.scrollTop;
            setScrollFadeOpacity(top <= 0 ? 0 : Math.min(1, top / 32));
        };
        onScroll();
        el.addEventListener("scroll", onScroll, { passive: true });
        return () => el.removeEventListener("scroll", onScroll);
    }, [currentPath]);

    useEffect(() => {
        handleMobileMenuOpenChange(false);
    }, [currentPath, handleMobileMenuOpenChange]);

    useEffect(() => {
        if (!pendingAdmin) return;
        const id = setInterval(refreshUser, 25000);
        return () => clearInterval(id);
    }, [pendingAdmin, refreshUser]);

    useEffect(() => {
        if (!user) return;
        const id = setInterval(() => axios.get("/api/ping").catch(() => {}), 2 * 60 * 1000);
        return () => clearInterval(id);
    }, [user]);

    const toggleFullscreen = async () => {
        try {
            if (!document.fullscreenElement) {
                await document.documentElement.requestFullscreen();
                setFullscreen(true);
            } else {
                await document.exitFullscreen();
                setFullscreen(false);
            }
        } catch {
            // ignore
        }
    };

    const pageTitle = getPageTitle(currentPath, titleProp);
    const sidebarCollapsed = collapsed || focused;

    // El Toaster (sileo) se monta en la raíz de la app (inertia.jsx), fuera
    // de este layout -- "top-center" centra sobre TODO el viewport, no
    // sobre el área de contenido, así que queda corrido hacia el sidebar
    // (72px colapsado / 256px expandido; 0 en mobile, donde el <aside> está
    // oculto). Exponemos el ancho real como variable CSS en <html> para que
    // el toast pueda compensarlo -- ver la regla de app.css.
    useEffect(() => {
        const width = forceDeviceView ? 0 : (sidebarCollapsed ? 72 : 256);
        document.documentElement.style.setProperty("--app-sidebar-width", `${width}px`);
        document.documentElement.dataset.sidebarPosition = sidebarPosition;
    }, [sidebarCollapsed, forceDeviceView, sidebarPosition]);

    return (
        <TooltipProvider>
            <div
                className={cn(
                    "flex h-screen w-full overflow-hidden bg-background text-foreground font-sans selection:bg-primary/20",
                    sidebarPosition === "right" && "flex-row-reverse"
                )}
                data-sidebar-position={sidebarPosition}
            >
                {pendingAdmin && (
                    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-background/70 backdrop-blur-md">
                        <div className="mx-4 max-w-md rounded-2xl border border-border/60 bg-card/95 p-8 shadow-xl backdrop-blur-sm text-center">
                            <div className="mb-4 flex justify-center">
                                <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                                    <ShieldCheck className="h-7 w-7 text-primary" />
                                </div>
                            </div>
                            <h2 className="text-xl font-bold tracking-tight text-foreground mb-2">Bienvenido</h2>
                            <p className="text-sm text-muted-foreground leading-relaxed">
                                Un administrador asignará un rol a tu cuenta y enseguida podrás acceder por completo.
                            </p>
                            <Button variant="outline" size="sm" onClick={logout} className="mt-6 gap-2">
                                <LogOut className="h-4 w-4" />
                                Cerrar sesión
                            </Button>
                        </div>
                    </div>
                )}

                <aside
                    className={cn(
                        "flex-col z-40 overflow-hidden flex-shrink-0",
                        forceDeviceView ? "hidden" : "hidden md:flex",
                        "will-change-[width]",
                        sidebarCollapsed ? "w-[72px]" : "w-64"
                    )}
                    style={{ transition: "width 350ms cubic-bezier(0.32, 0.72, 0, 1)" }}
                    onMouseEnter={() => {
                        if (hoverPreviewEnabled && collapsed && !focused) {
                            hoverTempExpandRef.current = true;
                            setCollapsed(false);
                        }
                    }}
                    onMouseLeave={() => {
                        if (hoverPreviewEnabled && hoverTempExpandRef.current) {
                            setCollapsed(true);
                            hoverTempExpandRef.current = false;
                        }
                    }}
                >
                    <Sidebar
                        collapsed={sidebarCollapsed}
                        onToggle={handleSidebarToggle}
                        onNavigate={handleSidebarNavigate}
                        currentPath={currentPath}
                    />
                </aside>

                <div className="flex flex-1 flex-col min-w-0 relative transition-[margin] duration-350 ease-[cubic-bezier(0.32,0.72,0,1)]">
                    {mustChangePassword && (
                        <div className={noticeWarningBanner}>
                            <AlertTriangle className="h-4 w-4" />
                            <span className="font-semibold">Seguridad: Cambio de contraseña requerido.</span>
                            <Button variant="outline" size="sm" asChild className={cn("h-7 text-xs", noticeWarningBtnOutline)}>
                                <Link href="/force-change-password">Cambiar</Link>
                            </Button>
                        </div>
                    )}

                    <header
                        className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-x-4 bg-background/80 px-4 safe-area-header backdrop-blur-xl transition-all md:px-6"
                        data-sidebar-position={sidebarPosition}
                    >
                        <div className={cn("order-0", forceDeviceView ? "flex" : "md:hidden")}>
                            <Sheet open={mobileMenuOpen} onOpenChange={handleMobileMenuOpenChange}>
                                <SheetTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="-ml-2 h-11 w-11 text-muted-foreground md:h-9 md:w-9"
                                        aria-label="Abrir menú"
                                    >
                                        <Menu className="h-5 w-5" />
                                    </Button>
                                </SheetTrigger>
                                <SheetContent
                                    ref={mobileSheetRef}
                                    side="left"
                                    className="flex flex-col p-0 w-72 max-w-[85vw]"
                                    showCloseButton={false}
                                    overlayClassName="sheet-overlay-mobile"
                                    dataDrawer="mobile"
                                >
                                    <div
                                        className="flex shrink-0 items-center justify-between border-b border-border/50 bg-background px-4 py-3"
                                        style={{ paddingTop: "max(env(safe-area-inset-top), 0.75rem)" }}
                                    >
                                        <span className="text-base font-semibold text-foreground">Menú</span>
                                        <SheetClose asChild>
                                            <Button variant="ghost" size="icon" className="h-11 w-11 rounded-full" aria-label="Cerrar menú">
                                                <X className="h-5 w-5" />
                                            </Button>
                                        </SheetClose>
                                    </div>
                                    <div className="flex-1 min-h-0 flex flex-col pb-[env(safe-area-inset-bottom)]">
                                        {mobileMenuMounted && (
                                            <Sidebar
                                                collapsed={false}
                                                onToggle={() => handleMobileMenuOpenChange(false)}
                                                onNavigate={handleSidebarNavigate}
                                                currentPath={currentPath}
                                            />
                                        )}
                                    </div>
                                </SheetContent>
                            </Sheet>
                        </div>

                        <div className="order-1 flex shrink-0 md:hidden">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-9 w-9 -ml-1 text-muted-foreground"
                                onClick={() => window.history.back()}
                                title="Volver"
                                aria-label="Volver"
                            >
                                <ChevronLeft className="h-5 w-5" />
                            </Button>
                        </div>

                        <div
                            className={cn(
                                "flex flex-col gap-0.5 min-w-0",
                                sidebarPosition === "right"
                                    ? "order-3 ms-auto flex-1 justify-end text-right md:order-3"
                                    : "order-1 flex-1 md:order-1"
                            )}
                        >
                            <div
                                className={cn(
                                    "hidden md:flex items-center gap-2 text-xs text-muted-foreground",
                                    sidebarPosition === "right" && "justify-end"
                                )}
                            >
                                {isPortalBrand ? (
                                    <>
                                        <TenantBrandMark tenant={tenant} className="h-5 w-5 rounded-md" />
                                        <span className="truncate font-medium normal-case tracking-normal opacity-90">
                                            {tenant.name}
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        <span className="uppercase tracking-wider font-semibold opacity-70">
                                            {t("layout.panel")}
                                        </span>
                                        <ChevronRight
                                            className={cn("h-3 w-3", sidebarPosition === "right" && "rotate-180")}
                                        />
                                    </>
                                )}
                            </div>
                            <div
                                className={cn(
                                    "flex items-center gap-2 min-w-0",
                                    sidebarPosition === "right" && "justify-end"
                                )}
                            >
                                {isPortalBrand ? (
                                    <TenantBrandMark
                                        tenant={tenant}
                                        className="h-8 w-8 shrink-0 md:hidden"
                                    />
                                ) : null}
                                <div className="min-w-0 flex flex-col gap-0.5">
                                    <Head title={pageTitle} />
                                    <h1
                                        className={cn(
                                            "text-lg font-bold tracking-tight text-foreground truncate",
                                            sidebarPosition === "right" && "text-right"
                                        )}
                                    >
                                        {pageTitle}
                                    </h1>
                                </div>
                            </div>
                        </div>

                        <div
                            className={cn(
                                "flex items-center gap-2 shrink-0",
                                sidebarPosition === "right" ? "order-1" : "order-2 ms-auto"
                            )}
                        >
                            <div className="hidden md:flex items-center gap-1 rounded-full border border-border/50 bg-muted/20 p-1">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant={focused ? "secondary" : "ghost"}
                                            size="icon"
                                            className="h-7 w-7 rounded-full"
                                            onClick={() => setFocused(!focused)}
                                        >
                                            {focused ? <Minimize2 className="h-3.5 w-3.5" /> : <Maximize2 className="h-3.5 w-3.5" />}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Modo Enfoque</TooltipContent>
                                </Tooltip>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant={fullscreen ? "secondary" : "ghost"}
                                            size="icon"
                                            className="h-7 w-7 rounded-full"
                                            onClick={toggleFullscreen}
                                        >
                                            {fullscreen ? <SquareDashed className="h-3.5 w-3.5" /> : <Square className="h-3.5 w-3.5" />}
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>Pantalla Completa</TooltipContent>
                                </Tooltip>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <Button
                                            variant={forceDeviceView ? "secondary" : "ghost"}
                                            size="icon"
                                            className="h-7 w-7 rounded-full"
                                            onClick={() => setForceDeviceView((v) => !v)}
                                        >
                                            <Smartphone className="h-3.5 w-3.5" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {forceDeviceView ? "Salir vista tablet/móvil" : "Vista tablet/móvil"}
                                    </TooltipContent>
                                </Tooltip>
                            </div>

                            <Separator orientation="vertical" className="hidden md:block h-6 mx-1" />

                            <div className="flex h-11 w-11 items-center justify-center md:h-9 md:w-9">
                                <ThemeToggle variant="icon" />
                            </div>

                            <NotificationBell
                                initialNotifications={initialNotifications}
                                initialUnreadCount={initialUnreadCount}
                                onUnreadCountChange={setNotifUnreadCount}
                            />
                        </div>
                    </header>

                    <main
                        ref={mainScrollRef}
                        className={cn(
                            "flex-1 overflow-y-auto bg-muted/10 relative",
                            forceDeviceView ? "pb-32" : "pb-32 md:pb-0"
                        )}
                        tabIndex={-1}
                    >
                        <div
                            aria-hidden="true"
                            className="layout-scroll-fade pointer-events-none sticky top-0 left-0 right-0 z-20 h-2 transition-opacity duration-200 ease-out"
                            style={{ opacity: scrollFadeOpacity }}
                        />
                        <div
                            className="absolute inset-0 -z-10 h-full w-full bg-[size:32px_32px] bg-[linear-gradient(to_right,hsl(var(--border)/0.35)_1px,transparent_1px),linear-gradient(to_bottom,hsl(var(--border)/0.35)_1px,transparent_1px)]"
                            aria-hidden
                        />

                        <div
                            className={cn(
                                "mx-auto p-4 transition-all duration-300 ease-out",
                                focused ? "max-w-[1920px]" : "max-w-7xl"
                            )}
                        >
                            <div key={currentPath} className="page-transition">
                                {children}
                            </div>
                        </div>
                    </main>
                </div>

                <MobileBottomBar
                    onOpenMenu={handleMobileMenuOpenChange}
                    forceVisible={forceDeviceView}
                    unreadCount={notifUnreadCount}
                    pathname={currentPath}
                />
            </div>
        </TooltipProvider>
    );
}
