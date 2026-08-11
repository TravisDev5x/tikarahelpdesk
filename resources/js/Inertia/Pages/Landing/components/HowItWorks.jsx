import { Reveal } from "@/components/ui/Reveal";
import { brandBadgeSm, brandGradientText, sectionDefault } from "@/lib/marketingTheme";

const STEPS = [
    {
        n: "1",
        title: "Crea tu cuenta",
        desc: "Regístrate con el correo de tu empresa en menos de 3 minutos.",
    },
    {
        n: "2",
        title: "Agrega tus clientes",
        desc: "Registra las empresas a las que prestas soporte y sus sedes.",
    },
    {
        n: "3",
        title: "Invita a tu equipo",
        desc: "Envía invitaciones a tus técnicos y agentes de soporte.",
    },
    {
        n: "4",
        title: "Empieza a operar",
        desc: "Recibe tickets, asigna técnicos y resuelve incidencias.",
    },
];

export default function HowItWorks() {
    return (
        <section id="how-it-works" className={`${sectionDefault} scroll-mt-20`}>
            <div className="mx-auto max-w-7xl">
                <Reveal className="text-center mb-16">
                    <span className={`inline-block ${brandBadgeSm} mb-4`}>Cómo empezar</span>
                    <h2 className="text-4xl font-bold text-foreground">Operativo en minutos</h2>
                    <p className="mt-3 text-muted-foreground">Sin instalaciones. Sin configuraciones complejas.</p>
                </Reveal>

                <div className="grid grid-cols-1 md:grid-cols-4 gap-10 md:gap-6">
                    {STEPS.map((step, index) => (
                        <Reveal
                            key={step.n}
                            delay={index * 100}
                            className="relative text-center md:text-left"
                        >
                            {index < STEPS.length - 1 && (
                                <div
                                    className="hidden md:block absolute top-8 left-[calc(50%+2rem)] right-0 h-px bg-gradient-to-r from-[hsl(var(--brand)/0.5)] to-transparent"
                                    aria-hidden
                                />
                            )}
                            <span className={`text-6xl font-black ${brandGradientText}`}>{step.n}</span>
                            <h3 className="text-foreground font-semibold mt-3">{step.title}</h3>
                            <p className="text-muted-foreground text-sm mt-1 leading-relaxed">{step.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}
