import { Link } from "@inertiajs/react";
import { ThemeToggle } from "@/components/ThemeToggle";
import { AuthBrandHomeLink } from "@/components/auth/AuthBrandHomeLink";
import { Reveal } from "@/components/ui/Reveal";
import { authCard, linkBrand, surfaceAuth } from "@/lib/marketingTheme";
import { cn } from "@/lib/utils";

/**
 * Shell split auth: panel lateral + columna de formulario (login, registro).
 */
export function AuthSplitLayout({
    tenant = {},
    brandingPanel,
    children,
    formClassName,
    topLink,
}) {
    return (
        <div className={`${surfaceAuth} flex min-h-[100dvh]`}>
            {brandingPanel}

            <div className="flex min-h-[100dvh] flex-1 items-center justify-center bg-muted/20 px-4 py-8 sm:px-8 lg:px-16">
                <Reveal
                    className={cn(
                        authCard,
                        "w-full pb-[max(1rem,env(safe-area-inset-bottom))]",
                        formClassName
                    )}
                >
                    <div className="mb-6 flex items-start justify-between">
                        <AuthBrandHomeLink
                            tenant={tenant}
                            showName
                            markClassName="h-9 w-9"
                            nameClassName="text-lg"
                            className="lg:hidden"
                        />
                        <div className="ml-auto">
                            <ThemeToggle variant="icon" />
                        </div>
                    </div>

                    {topLink ? (
                        <div className="mb-6 flex items-center justify-end text-sm">
                            {topLink.prompt ? (
                                <span className="text-muted-foreground">{topLink.prompt}</span>
                            ) : null}
                            <Link href={topLink.href} className={`${linkBrand} ml-1`}>
                                {topLink.label}
                            </Link>
                        </div>
                    ) : null}

                    {children}
                </Reveal>
            </div>
        </div>
    );
}
