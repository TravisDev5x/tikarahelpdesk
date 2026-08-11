import { useMemo, useState } from "react";
import { CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { PlanTypeBadge } from "@/components/badges/EntityBadges";
import { Reveal } from "@/components/ui/Reveal";
import {
    brandBadgeSm,
    brandGradientFill,
    btnBrand,
    btnBrandOutline,
    infoBadge,
    planCard,
    planCardHighlighted,
    planChip,
    pricingTabActive,
    pricingTabInactive,
    pricingTabWrap,
    sectionAlt,
} from "@/lib/marketingTheme";
import { cn } from "@/lib/utils";

function formatPrice(value) {
    const n = Number(value);
    if (!n) return "0";
    return new Intl.NumberFormat("es-MX", {
        style: "currency",
        currency: "MXN",
        maximumFractionDigits: 0,
    }).format(n);
}

function sortPlans(plans) {
    const enterprise = plans.filter((p) => p.slug === "enterprise");
    const rest = plans
        .filter((p) => p.slug !== "enterprise")
        .sort((a, b) => Number(a.price_monthly) - Number(b.price_monthly));
    return [...rest, ...enterprise];
}

function PlanPrice({ plan, billingCycle }) {
    const monthly = Number(plan.price_monthly);
    const yearly = Number(plan.price_yearly);

    if (monthly === 0) {
        return (
            <>
                <div className="text-4xl font-black text-foreground">Contactar</div>
                <div className="text-muted-foreground text-sm mt-1">Precio a medida</div>
            </>
        );
    }

    if (billingCycle === "yearly" && yearly > 0) {
        const perMonth = Math.round(yearly / 12);
        return (
            <>
                <div className="text-4xl font-black text-foreground">
                    {formatPrice(perMonth)}
                    <span className="text-muted-foreground text-lg font-normal">/mes</span>
                </div>
                <div className="text-muted-foreground text-sm mt-1">
                    facturado anualmente ·{" "}
                    <span className="line-through">{formatPrice(monthly)}/mes</span>
                </div>
            </>
        );
    }

    return (
        <div className="text-4xl font-black text-foreground">
            {formatPrice(monthly)}
            <span className="text-muted-foreground text-lg font-normal">/mes</span>
        </div>
    );
}

function PlanCard({ plan, billingCycle }) {
    const highlighted = Boolean(plan.highlighted);
    const isContact = Number(plan.price_monthly) === 0;

    return (
        <div
            className={cn(planCard, highlighted && planCardHighlighted)}
        >
            {highlighted && (
                <Badge
                    className={cn(
                        "absolute -top-3.5 left-1/2 -translate-x-1/2 whitespace-nowrap border-0 px-4 py-1 text-xs font-bold text-brand-foreground",
                        brandGradientFill
                    )}
                >
                    Más popular
                </Badge>
            )}

            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-sm font-medium uppercase tracking-wider text-muted-foreground">
                    {plan.name}
                </span>
                <PlanTypeBadge type={plan.type} />
            </div>

            <div className="mb-2 mt-4">
                <PlanPrice plan={plan} billingCycle={billingCycle} />
            </div>

            {plan.trial_days > 0 && (
                <div className="mt-1 text-xs text-brand-muted">
                    {plan.trial_days} días gratis para empezar
                </div>
            )}

            <Separator className="my-5 bg-border" />

            <div className="mb-1">
                {plan.max_clients != null && (
                    <span className={planChip}>{plan.max_clients} clientes</span>
                )}
                <span className={planChip}>{plan.max_users ?? "∞"} usuarios</span>
                <span className={planChip}>{plan.max_agents ?? "∞"} agentes</span>
            </div>

            <div className="mt-4 flex flex-1 flex-col">
                <ul className="space-y-2">
                    {(plan.features || []).map((feature) => (
                        <li key={feature} className="flex items-start gap-2">
                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-brand-muted" />
                            <span className="text-sm text-muted-foreground">{feature}</span>
                        </li>
                    ))}
                </ul>
            </div>

            <div className="mt-auto pt-6">
                {isContact ? (
                    <Button
                        type="button"
                        variant="outline"
                        className={`h-11 w-full rounded-lg shadow-none transition-all duration-200 ${btnBrandOutline}`}
                        onClick={() => {
                            window.location.href = "mailto:ventas@tikara.mx";
                        }}
                    >
                        Contactar ventas
                    </Button>
                ) : (
                    <Button
                        type="button"
                        className={`h-11 w-full rounded-lg font-semibold transition-all duration-200 ${
                            highlighted ? btnBrand : btnBrandOutline
                        }`}
                        variant={highlighted ? "default" : "outline"}
                        onClick={() => {
                            window.location.href = `/register?plan=${plan.slug}`;
                        }}
                    >
                        Empezar gratis
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function Pricing({ plans = [] }) {
    const [activeTab, setActiveTab] = useState("msp");
    const [billingCycle, setBillingCycle] = useState("monthly");
    const isYearly = billingCycle === "yearly";

    const mspPlans = useMemo(
        () =>
            sortPlans(plans.filter((p) => p.type === "msp" || p.type === "both")),
        [plans]
    );
    const inhousePlans = useMemo(
        () =>
            sortPlans(plans.filter((p) => p.type === "inhouse" || p.type === "both")),
        [plans]
    );

    const activePlans = activeTab === "msp" ? mspPlans : inhousePlans;

    return (
        <section id="pricing" className={`${sectionAlt} px-6 scroll-mt-20`}>
            <div className="mx-auto max-w-7xl">
                <Reveal className="text-center">
                    <span className={infoBadge}>Planes</span>
                    <h2 className="text-center text-4xl font-bold text-foreground">
                        Precios simples, sin sorpresas
                    </h2>
                    <p className="mx-auto mt-3 max-w-xl text-center text-muted-foreground">
                        Empieza gratis 14 días. Sin tarjeta de crédito.
                    </p>
                </Reveal>

                <div className="mt-10 flex justify-center">
                    <div className={pricingTabWrap}>
                        <button
                            type="button"
                            onClick={() => setActiveTab("msp")}
                            className={activeTab === "msp" ? pricingTabActive : pricingTabInactive}
                        >
                            <div className="text-center">
                                <div className="font-semibold">Para empresas MSP</div>
                                <div className="mt-0.5 text-xs opacity-70">
                                    Presta soporte a tus clientes
                                </div>
                            </div>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab("inhouse")}
                            className={activeTab === "inhouse" ? pricingTabActive : pricingTabInactive}
                        >
                            <div className="text-center">
                                <div className="font-semibold">Para uso interno</div>
                                <div className="mt-0.5 text-xs opacity-70">Gestiona tu IT interno</div>
                            </div>
                        </button>
                    </div>
                </div>

                <p className="mt-3 text-center text-sm text-muted-foreground">
                    {activeTab === "msp"
                        ? "Gestiona múltiples empresas desde un solo panel"
                        : "Tu equipo IT y tus empleados en un solo lugar"}
                </p>

                <div className="mt-8 flex items-center justify-center gap-3">
                    <span className="text-sm text-muted-foreground">Mensual</span>
                    <Switch
                        checked={isYearly}
                        onCheckedChange={(checked) =>
                            setBillingCycle(checked ? "yearly" : "monthly")
                        }
                        className="data-[state=checked]:bg-brand"
                    />
                    <span className="text-sm text-muted-foreground">Anual</span>
                    {isYearly && (
                        <Badge className={`text-xs border-0 ${brandBadgeSm}`}>2 meses gratis</Badge>
                    )}
                </div>

                <div className="mt-12 grid grid-cols-1 items-stretch gap-6 lg:grid-cols-3">
                    {activePlans.map((plan, index) => (
                        <Reveal
                            key={`${activeTab}-${plan.id}`}
                            delay={index * 100}
                            className="flex h-full [&>*]:w-full [&>*]:flex-1"
                        >
                            <PlanCard plan={plan} billingCycle={billingCycle} />
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}
