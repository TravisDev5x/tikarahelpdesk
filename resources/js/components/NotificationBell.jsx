import { useCallback, useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import axios from "@/lib/axios";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Bell, BellOff } from "lucide-react";

function notificationTitle(n) {
    const d = n.data || {};
    if (d.message) return d.message;
    if (d.subject && d.action) {
        return `${d.action === "created" ? "Creado" : "Actualizado"}: ${d.subject}`;
    }
    if (d.ticket_id) return `Ticket #${d.ticket_id}`;
    return "Notificación";
}

function notificationTime(n) {
    if (!n.created_at) return "";
    const date = new Date(n.created_at);
    const now = new Date();
    const diffMs = now - date;
    if (diffMs < 60000) return "Ahora";
    if (diffMs < 3600000) return `${Math.floor(diffMs / 60000)} min`;
    if (diffMs < 86400000) return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
    return date.toLocaleDateString();
}

export function NotificationBell({
    initialNotifications = [],
    initialUnreadCount = 0,
    onUnreadCountChange,
}) {
    const [open, setOpen] = useState(false);
    const [notifications, setNotifications] = useState(initialNotifications ?? []);
    const [unreadCount, setUnreadCount] = useState(initialUnreadCount ?? 0);

    useEffect(() => {
        if (initialNotifications != null) {
            setNotifications(initialNotifications);
        }
    }, [initialNotifications]);

    useEffect(() => {
        if (initialUnreadCount != null) {
            setUnreadCount(initialUnreadCount);
            onUnreadCountChange?.(initialUnreadCount);
        }
    }, [initialUnreadCount, onUnreadCountChange]);

    const loadNotifs = useCallback(async () => {
        try {
            const { data } = await axios.get("/api/notifications");
            const list = data?.notifications ?? (Array.isArray(data) ? data : []);
            setNotifications(list);
            if (typeof data?.unread_count === "number") {
                setUnreadCount(data.unread_count);
                onUnreadCountChange?.(data.unread_count);
            } else {
                const count = list.filter((n) => !n.read_at).length;
                setUnreadCount(count);
                onUnreadCountChange?.(count);
            }
        } catch {
            // ignore
        }
    }, []);

    useEffect(() => {
        loadNotifs();
        const id = setInterval(loadNotifs, 60000);
        return () => clearInterval(id);
    }, [loadNotifs]);

    useEffect(() => {
        if (open) loadNotifs();
    }, [open, loadNotifs]);

    const markAllRead = async () => {
        try {
            await axios.post("/api/notifications/read-all");
            setNotifications((prev) => prev.map((n) => ({ ...n, read_at: new Date().toISOString() })));
            setUnreadCount(0);
            onUnreadCountChange?.(0);
        } catch {
            // ignore
        }
    };

    const markOneRead = async (id) => {
        try {
            await axios.post(`/api/notifications/${id}/read`);
            setNotifications((prev) =>
                prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n))
            );
            setUnreadCount((c) => {
                const next = Math.max(0, c - 1);
                onUnreadCountChange?.(next);
                return next;
            });
        } catch {
            // ignore
        }
    };

    const renderLink = (href, content, key) => {
        const onClick = () => {
            markOneRead(key);
            setOpen(false);
        };

        return (
            <Link key={key} href={href} onClick={onClick} className="block">
                {content}
            </Link>
        );
    };

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-11 w-11 relative rounded-full flex items-center justify-center hover:bg-muted/50 md:h-9 md:w-9"
                >
                    <Bell className={cn("h-5 w-5", unreadCount > 0 ? "text-foreground" : "text-muted-foreground")} />
                    {unreadCount > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] flex items-center justify-center rounded-full bg-destructive text-[10px] font-bold text-destructive-foreground ring-2 ring-background px-1">
                            {unreadCount > 99 ? "99+" : unreadCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-0 shadow-xl border-border/60">
                <div className="flex items-center justify-between p-4 border-b">
                    <h4 className="text-sm font-semibold">Notificaciones</h4>
                    {unreadCount > 0 && (
                        <Button variant="ghost" size="sm" className="h-auto px-2 text-xs text-primary" onClick={markAllRead}>
                            Marcar leídas
                        </Button>
                    )}
                </div>
                <ScrollArea className="h-[300px]">
                    {notifications.length === 0 ? (
                        <div className="flex flex-col items-center justify-center h-32 text-center p-4">
                            <BellOff className="h-8 w-8 text-muted-foreground/30 mb-2" />
                            <p className="text-xs text-muted-foreground">Sin notificaciones pendientes</p>
                        </div>
                    ) : (
                        <div className="flex flex-col">
                            {notifications.map((n) => {
                                const ticketId = n.data?.ticket_id;
                                const href = n.data?.href || (ticketId ? `/resolbeb/tickets/${ticketId}` : null);
                                const content = (
                                    <div
                                        className={cn(
                                            "flex flex-col gap-1 p-3 border-b border-border/40 hover:bg-muted/30 transition-colors text-left w-full",
                                            !n.read_at && "bg-muted/10 border-l-2 border-l-primary"
                                        )}
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-[10px] text-muted-foreground shrink-0">
                                                {notificationTime(n)}
                                            </span>
                                            {ticketId && (
                                                <span className="text-[10px] font-mono text-muted-foreground">#{ticketId}</span>
                                            )}
                                        </div>
                                        <p className="text-xs text-foreground/90 line-clamp-2">{notificationTitle(n)}</p>
                                    </div>
                                );

                                if (href) {
                                    return renderLink(href, content, n.id);
                                }

                                return (
                                    <div key={n.id} className="cursor-default">
                                        {content}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </ScrollArea>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
