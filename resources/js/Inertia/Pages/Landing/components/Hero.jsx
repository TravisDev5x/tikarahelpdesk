import { Link } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/ui/Reveal";
import { AbstractRibbons } from "@/components/marketing/AbstractRibbons";
import {
    brandBadge,
    brandGradientText,
    btnBrand,
    btnBrandOutline,
} from "@/lib/marketingTheme";

export default function Hero() {
    return (
        <section
            id="mission"
            className="relative flex flex-col items-center pt-28 pb-28 px-6 mkt-section-default mkt-dots overflow-hidden scroll-mt-20"
        >
            {/* Background radial glow */}
            <div className="pointer-events-none absolute top-0 left-1/2 -translate-x-1/2 w-[900px] h-[500px] -z-10 blur-[120px] bg-[radial-gradient(ellipse,hsl(var(--brand)/0.12)_0%,transparent_70%)]" />

            {/* ── Texto + gráfica, dos columnas ────────────── */}
            <div className="grid w-full max-w-7xl grid-cols-1 items-center gap-12 lg:grid-cols-2 lg:gap-8">
                <div className="text-center lg:text-left">
                    {/* Badges row */}
                    <Reveal className="flex flex-wrap items-center justify-center gap-3 mb-8 lg:justify-start">
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
                    <Reveal delay={80} as="h1" className="text-6xl sm:text-7xl lg:text-7xl xl:text-8xl font-black text-foreground leading-[1.15] tracking-tighter">
                        Soporte IT
                        <br />
                        <span className={`${brandGradientText} inline-block pb-2 pr-1`}>sin caos</span>
                    </Reveal>

                    {/* Subtitle */}
                    <Reveal delay={160} as="p" className="mt-7 text-lg lg:text-xl text-muted-foreground max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        Tikara reúne tickets, clientes, técnicos y SLAs en un solo panel.
                        Pensado para empresas MSP que quieren escalar sin perder el control.
                    </Reveal>

                    {/* CTAs */}
                    <Reveal delay={240} className="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4 lg:justify-start">
                        <Button size="lg" className={`px-8 rounded-full ${btnBrand}`} asChild>
                            <Link href="/register">Crear cuenta gratis</Link>
                        </Button>
                        <Button size="lg" variant="outline" className={`rounded-full ${btnBrandOutline}`} asChild>
                            <Link href="/login">Ya tengo cuenta</Link>
                        </Button>
                    </Reveal>

                    {/* Trust row */}
                    <Reveal delay={300} as="p" className="mt-5 text-sm text-muted-foreground/80 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 lg:justify-start">
                        <span>Registro en 3 minutos</span>
                        <span className="h-1 w-1 rounded-full bg-border/80" />
                        <span>Sin tarjeta de crédito</span>
                        <span className="h-1 w-1 rounded-full bg-border/80" />
                        <span>Cancela cuando quieras</span>
                    </Reveal>
                </div>

                {/* Gráfica abstracta -- solo desde lg, en móvil el texto ocupa todo el ancho */}
                <Reveal delay={120} className="relative hidden lg:flex items-center justify-center">
                    <AbstractRibbons className="h-auto w-full max-w-lg" gradientId="heroRibbons" />
                </Reveal>
            </div>
        </section>
    );
}
