import { Fragment } from "react";
import { cn } from "@/lib/utils";

/**
 * @param {{ step: number; label: string; status: 'done' | 'active' | 'upcoming' }} item
 */
function StepItem({ step, label, status }) {
    const dotClass =
        status === "done"
            ? "bg-primary/15 text-primary border border-primary/30 font-semibold"
            : status === "active"
              ? "bg-primary text-primary-foreground font-semibold"
              : "border border-border text-muted-foreground";

    const labelClass =
        status === "active" ? "text-sm font-medium text-foreground" : "text-sm text-muted-foreground";

    const wrapOpacity = status === "upcoming" ? "opacity-50" : "";

    return (
        <div className={cn("flex items-center gap-2", wrapOpacity)}>
            <span
                className={cn(
                    "flex h-8 w-8 items-center justify-center rounded-full text-sm shrink-0",
                    dotClass
                )}
            >
                {status === "done" ? "✓" : step}
            </span>
            <span className={cn(labelClass, "hidden sm:inline")}>{label}</span>
        </div>
    );
}

/**
 * Indicador de pasos genérico -- mismo estilo visual que
 * components/onboarding/OnboardingStepIndicator.jsx (que se queda fijo en
 * 2 pasos para ese flujo), aquí generalizado a N pasos con label libre.
 * @param {{ steps: string[]; currentStep: number }} props
 */
export function StepIndicator({ steps, currentStep }) {
    return (
        <div className="flex items-center justify-center gap-2 sm:gap-3 flex-wrap">
            {steps.map((label, i) => {
                const step = i + 1;
                const status = step < currentStep ? "done" : step === currentStep ? "active" : "upcoming";
                return (
                    <Fragment key={label}>
                        {i > 0 && <div className="h-px w-6 sm:w-10 bg-border shrink-0" aria-hidden />}
                        <StepItem step={step} label={label} status={status} />
                    </Fragment>
                );
            })}
        </div>
    );
}
