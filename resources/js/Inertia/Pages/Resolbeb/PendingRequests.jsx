import { useCallback, useEffect, useRef, useState } from "react";
import { Head, Link } from "@inertiajs/react";
import { formatDistanceToNow } from "date-fns";
import { es } from "date-fns/locale";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import InertiaPageShell from "@/Inertia/components/InertiaPageShell";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { notify } from "@/lib/notify";
import { getApiErrorMessage } from "@/lib/apiErrors";
import { Check, Inbox, Search, UserPlus, X } from "lucide-react";

const REASON_INFO = {
    unregistered: { label: "Sin cuenta", variant: "outline" },
    inactive: { label: "Cuenta inactiva", variant: "secondary" },
    wrong_tenant: { label: "Otro tenant", variant: "destructive" },
};

function ReviewDialog({ request, open, onOpenChange, onResolved }) {
    const [search, setSearch] = useState("");
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);
    const [linkingId, setLinkingId] = useState(null);
    const [rejecting, setRejecting] = useState(false);
    const [note, setNote] = useState("");
    const [showRejectNote, setShowRejectNote] = useState(false);
    const debounceRef = useRef(null);

    useEffect(() => {
        if (!open) {
            setSearch("");
            setResults([]);
            setNote("");
            setShowRejectNote(false);
            return;
        }
        setSearch(request?.matched_user?.email ?? "");
    }, [open, request]);

    useEffect(() => {
        if (!open || request?.reason === "wrong_tenant") return undefined;
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(async () => {
            setSearching(true);
            try {
                const { data } = await axios.get("/api/users", {
                    params: { search, user_status: "active", per_page: 8 },
                });
                setResults(data?.data ?? []);
            } catch {
                setResults([]);
            } finally {
                setSearching(false);
            }
        }, 300);
        return () => clearTimeout(debounceRef.current);
    }, [search, open, request]);

    if (!request) return null;

    const linkUser = async (userId) => {
        setLinkingId(userId);
        try {
            const { data } = await axios.post(`/api/pending-ticket-requests/${request.id}/approve`, {
                user_id: userId,
            });
            notify.success(`Ticket ${data?.ticket?.folio ?? ""} creado`.trim());
            onResolved();
            onOpenChange(false);
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudo vincular el usuario."));
        } finally {
            setLinkingId(null);
        }
    };

    const reject = async () => {
        setRejecting(true);
        try {
            await axios.post(`/api/pending-ticket-requests/${request.id}/reject`, { note: note || null });
            notify.success("Solicitud rechazada");
            onResolved();
            onOpenChange(false);
        } catch (err) {
            notify.error(getApiErrorMessage(err, "No se pudo rechazar la solicitud."));
        } finally {
            setRejecting(false);
        }
    };

    const createNewUserHref =
        `/users?create=1&first_name=${encodeURIComponent(request.from_name ?? "")}` +
        `&email=${encodeURIComponent(request.from_email ?? "")}`;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Revisar solicitud</DialogTitle>
                    <DialogDescription>
                        {request.from_name ? `${request.from_name} · ` : ""}
                        {request.from_email}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {request.subject && <p className="text-sm font-medium">{request.subject}</p>}
                    {request.body && (
                        <p className="text-xs text-muted-foreground line-clamp-4 whitespace-pre-wrap">
                            {request.body}
                        </p>
                    )}

                    {request.reason === "inactive" && request.matched_user && (
                        <div className="rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-xs">
                            Ya existe una cuenta ({request.matched_user.email}) pero no está activa. Actívala
                            desde Usuarios antes de vincular, o rechaza esta solicitud.
                        </div>
                    )}

                    {request.reason === "wrong_tenant" && (
                        <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-xs">
                            Este correo ya pertenece a otro tenant de la plataforma — no se puede vincular ni
                            crear una cuenta nueva con el mismo correo aquí. Solo se puede rechazar.
                        </div>
                    )}

                    {request.reason !== "wrong_tenant" && (
                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <Search className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Buscar usuario por nombre o correo..."
                                    className="h-8 text-xs"
                                />
                            </div>
                            <div className="max-h-40 divide-y overflow-y-auto rounded-md border">
                                {searching ? (
                                    <div className="p-3 text-xs text-muted-foreground">Buscando...</div>
                                ) : results.length === 0 ? (
                                    <div className="p-3 text-xs text-muted-foreground">Sin resultados</div>
                                ) : (
                                    results.map((u) => (
                                        <button
                                            key={u.id}
                                            type="button"
                                            disabled={linkingId !== null}
                                            onClick={() => linkUser(u.id)}
                                            className="flex w-full items-center justify-between gap-2 p-2 text-left text-xs hover:bg-muted/50 disabled:opacity-50"
                                        >
                                            <span className="truncate">
                                                <span className="font-medium">{u.name}</span>{" "}
                                                <span className="text-muted-foreground">{u.email}</span>
                                            </span>
                                            <Check className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                        </button>
                                    ))
                                )}
                            </div>
                            <Button asChild variant="ghost" size="sm" className="w-full justify-start gap-1.5 text-xs">
                                <Link href={createNewUserHref}>
                                    <UserPlus className="h-3.5 w-3.5" /> Crear usuario nuevo con este correo
                                </Link>
                            </Button>
                        </div>
                    )}

                    {showRejectNote && (
                        <Textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Nota (opcional)"
                            className="text-xs"
                            rows={2}
                        />
                    )}
                </div>

                <DialogFooter className="gap-2 sm:justify-between">
                    {!showRejectNote ? (
                        <Button type="button" variant="outline" size="sm" onClick={() => setShowRejectNote(true)}>
                            <X className="h-3.5 w-3.5 mr-1.5" /> Rechazar
                        </Button>
                    ) : (
                        <Button type="button" variant="destructive" size="sm" onClick={reject} disabled={rejecting}>
                            Confirmar rechazo
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ------------------------------------------------------------------
// VISTA MÓVIL: CARD POR SOLICITUD (solo visible en viewport < md)
// ------------------------------------------------------------------
function RequestCard({ request, onReview }) {
    const reasonInfo = REASON_INFO[request.reason] ?? { label: request.reason, variant: "outline" };

    return (
        <Card className="overflow-hidden">
            <CardContent className="p-4 flex flex-col gap-2">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="text-sm font-medium truncate">{request.from_name || request.from_email}</p>
                        {request.from_name && (
                            <p className="text-xs text-muted-foreground truncate">{request.from_email}</p>
                        )}
                    </div>
                    <Badge variant={reasonInfo.variant} className="shrink-0">{reasonInfo.label}</Badge>
                </div>
                {request.subject && (
                    <p className="text-sm text-muted-foreground truncate">{request.subject}</p>
                )}
                <div className="flex items-center justify-between gap-2 pt-1">
                    <span className="text-xs text-muted-foreground">
                        {formatDistanceToNow(new Date(request.created_at), { addSuffix: true, locale: es })}
                    </span>
                    {request.status === "pending" ? (
                        <Button size="sm" variant="outline" className="min-h-[44px]" onClick={() => onReview(request)}>
                            Revisar
                        </Button>
                    ) : (
                        <Badge variant={request.status === "approved" ? "secondary" : "outline"}>
                            {request.status === "approved"
                                ? `Aprobada${request.resulting_ticket ? " · #" + request.resulting_ticket.folio : ""}`
                                : "Rechazada"}
                        </Badge>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function RequestsTable({ items, loading, onReview }) {
    if (loading) {
        return (
            <div className="space-y-2">
                {[1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-12 w-full" />
                ))}
            </div>
        );
    }

    if (items.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 py-16 text-center text-sm text-muted-foreground">
                <Inbox className="h-8 w-8 opacity-40" />
                Sin solicitudes
            </div>
        );
    }

    return (
        <>
            {/* VISTA MÓVIL: CARDS */}
            <div className="block md:hidden space-y-3">
                {items.map((r) => (
                    <RequestCard key={r.id} request={r} onReview={onReview} />
                ))}
            </div>

            {/* TABLA DESKTOP */}
            <div className="hidden md:block">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Remitente</TableHead>
                            <TableHead>Asunto</TableHead>
                            <TableHead>Motivo</TableHead>
                            <TableHead>Recibido</TableHead>
                            <TableHead className="text-right">Acciones</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.map((r) => {
                            const reasonInfo = REASON_INFO[r.reason] ?? { label: r.reason, variant: "outline" };
                            return (
                                <TableRow key={r.id}>
                                    <TableCell>
                                        <p className="text-sm font-medium">{r.from_name || r.from_email}</p>
                                        {r.from_name && (
                                            <p className="text-xs text-muted-foreground">{r.from_email}</p>
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-[260px] truncate text-sm">
                                        {r.subject || "—"}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={reasonInfo.variant}>{reasonInfo.label}</Badge>
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {formatDistanceToNow(new Date(r.created_at), { addSuffix: true, locale: es })}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {r.status === "pending" ? (
                                            <Button size="sm" variant="outline" onClick={() => onReview(r)}>
                                                Revisar
                                            </Button>
                                        ) : (
                                            <Badge variant={r.status === "approved" ? "secondary" : "outline"}>
                                                {r.status === "approved"
                                                    ? `Aprobada${r.resulting_ticket ? " · #" + r.resulting_ticket.folio : ""}`
                                                    : "Rechazada"}
                                            </Badge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}

export default function PendingRequests() {
    const [tab, setTab] = useState("pending");
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [reviewing, setReviewing] = useState(null);

    const load = useCallback(async (status) => {
        setLoading(true);
        try {
            const { data } = await axios.get("/api/pending-ticket-requests", { params: { status } });
            setItems(Array.isArray(data?.data) ? data.data : []);
        } catch (err) {
            if (!err?.duringLogout) {
                notify.error(getApiErrorMessage(err, "No se pudieron cargar las solicitudes."));
            }
            setItems([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(tab === "pending" ? "pending" : "resolved");
    }, [tab, load]);

    return (
        <InertiaPageShell className="space-y-6">
            <Head title="Solicitudes pendientes" />
            <div>
                <h1 className="text-lg font-semibold">Solicitudes pendientes</h1>
                <p className="text-sm text-muted-foreground">
                    Correos de remitentes sin cuenta reconocida en el tenant — vincula, crea una cuenta o
                    rechaza.
                </p>
            </div>

            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    <TabsTrigger value="pending">Pendientes</TabsTrigger>
                    <TabsTrigger value="resolved">Resueltas</TabsTrigger>
                </TabsList>
                <TabsContent value={tab}>
                    <Card>
                        <CardContent className="pt-6">
                            <RequestsTable items={items} loading={loading} onReview={setReviewing} />
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>

            <ReviewDialog
                request={reviewing}
                open={!!reviewing}
                onOpenChange={(v) => !v && setReviewing(null)}
                onResolved={() => load(tab === "pending" ? "pending" : "resolved")}
            />
        </InertiaPageShell>
    );
}

PendingRequests.layout = (page) => (
    <AuthenticatedLayout title="Solicitudes pendientes">{page}</AuthenticatedLayout>
);
