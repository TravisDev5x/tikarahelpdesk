import { useState } from "react";
import { Link } from "@inertiajs/react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Reveal } from "@/components/ui/Reveal";
import { brandLogo, btnBrand, footerLink } from "@/lib/marketingTheme";
import {
    Twitter,
    Linkedin,
    Github,
    Youtube,
    CheckCircle2,
} from "lucide-react";

// ── Nav data ──────────────────────────────────────────────────────────────
const NAV = [
    {
        heading: "Producto",
        links: [
            { label: "Tickets y soporte", href: "#features" },
            { label: "Portal del cliente", href: "#features" },
            { label: "Email inbound", href: "#features" },
            { label: "Clasificación con IA", href: "#features" },
            { label: "Reportes y analíticas", href: "#features" },
            { label: "SLAs y alertas", href: "#features" },
            { label: "Planes y precios", href: "#pricing" },
        ],
    },
    {
        heading: "Soluciones",
        links: [
            { label: "MSP / Multi-tenant", href: "#features" },
            { label: "IT interno", href: "#features" },
            { label: "Gestión de incidentes", href: "#features" },
            { label: "Equipos remotos", href: "#features" },
            { label: "Empresas medianas", href: "#features" },
        ],
    },
    {
        heading: "Recursos",
        links: [
            { label: "Documentación", href: "/manual" },
            { label: "Guía de inicio rápido", href: "#how-it-works" },
            { label: "Estado del sistema", href: "#footer" },
            { label: "Changelog", href: "#footer" },
            { label: "Blog", href: "#footer" },
        ],
    },
    {
        heading: "Empresa",
        links: [
            { label: "Misión", href: "#mission" },
            { label: "Sobre DDMA", href: "#footer" },
            { label: "Contacto", href: "#footer" },
            { label: "Aviso de privacidad", href: "/privacidad" },
            { label: "Términos de servicio", href: "/terminos" },
            { label: "Iniciar sesión", href: "/login" },
            { label: "Crear cuenta", href: "/register" },
        ],
    },
];

const SOCIAL = [
    { Icon: Twitter,  label: "X / Twitter",  href: "#" },
    { Icon: Linkedin, label: "LinkedIn",      href: "#" },
    { Icon: Github,   label: "GitHub",        href: "#" },
    { Icon: Youtube,  label: "YouTube",       href: "#" },
];

// ── Newsletter ─────────────────────────────────────────────────────────────
function Newsletter() {
    const [email, setEmail] = useState("");
    const [sent, setSent] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();
        if (email.includes("@")) setSent(true);
    };

    return (
        <div className="mt-6">
            <p className="text-sm text-muted-foreground mb-3 leading-snug">
                Recibe actualizaciones y noticias de Tikara.
            </p>
            {sent ? (
                <div className="flex items-center gap-2 text-sm text-emerald-500">
                    <CheckCircle2 className="h-4 w-4 shrink-0" />
                    ¡Gracias! Te avisamos cuando haya novedades.
                </div>
            ) : (
                <form onSubmit={handleSubmit} className="flex flex-col gap-2">
                    <Input
                        type="email"
                        placeholder="tu@empresa.com"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        className="h-9 bg-background/60 border-border/60 text-sm placeholder:text-muted-foreground/50"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        className={`w-fit ${btnBrand}`}
                    >
                        Suscribirse
                    </Button>
                </form>
            )}
        </div>
    );
}

// ── Footer ─────────────────────────────────────────────────────────────────
export default function Footer() {
    const year = new Date().getFullYear();

    return (
        <footer id="footer" className="border-t border-border/50 bg-secondary/20 px-6 pt-16 pb-10 scroll-mt-20">
            <div className="mx-auto max-w-7xl">
                {/* Main grid */}
                <Reveal as="div" className="grid grid-cols-2 gap-x-8 gap-y-12 sm:grid-cols-3 lg:grid-cols-[260px_repeat(4,1fr)]">

                    {/* ── Brand column ── */}
                    <div className="col-span-2 sm:col-span-3 lg:col-span-1">
                        {/* Logo */}
                        <Link href="/" className="inline-flex items-center gap-2.5 hover:opacity-90 transition-opacity">
                            <div className={`h-8 w-8 text-[10px] shrink-0 ${brandLogo}`}>TI</div>
                            <div>
                                <p className="text-base font-bold text-foreground leading-tight">Tikara</p>
                                <p className="text-[10px] text-muted-foreground/60 font-medium tracking-wide leading-tight">
                                    por DDMA
                                </p>
                            </div>
                        </Link>

                        {/* Social icons */}
                        <div className="mt-5 flex gap-2">
                            {SOCIAL.map(({ Icon, label, href }) => (
                                <a
                                    key={label}
                                    href={href}
                                    aria-label={label}
                                    className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground/60 transition-colors hover:bg-muted/60 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                >
                                    <Icon className="h-4 w-4" />
                                </a>
                            ))}
                        </div>

                        {/* Newsletter */}
                        <Newsletter />
                    </div>

                    {/* ── Link columns ── */}
                    {NAV.map(({ heading, links }) => (
                        <div key={heading}>
                            <h4 className="text-sm font-semibold text-foreground mb-4">
                                {heading}
                            </h4>
                            <ul className="space-y-2.5">
                                {links.map(({ label, href }) => (
                                    <li key={label}>
                                        {href.startsWith("/") ? (
                                            <Link href={href} className={`text-sm ${footerLink}`}>
                                                {label}
                                            </Link>
                                        ) : (
                                            <a href={href} className={`text-sm ${footerLink}`}>
                                                {label}
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </Reveal>

                {/* Divider */}
                <Separator className="mt-14 mb-6 bg-border/40" />

                {/* Bottom bar */}
                <div className="text-xs text-muted-foreground/70">
                    <p>
                        © {year}{" "}
                        <span className="font-medium text-muted-foreground/90">Tikara</span>
                        {" · "}una marca de{" "}
                        <span className="font-medium text-muted-foreground/90">
                            DDMA Desarrollos Digitales Mexicanos y Asociados
                        </span>
                        . Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </footer>
    );
}
