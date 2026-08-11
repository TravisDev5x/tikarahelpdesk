import { Head, Link, usePage } from "@inertiajs/react";
import { useAuth } from "@/context/AuthContext";
import { ThemeToggle } from "@/components/ThemeToggle";
import { Button } from "@/components/ui/button";
import { getTenantBrandName, getTenantLogoUrl } from "@/lib/tenantBranding";
import { cn } from "@/lib/utils";
import axios from "@/lib/axios";
import { useState } from "react";
import { LogOut, TicketIcon, LayoutDashboard } from "lucide-react";

export default function PortalLayout({ title, children }) {
    const { tenant = {} } = usePage().props;
    const { user } = useAuth();
    const brandName = getTenantBrandName(tenant, "Portal");
    const logoUrl = getTenantLogoUrl(tenant);
    const [loggingOut, setLoggingOut] = useState(false);

    const handleLogout = async () => {
        setLoggingOut(true);
        try {
            await axios.post("/api/auth/logout");
        } finally {
            window.location.href = "/login";
        }
    };

    return (
        <>
            <Head title={title ? `${title} — ${brandName}` : brandName} />

            <div className="min-h-dvh bg-background text-foreground flex flex-col">
                {/* Header */}
                <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
                    <div className="mx-auto flex h-14 max-w-5xl items-center gap-2 px-4 sm:gap-4">
                        {/* Brand */}
                        <Link href="/" className="flex min-w-0 shrink-0 items-center gap-2 font-semibold">
                            {logoUrl ? (
                                <img src={logoUrl} alt={brandName} className="h-7 w-auto" />
                            ) : (
                                <span className="max-w-[7rem] truncate text-primary sm:max-w-none">{brandName}</span>
                            )}
                        </Link>

                        {/* Nav */}
                        <nav className="ml-2 flex items-center gap-1 sm:ml-4">
                            <NavLink href="/" icon={<LayoutDashboard className="h-4 w-4" />}>
                                Inicio
                            </NavLink>
                            <NavLink href="/tickets" icon={<TicketIcon className="h-4 w-4" />}>
                                Mis Tickets
                            </NavLink>
                        </nav>

                        {/* Right side */}
                        <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                            {user && (
                                <span className="hidden text-sm text-muted-foreground sm:block">
                                    {user.first_name || user.name}
                                </span>
                            )}
                            <ThemeToggle />
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={handleLogout}
                                disabled={loggingOut}
                                title="Cerrar sesión"
                            >
                                <LogOut className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </header>

                {/* Main */}
                <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
                    {children}
                </main>

                {/* Footer */}
                <footer className="border-t py-4 text-center text-xs text-muted-foreground">
                    {brandName} · Soporte técnico
                </footer>
            </div>
        </>
    );
}

function NavLink({ href, icon, children }) {
    const { url } = usePage();
    const active = url === href || (href !== "/" && url.startsWith(href));

    return (
        <Link
            href={href}
            title={typeof children === "string" ? children : undefined}
            className={cn(
                "flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm transition-colors sm:px-3",
                active
                    ? "bg-accent text-accent-foreground font-medium"
                    : "text-muted-foreground hover:bg-accent/50 hover:text-foreground"
            )}
        >
            {icon}
            <span className="hidden sm:inline">{children}</span>
        </Link>
    );
}
