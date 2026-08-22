import { Fragment, useMemo, useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { badgeStatus } from "@/lib/badgeStyles";
import { ChevronDown, ChevronRight, Search, Users } from "lucide-react";

const currency = (value) => `$${Number(value ?? 0).toLocaleString("es-MX", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function Assignments() {
    const { roster } = usePage().props;
    const [search, setSearch] = useState("");
    const [expanded, setExpanded] = useState(() => new Set());

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return roster ?? [];
        return (roster ?? []).filter((row) => row.user_name.toLowerCase().includes(q));
    }, [roster, search]);

    const toggle = (userId) => {
        setExpanded((prev) => {
            const next = new Set(prev);
            next.has(userId) ? next.delete(userId) : next.add(userId);
            return next;
        });
    };

    return (
        <div className="p-6 space-y-6">
            <Head title="Asignaciones de inventario" />
            <div>
                <h1 className="text-xl font-semibold flex items-center gap-2">
                    <Users className="h-5 w-5" />
                    Asignaciones de inventario
                </h1>
                <p className="text-sm text-muted-foreground">Quién tiene qué -- de un vistazo, por usuario.</p>
            </div>

            <div className="relative max-w-sm">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Buscar usuario..."
                    className="pl-8"
                />
            </div>

            {filtered.length === 0 ? (
                <p className="text-sm text-muted-foreground py-10 text-center">
                    {roster?.length ? "Sin resultados para esa búsqueda." : "Nadie tiene activos asignados todavía."}
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-10" />
                            <TableHead>Usuario</TableHead>
                            <TableHead>Activos</TableHead>
                            <TableHead>Valor total</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {filtered.map((row) => {
                            const isOpen = expanded.has(row.user_id);
                            return (
                                <Fragment key={row.user_id}>
                                    <TableRow className="cursor-pointer" onClick={() => toggle(row.user_id)}>
                                        <TableCell>
                                            {isOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                        </TableCell>
                                        <TableCell className="font-medium">{row.user_name}</TableCell>
                                        <TableCell>
                                            <Badge className={badgeStatus.neutral}>{row.asset_count}</Badge>
                                        </TableCell>
                                        <TableCell className="text-sm">{currency(row.total_value)}</TableCell>
                                        <TableCell onClick={(e) => e.stopPropagation()}>
                                            <Link
                                                href={`/inventory/assets?user_id=${row.user_id}`}
                                                className="text-sm text-primary hover:underline"
                                            >
                                                Ver todos
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                    {isOpen && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="bg-muted/30">
                                                <div className="flex flex-wrap gap-2 py-1">
                                                    {row.assets.map((a) => (
                                                        <Link
                                                            key={a.id}
                                                            href={`/inventory/assets?asset=${a.id}`}
                                                            className="text-sm rounded-md border border-border/60 bg-background px-2 py-1 hover:bg-muted"
                                                        >
                                                            {a.name}{" "}
                                                            <span className="text-muted-foreground font-mono text-xs">({a.internal_tag})</span>
                                                            {a.category && <span className="text-muted-foreground"> · {a.category}</span>}
                                                        </Link>
                                                    ))}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
        </div>
    );
}

Assignments.layout = (page) => <AuthenticatedLayout title="Asignaciones de inventario">{page}</AuthenticatedLayout>;
