import {
    Building2,
    Clock,
    LayoutDashboard,
    MapPin,
    ShieldCheck,
    Ticket,
} from "lucide-react";
import { Reveal } from "@/components/ui/Reveal";
import { featureCard, featureIconBox, infoBadge, sectionAlt } from "@/lib/marketingTheme";

const FEATURES = [
    {
        icon: Ticket,
        title: "Gestión de tickets",
        description:
            "Crea, asigna y resuelve incidencias con flujo completo de estados, prioridades e historial.",
    },
    {
        icon: Building2,
        title: "Multi-cliente aislado",
        description:
            "Cada empresa ve solo sus tickets. Alfa Retail nunca verá lo de Beta Logística.",
    },
    {
        icon: MapPin,
        title: "Despacho a sede",
        description: "Asigna técnicos a la ubicación exacta donde ocurre la incidencia.",
    },
    {
        icon: Clock,
        title: "SLA configurable",
        description: "Define tiempos de respuesta y resolución por cliente, área o prioridad.",
    },
    {
        icon: LayoutDashboard,
        title: "Portal para clientes",
        description:
            "Tus clientes abren y dan seguimiento a sus tickets sin acceder a tu panel interno.",
    },
    {
        icon: ShieldCheck,
        title: "Auditoría completa",
        description: "Cada acción queda registrada. Sabe quién hizo qué y cuándo en cada ticket.",
    },
];

export default function Features() {
    return (
        <section id="features" className={`${sectionAlt} scroll-mt-16`}>
            <div className="mx-auto max-w-7xl">
                <Reveal className="text-center">
                    <span className={infoBadge}>En qué creemos</span>
                    <h2 className="text-4xl font-bold text-foreground">
                        Todo lo que tu equipo de soporte necesita
                    </h2>
                    <p className="mt-3 text-muted-foreground text-center max-w-2xl mx-auto">
                        Desde el primer ticket hasta el cierre, Tikara cubre cada paso del flujo MSP.
                    </p>
                </Reveal>

                <div className="mt-16 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {FEATURES.map(({ icon: Icon, title, description }, index) => (
                        <Reveal key={title} delay={index * 80}>
                            <div className={featureCard}>
                                <div className={featureIconBox}>
                                    <Icon className="h-6 w-6 text-brand-muted" />
                                </div>
                                <h3 className="text-lg font-semibold text-foreground mb-2">{title}</h3>
                                <p className="text-sm text-muted-foreground leading-relaxed">{description}</p>
                            </div>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}
