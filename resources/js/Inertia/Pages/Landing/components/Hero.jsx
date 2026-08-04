import { Link } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Reveal } from "@/components/ui/Reveal";
import {
    brandBadge,
    brandGradientText,
    btnBrand,
    btnBrandOutline,
    mockAvatarRing,
    mockTicketRow,
} from "@/lib/marketingTheme";
import { badgeStatusFlat, mockTicketAccentBorder } from "@/lib/badgeStyles";

const MOCK_TICKETS = [
    {
        id: "#00041",
        title: "Servidor caído — Alfa Retail CDMX",
        client: "Alfa Retail",
        time: "Hace 5 min",
        priority: "Crítico",
        accent: mockTicketAccentBorder.danger,
        badge: badgeStatusFlat.danger,
        status: "Abierto",
        avatar: "bg-[hsl(var(--brand))]",
        initials: "AR",
    },
    {
        id: "#00040",
        title: "VPN sin acceso — Beta Logística MTY",
        client: "Beta Logística",
        time: "Hace 23 min",
        priority: "Alto",
        accent: mockTicketAccentBorder.warning,
        badge: badgeStatusFlat.warning,
        status: "En proceso",
        avatar: "bg-[hsl(var(--chart-3))]",
        initials: "BL",
    },
    {
        id: "#00039",
        title: "Impresora offline — Gamma Servicios GDL",
        client: "Gamma Servicios",
        time: "Hace 1 h",
        priority: "Medio",
        accent: mockTicketAccentBorder.success,
        badge: badgeStatusFlat.success,
        status: "Resuelto",
        avatar: "bg-[hsl(var(--chart-2))]",
        initials: "GS",
    },
    {
        id: "#00038",
        title: "Correo sin sincronizar — Delta Comercial",
        client: "Delta Comercial",
        time: "Hace 2 h",
        priority: "Bajo",
        accent: "border-l-2 border-l-border",
        badge: badgeStatusFlat.success,
        status: "Resuelto",
        avatar: "bg-muted-foreground/50",
        initials: "DC",
    },
];

export default function Hero() {
    return (
        <section
            id="mission"
            className="relative flex flex-col items-center pt-28 pb-0 px-6 mkt-section-default mkt-dots overflow-hidden scroll-mt-16"
        >
            {/* Background radial glow */}
            <div className="pointer-events-none absolute top-0 left-1/2 -translate-x-1/2 w-[900px] h-[500px] -z-10 blur-[120px] bg-[radial-gradient(ellipse,hsl(var(--brand)/0.12)_0%,transparent_70%)]" />

            {/* ── Text block ─────────────────────────────── */}
            <div className="w-full max-w-3xl text-center">
                {/* Badges row */}
                <Reveal className="flex flex-wrap items-center justify-center gap-3 mb-8">
                    <div className={brandBadge}>
                        <span className="h-2 w-2 rounded-full bg-brand animate-pulse" />
                        Listo para operar en tu empresa
                    </div>
                    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground/70">
                        <span className="h-px w-4 bg-border" />
                        Un producto de{" "}
                        <span className="font-semibold text-foreground/70 tracking-tight">DDMA</span>
                    </span>
                </Reveal>

                {/* Headline */}
                <Reveal delay={80} as="h1" className="text-6xl sm:text-7xl lg:text-8xl font-black text-foreground leading-[1.15] tracking-tighter">
                    Soporte IT
                    <br />
                    <span className={`${brandGradientText} inline-block pb-2 pr-1`}>sin caos</span>
                </Reveal>

                {/* Subtitle */}
                <Reveal delay={160} as="p" className="mt-7 text-lg lg:text-xl text-muted-foreground max-w-xl mx-auto leading-relaxed">
                    Tikara reúne tickets, clientes, técnicos y SLAs en un solo panel.
                    Pensado para empresas MSP que quieren escalar sin perder el control.
                </Reveal>

                {/* CTAs */}
                <Reveal delay={240} className="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Button size="lg" className={`px-8 ${btnBrand}`} asChild>
                        <Link href="/register">Crear cuenta gratis</Link>
                    </Button>
                    <Button size="lg" variant="outline" className={btnBrandOutline} asChild>
                        <Link href="/login">Ya tengo cuenta</Link>
                    </Button>
                </Reveal>

                {/* Trust row */}
                <Reveal delay={300} as="p" className="mt-5 text-sm text-muted-foreground/80 flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
                    <span>Registro en 3 minutos</span>
                    <span className="h-1 w-1 rounded-full bg-border/80" />
                    <span>Sin tarjeta de crédito</span>
                    <span className="h-1 w-1 rounded-full bg-border/80" />
                    <span>Cancela cuando quieras</span>
                </Reveal>
            </div>

            {/* ── Dashboard mockup ───────────────────────── */}
            <Reveal delay={200} className="relative mt-16 w-full max-w-5xl">
                {/* Glow under the card */}
                <div className="pointer-events-none absolute -inset-x-10 top-12 h-32 -z-10 blur-3xl bg-[hsl(var(--brand)/0.1)]" />

                {/* Card */}
                <div className="mkt-elevated rounded-t-2xl overflow-hidden border border-border/50 border-b-0">
                    {/* Window chrome */}
                    <div className="flex items-center gap-3 px-5 py-3 border-b border-border/50 bg-muted/20">
                        <div className="flex gap-1.5">
                            <span className="h-3 w-3 rounded-full bg-rose-500/70" />
                            <span className="h-3 w-3 rounded-full bg-amber-400/70" />
                            <span className="h-3 w-3 rounded-full bg-emerald-500/70" />
                        </div>
                        <div className="flex-1 mx-4 hidden sm:block">
                            <div className="max-w-xs mx-auto flex items-center gap-2 rounded-md bg-background/60 border border-border/40 px-3 py-1">
                                <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse shrink-0" />
                                <span className="text-xs text-muted-foreground/60 font-mono truncate">
                                    panel.tikara.mx / tickets
                                </span>
                            </div>
                        </div>
                        <div className="ml-auto flex items-center gap-3">
                            <Badge className="bg-brand/12 text-brand-muted border border-brand/20 text-xs">
                                12 activos
                            </Badge>
                            <div className="flex -space-x-2">
                                {["bg-[hsl(var(--brand))]", "bg-[hsl(var(--chart-3))]", "bg-muted-foreground/60"].map((c, i) => (
                                    <div key={i} className={`h-6 w-6 rounded-full ${c} ${mockAvatarRing}`} />
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Body */}
                    <div className="flex gap-0 divide-x divide-border/40">
                        {/* Ticket list */}
                        <div className="flex-1 p-4 space-y-2">
                            {/* Table header */}
                            <div className="flex items-center justify-between pb-2 mb-1">
                                <span className="text-xs font-semibold uppercase tracking-widest text-muted-foreground/50">
                                    Tickets recientes
                                </span>
                                <div className="flex gap-1">
                                    {["Todos", "Abiertos", "En proceso"].map((f) => (
                                        <span
                                            key={f}
                                            className={`text-xs px-2 py-0.5 rounded-md cursor-default select-none ${
                                                f === "Todos"
                                                    ? "bg-brand/15 text-brand-muted font-medium"
                                                    : "text-muted-foreground/50 hover:text-muted-foreground"
                                            }`}
                                        >
                                            {f}
                                        </span>
                                    ))}
                                </div>
                            </div>

                            {MOCK_TICKETS.map((t) => (
                                <div key={t.id} className={`${mockTicketRow} ${t.accent}`}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-2.5 min-w-0">
                                            <div
                                                className={`mt-0.5 h-6 w-6 shrink-0 rounded-full ${t.avatar} flex items-center justify-center text-[9px] font-bold text-white/80`}
                                            >
                                                {t.initials}
                                            </div>
                                            <div className="min-w-0">
                                                <p className="text-foreground text-sm truncate leading-snug">
                                                    {t.title}
                                                </p>
                                                <p className="text-muted-foreground text-xs mt-0.5">
                                                    <span className="font-mono text-[10px] text-muted-foreground/50 mr-1.5">
                                                        {t.id}
                                                    </span>
                                                    {t.time} · {t.priority}
                                                </p>
                                            </div>
                                        </div>
                                        <Badge className={`${t.badge} shrink-0 text-xs`}>{t.status}</Badge>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Stats sidebar */}
                        <div className="hidden md:flex flex-col gap-3 w-52 p-4 shrink-0">
                            <span className="text-xs font-semibold uppercase tracking-widest text-muted-foreground/50 mb-1">
                                Resumen hoy
                            </span>
                            {[
                                { label: "Abiertos", value: "8", color: "text-destructive", bg: "bg-destructive/8" },
                                { label: "En proceso", value: "4", color: "text-amber-500", bg: "bg-amber-500/8" },
                                { label: "Resueltos", value: "12", color: "text-emerald-500", bg: "bg-emerald-500/8" },
                                { label: "SLA cumplido", value: "94%", color: "text-brand-muted", bg: "bg-brand/8" },
                            ].map((s) => (
                                <div
                                    key={s.label}
                                    className={`rounded-lg ${s.bg} border border-border/30 px-3 py-2.5 flex items-center justify-between`}
                                >
                                    <span className="text-xs text-muted-foreground">{s.label}</span>
                                    <span className={`text-lg font-black leading-none ${s.color}`}>{s.value}</span>
                                </div>
                            ))}

                            <div className="mt-auto pt-3 border-t border-border/40">
                                <p className="text-[10px] text-muted-foreground/40 leading-relaxed">
                                    Actualizado hace 2 min · 3 técnicos activos
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Gradient fade-out at the bottom */}
                <div className="h-20 bg-gradient-to-b from-transparent to-background" />
            </Reveal>
        </section>
    );
}
