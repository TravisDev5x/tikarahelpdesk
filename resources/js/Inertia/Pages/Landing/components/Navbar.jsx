import { useState } from "react";
import { Link } from "@inertiajs/react";
import { Menu } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ThemeToggle } from "@/components/ThemeToggle";
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from "@/components/ui/sheet";
import { brandLogo, btnBrand, btnBrandOutline, navLink } from "@/lib/marketingTheme";

const NAV_LINKS = [
    { href: "#mission", label: "Misión" },
    { href: "#features", label: "En qué creemos" },
    { href: "#how-it-works", label: "Cómo empezar" },
    { href: "#pricing", label: "Planes" },
    { href: "#faq", label: "Preguntas" },
    { href: "#footer", label: "Contacto" },
];

function BrandLogo() {
    return (
        <Link href="/" className="flex items-center gap-2.5 shrink-0">
            <div className={`h-8 w-8 text-[10px] ${brandLogo}`}>TI</div>
            <span className="text-lg font-bold text-foreground tracking-tight">Tikara</span>
        </Link>
    );
}

export default function Navbar() {
    const [open, setOpen] = useState(false);

    return (
        <header className="sticky top-0 z-50 border-b border-border/80 bg-background/90 backdrop-blur-md shadow-sm">
            <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-6 py-4">
                <BrandLogo />

                <nav className="hidden lg:flex items-center gap-8">
                    {NAV_LINKS.map((link) => (
                        <a key={link.href} href={link.href} className={navLink}>
                            {link.label}
                        </a>
                    ))}
                </nav>

                <div className="hidden lg:flex items-center gap-3">
                    <ThemeToggle variant="icon" />
                    <Button variant="ghost" className="text-foreground/90 hover:text-foreground" asChild>
                        <Link href="/login">Iniciar sesión</Link>
                    </Button>
                    <Button className={btnBrand} asChild>
                        <Link href="/register">Crear cuenta</Link>
                    </Button>
                </div>

                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetTrigger asChild className="lg:hidden">
                        <Button variant="ghost" size="icon" className="text-foreground/90">
                            <Menu className="h-5 w-5" />
                            <span className="sr-only">Menú</span>
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="right"
                        className="border-border bg-card text-foreground w-[min(100vw,20rem)]"
                    >
                        <SheetHeader>
                            <SheetTitle className="text-foreground text-left">Menú</SheetTitle>
                        </SheetHeader>
                        <nav className="mt-8 flex flex-col gap-4">
                            {NAV_LINKS.map((link) => (
                                <a
                                    key={link.href}
                                    href={link.href}
                                    onClick={() => setOpen(false)}
                                    className="text-muted-foreground hover:text-brand-muted transition-colors rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                >
                                    {link.label}
                                </a>
                            ))}
                        </nav>
                        <div className="mt-8 flex flex-col gap-3">
                            <div className="flex justify-center pb-2">
                                <ThemeToggle variant="icon" />
                            </div>
                            <Button variant="outline" className={btnBrandOutline} asChild>
                                <Link href="/login" onClick={() => setOpen(false)}>
                                    Iniciar sesión
                                </Link>
                            </Button>
                            <Button className={btnBrand} asChild>
                                <Link href="/register" onClick={() => setOpen(false)}>
                                    Crear cuenta
                                </Link>
                            </Button>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </header>
    );
}
