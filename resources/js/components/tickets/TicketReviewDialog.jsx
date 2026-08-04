import { useEffect, useState } from "react";
import axios from "@/lib/axios";
import { notify } from "@/lib/notify";
import { getApiErrorMessage, handleAuthError } from "@/lib/apiErrors";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Loader2, Mail, ThumbsDown, ThumbsUp } from "lucide-react";

/**
 * Aceptar/rechazar un ticket -- acción de triage opcional, no bloquea nada
 * mientras tanto (el ticket ya es trabajable desde que se crea). Rechazar
 * cambia ticket_state_id al estado "Rechazado" + guarda el motivo como
 * nota externa (le llega al solicitante vía TicketReplyNotificationMail,
 * el mismo flujo que cualquier otra respuesta). Aceptar solo deja una nota
 * interna de auditoría. Reutiliza PUT /api/tickets/{ticket} -- sin
 * endpoint nuevo.
 */
export function TicketReviewDialog({ open, onClose, ticket, rejectedStateId, onReviewed }) {
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setRejecting(false);
            setReason("");
        }
    }, [open, ticket?.id]);

    const isEmailSource = ticket?.source === "email";

    const submit = async (payload, successMessage) => {
        setSaving(true);
        try {
            await axios.put(`/api/tickets/${ticket.id}`, payload);
            notify.success(successMessage);
            onReviewed?.();
            onClose();
        } catch (err) {
            if (handleAuthError(err)) return;
            notify.error(getApiErrorMessage(err, "Error al procesar el ticket"));
        } finally {
            setSaving(false);
        }
    };

    const accept = () => {
        submit(
            { note: "Ticket aceptado por revisión.", is_internal: true },
            "Ticket aceptado"
        );
    };

    const confirmReject = () => {
        if (!reason.trim()) return;
        submit(
            { ticket_state_id: rejectedStateId, note: reason.trim(), is_internal: false },
            "Ticket rechazado"
        );
    };

    if (!ticket) return null;

    return (
        <Dialog open={open} onOpenChange={(o) => !saving && !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Revisar ticket #{ticket.folio}</DialogTitle>
                    <DialogDescription>{ticket.subject}</DialogDescription>
                </DialogHeader>

                {isEmailSource && ticket.requester?.email && (
                    <div className="flex items-center gap-2 rounded-lg border border-border/50 bg-muted/20 px-3 py-2 text-sm">
                        <Mail className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
                        <div className="min-w-0">
                            <div className="text-xs text-muted-foreground">Recibido por correo, solicitante:</div>
                            <div className="truncate font-medium">{ticket.requester.email}</div>
                        </div>
                    </div>
                )}

                {rejecting ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="reject-reason">Motivo del rechazo</Label>
                        <Textarea
                            id="reject-reason"
                            autoFocus
                            placeholder="Explica por qué se rechaza -- se le comunica al solicitante."
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            disabled={saving}
                        />
                    </div>
                ) : null}

                <DialogFooter className="gap-2 sm:justify-between">
                    {rejecting ? (
                        <>
                            <Button type="button" variant="ghost" onClick={() => setRejecting(false)} disabled={saving}>
                                Cancelar
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={saving || !reason.trim()}
                                onClick={confirmReject}
                            >
                                {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                                Confirmar rechazo
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                className="text-destructive hover:text-destructive"
                                disabled={saving}
                                onClick={() => setRejecting(true)}
                            >
                                <ThumbsDown className="h-4 w-4 mr-2" />
                                Rechazar
                            </Button>
                            <Button type="button" disabled={saving} onClick={accept}>
                                {saving ? (
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                ) : (
                                    <ThumbsUp className="h-4 w-4 mr-2" />
                                )}
                                Aceptar
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
