import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";

/**
 * Envoltorio de animación de entrada al hacer scroll (fade + slide).
 * Sin dependencias externas: IntersectionObserver + clases CSS (.reveal en app.css).
 * Respeta prefers-reduced-motion vía CSS (el elemento igual queda visible).
 *
 * Una vez que la transición termina, se quitan las clases de animación (y con
 * ellas `will-change`/`transition`) para que el nodo quede "limpio": mantener
 * will-change indefinidamente en muchos elementos fuerza al navegador a
 * sostener capas de composición todo el tiempo, lo que se siente pesado en
 * cualquier interacción posterior, no solo durante la entrada.
 */
export function Reveal({
    as: Tag = "div",
    direction = "up",
    delay = 0,
    className,
    children,
    ...props
}) {
    const ref = useRef(null);
    const [visible, setVisible] = useState(false);
    const [settled, setSettled] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        if (typeof IntersectionObserver === "undefined") {
            setVisible(true);
            setSettled(true);
            return;
        }
        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { threshold: 0.15, rootMargin: "0px 0px -10% 0px" }
        );
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        if (!visible || settled) return;
        const timer = setTimeout(() => setSettled(true), delay + 750);
        return () => clearTimeout(timer);
    }, [visible, settled, delay]);

    if (settled) {
        return (
            <Tag className={className} {...props}>
                {children}
            </Tag>
        );
    }

    const directionClass = direction === "left" ? "reveal-left" : direction === "right" ? "reveal-right" : "";

    return (
        <Tag
            ref={ref}
            className={cn("reveal", directionClass, visible && "is-visible", className)}
            style={{ transitionDelay: visible && delay ? `${delay}ms` : undefined }}
            {...props}
        >
            {children}
        </Tag>
    );
}
