import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

/**
 * Primitivos compartidos entre las pantallas de creación de ticket
 * (TicketCreateDialog y Resolbeb/Create) para que ambas se vean del mismo
 * sistema visual.
 */
export function Field({ label, required, hint, children, className }) {
    return (
        <div className={cn("space-y-1.5", className)}>
            <Label className="text-sm font-medium leading-none text-foreground">
                {label}
                {required ? <span className="text-destructive ml-0.5">*</span> : null}
            </Label>
            {children}
            {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
        </div>
    );
}

export function SectionHeading({ children }) {
    return (
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground pt-1">
            {children}
        </p>
    );
}
