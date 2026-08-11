import { Button } from "@/components/ui/button";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from "lucide-react";
import { cn } from "@/lib/utils";

const DEFAULT_PER_PAGE_OPTIONS = ["10", "15", "25", "50", "100"];

/**
 * Paginación reutilizable para tablas (catálogos, usuarios, tickets).
 * Compatible con paginación client-side (lista en memoria) y server-side (API).
 *
 * @param {number} total - Total de registros
 * @param {number} from - Índice del primer registro en la página actual (1-based)
 * @param {number} to - Índice del último registro en la página actual
 * @param {number} currentPage - Página actual (1-based)
 * @param {number} lastPage - Última página
 * @param {string} perPage - Filas por página (ej. "10")
 * @param {string[]} perPageOptions - Opciones para el select (default 10,15,25,50,100)
 * @param {(value: string) => void} onPerPageChange - Al cambiar filas por página
 * @param {(page: number) => void} onPageChange - Ir a página (1-based)
 * @param {boolean} [showPerPage=true] - Mostrar selector de filas
 * @param {boolean} [loading=false] - Deshabilita botones
 * @param {string} [className] - Clases extra para el contenedor
 */
export function TablePagination({
    total,
    from,
    to,
    currentPage,
    lastPage,
    perPage,
    perPageOptions = DEFAULT_PER_PAGE_OPTIONS,
    onPerPageChange,
    onPageChange,
    showPerPage = true,
    loading = false,
    className,
}) {
    const lastPageSafe = Math.max(1, lastPage);
    const pageNumbers = getPageNumbers(currentPage, lastPageSafe);

    const summary =
        total === 0
            ? "0 registros"
            : `${from}–${to} de ${total}`;

    return (
        <div
            className={cn(
                "flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-border/50 bg-muted/20",
                className
            )}
        >
            <div className="flex items-center gap-2 flex-wrap">
                {showPerPage && onPerPageChange && (
                    <>
                        <span className="text-xs text-muted-foreground whitespace-nowrap">
                            Filas
                        </span>
                        <Select
                            value={perPage}
                            onValueChange={(v) => {
                                onPerPageChange(v);
                                onPageChange(1);
                            }}
                            disabled={loading}
                        >
                            <SelectTrigger className="h-8 w-[70px] text-xs bg-background border-border/60">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {perPageOptions.map((v) => (
                                    <SelectItem key={v} value={v}>
                                        {v}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </>
                )}
                <span className="text-xs text-muted-foreground tabular-nums">
                    {summary}
                </span>
            </div>
            <div className="flex items-center gap-0.5 max-w-full overflow-x-auto">
                <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    disabled={currentPage <= 1 || loading}
                    onClick={() => onPageChange(1)}
                    aria-label="Primera página"
                >
                    <ChevronsLeft className="h-3.5 w-3.5" />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    disabled={currentPage <= 1 || loading}
                    onClick={() => onPageChange(currentPage - 1)}
                    aria-label="Página anterior"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                </Button>
                <div className="hidden items-center gap-0.5 mx-1 sm:flex">
                    {pageNumbers.map((p, i) =>
                        p === "…" ? (
                            <span
                                key={`ellipsis-${i}`}
                                className="px-1.5 text-xs text-muted-foreground"
                                aria-hidden
                            >
                                …
                            </span>
                        ) : (
                            <Button
                                key={p}
                                variant={currentPage === p ? "secondary" : "ghost"}
                                size="icon"
                                className={cn(
                                    "h-8 w-8 min-w-8 shrink-0 text-xs",
                                    currentPage === p &&
                                        "bg-primary/15 text-primary font-semibold"
                                )}
                                disabled={loading}
                                onClick={() => onPageChange(p)}
                                aria-label={`Página ${p}`}
                                aria-current={currentPage === p ? "page" : undefined}
                            >
                                {p}
                            </Button>
                        )
                    )}
                </div>
                <span className="mx-1 text-xs text-muted-foreground tabular-nums sm:hidden">
                    {currentPage}/{lastPageSafe}
                </span>
                <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    disabled={currentPage >= lastPageSafe || loading}
                    onClick={() => onPageChange(currentPage + 1)}
                    aria-label="Página siguiente"
                >
                    <ChevronRight className="h-3.5 w-3.5" />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    disabled={currentPage >= lastPageSafe || loading}
                    onClick={() => onPageChange(lastPageSafe)}
                    aria-label="Última página"
                >
                    <ChevronsRight className="h-3.5 w-3.5" />
                </Button>
            </div>
        </div>
    );
}

function getPageNumbers(currentPage, lastPage) {
    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, i) => i + 1);
    }
    const pages = [1];
    if (currentPage > 3) pages.push("…");
    for (
        let i = Math.max(2, currentPage - 1);
        i <= Math.min(lastPage - 1, currentPage + 1);
        i++
    ) {
        if (!pages.includes(i)) pages.push(i);
    }
    if (currentPage < lastPage - 2) pages.push("…");
    if (lastPage > 1) pages.push(lastPage);
    return pages;
}
