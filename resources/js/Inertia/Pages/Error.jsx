import { Head, Link } from "@inertiajs/react";
import { AlertTriangle, Ban, Home, ServerCrash, Wrench } from "lucide-react";
import { AuthBrandHomeLink } from "@/components/auth/AuthBrandHomeLink";
import { ThemeToggle } from "@/components/ThemeToggle";
import { Button } from "@/components/ui/button";
import { Reveal } from "@/components/ui/Reveal";
import { authCard, btnBrand, btnBrandOutline, surfaceAuth } from "@/lib/marketingTheme";

const STATUS_MAP = {
    403: {
        title: "No tienes acceso",
        description: "Tu cuenta no cuenta con permiso para ver esta página. Si crees que es un error, contacta a tu administrador.",
        icon: Ban,
    },
    404: {
        title: "Página no encontrada",
        description: "El enlace que seguiste no existe o fue movido. Revisa la dirección o vuelve al inicio.",
        icon: AlertTriangle,
    },
    419: {
        title: "Tu sesión expiró",
        description: "Por seguridad cerramos la sesión tras un período de inactividad. Vuelve a iniciar sesión para continuar.",
        icon: Wrench,
    },
    500: {
        title: "Algo salió mal",
        description: "Tuvimos un problema inesperado de nuestro lado. Ya quedó registrado — intenta de nuevo en unos minutos.",
        icon: ServerCrash,
    },
    503: {
        title: "Servicio no disponible",
        description: "Tikara está en mantenimiento o recibiendo mucho tráfico. Vuelve a intentarlo en unos minutos.",
        icon: Wrench,
    },
};

export default function ErrorPage({ status }) {
    const info = STATUS_MAP[status] ?? STATUS_MAP[500];
    const Icon = info.icon;

    return (
        <>
            <Head title={`Error ${status}`} />
            <div className={`${surfaceAuth} flex min-h-[100dvh] items-center justify-center px-4 py-8`}>
                <Reveal className={`${authCard} w-full text-center`}>
                    <div className="mb-6 flex items-center justify-between">
                        <AuthBrandHomeLink showName markClassName="h-9 w-9" nameClassName="text-lg" />
                        <ThemeToggle variant="icon" />
                    </div>

                    <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-brand/10 text-brand-muted">
                        <Icon className="h-8 w-8" aria-hidden="true" />
                    </div>

                    <p className="mb-1 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        Error {status}
                    </p>
                    <h1 className="mb-2 text-2xl font-bold tracking-tight text-foreground">
                        {info.title}
                    </h1>
                    <p className="mb-8 text-sm leading-relaxed text-muted-foreground">
                        {info.description}
                    </p>

                    <div className="flex flex-col gap-2">
                        <Button asChild className={`h-11 w-full rounded-lg ${btnBrand}`}>
                            <Link href="/">
                                <Home className="mr-2 h-4 w-4" />
                                Volver al inicio
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            className={`h-11 w-full rounded-lg ${btnBrandOutline}`}
                        >
                            <Link href="/login">Ir a iniciar sesión</Link>
                        </Button>
                    </div>
                </Reveal>
            </div>
        </>
    );
}
