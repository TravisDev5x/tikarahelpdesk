import { useEffect, useRef, useState } from "react";
import { router, usePage } from "@inertiajs/react";
import axios from "@/lib/axios";
import AuthenticatedLayout from "@/Inertia/Layouts/AuthenticatedLayout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Separator } from "@/components/ui/separator";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { notify } from "@/lib/notify";
import { getApiErrorMessage } from "@/lib/apiErrors";
import { strongPasswordSchema } from "@/lib/passwordSchema";
import { Camera, CircleDot, KeyRound, Loader2, Save } from "lucide-react";

function initials(name) {
    const parts = String(name || "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (!parts.length) return "??";
    return (parts[0][0] + (parts[1]?.[0] || "")).toUpperCase();
}

function avatarSrc(user, preview) {
    if (preview) return preview;
    if (user?.avatar_url) return user.avatar_url;
    if (user?.avatar_path) {
        return `/storage/${String(user.avatar_path).replace(/^\/+/, "")}`;
    }
    return null;
}

function validatePassword(form) {
    const errors = {};
    if (!form.current_password) errors.current_password = "Requerido";
    const passResult = strongPasswordSchema.safeParse(form.password);
    if (!passResult.success) {
        errors.password = passResult.error.errors[0]?.message ?? "Contraseña inválida";
    }
    if (form.password !== form.password_confirmation) {
        errors.password_confirmation = "Las contraseñas no coinciden";
    }
    return errors;
}

export default function Profile() {
    const { user: pageUser, auth } = usePage().props;
    const user = pageUser ?? auth?.user;

    const fileInputRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [avatarError, setAvatarError] = useState(false);
    const [profileLoading, setProfileLoading] = useState(false);
    const [passwordLoading, setPasswordLoading] = useState(false);

    const [profileForm, setProfileForm] = useState({
        first_name: user?.first_name ?? "",
        paternal_last_name: user?.paternal_last_name ?? "",
        maternal_last_name: user?.maternal_last_name ?? "",
        email: user?.email ?? "",
        phone: user?.phone ?? "",
        availability: user?.availability ?? "disconnected",
    });
    const [passwordForm, setPasswordForm] = useState({
        current_password: "",
        password: "",
        password_confirmation: "",
    });
    const [passwordErrors, setPasswordErrors] = useState({});

    useEffect(() => {
        if (!user) return;
        setProfileForm({
            first_name: user.first_name ?? "",
            paternal_last_name: user.paternal_last_name ?? "",
            maternal_last_name: user.maternal_last_name ?? "",
            email: user.email ?? "",
            phone: user.phone ?? "",
            availability: user.availability ?? "disconnected",
        });
    }, [user]);

    useEffect(() => {
        return () => {
            if (preview) URL.revokeObjectURL(preview);
        };
    }, [preview]);

    const handleFileChange = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            notify.error("La imagen es demasiado pesada. Máximo 5 MB.");
            e.target.value = "";
            return;
        }
        setPreview(URL.createObjectURL(file));
        setAvatarError(false);
    };

    const saveProfile = async (e) => {
        e.preventDefault();
        if (!profileForm.first_name.trim() || !profileForm.paternal_last_name.trim()) {
            notify.error("Nombre y apellido paterno son requeridos");
            return;
        }
        setProfileLoading(true);
        try {
            const formData = new FormData();
            formData.append("first_name", profileForm.first_name.trim());
            formData.append("paternal_last_name", profileForm.paternal_last_name.trim());
            formData.append("maternal_last_name", profileForm.maternal_last_name?.trim() ?? "");
            formData.append("phone", profileForm.phone?.trim() ?? "");
            formData.append("email", profileForm.email?.trim() ?? "");
            formData.append("availability", profileForm.availability ?? "disconnected");
            if (fileInputRef.current?.files?.[0]) {
                formData.append("avatar", fileInputRef.current.files[0]);
            }
            await axios.post("/api/profile", formData);
            notify.success("Perfil actualizado correctamente");
            setPreview(null);
            if (fileInputRef.current) fileInputRef.current.value = "";
            router.reload({ only: ["user"] });
        } catch (err) {
            notify.error(getApiErrorMessage(err, "Error al actualizar perfil"));
        } finally {
            setProfileLoading(false);
        }
    };

    const savePassword = async (e) => {
        e.preventDefault();
        const errors = validatePassword(passwordForm);
        setPasswordErrors(errors);
        if (Object.keys(errors).length) return;

        setPasswordLoading(true);
        try {
            await axios.put("/api/profile/password", passwordForm);
            notify.success("Contraseña actualizada");
            setPasswordForm({
                current_password: "",
                password: "",
                password_confirmation: "",
            });
            setPasswordErrors({});
        } catch (err) {
            const apiErrors = err.response?.data?.errors;
            if (apiErrors) {
                const mapped = {};
                Object.entries(apiErrors).forEach(([k, v]) => {
                    mapped[k] = Array.isArray(v) ? v[0] : v;
                });
                setPasswordErrors(mapped);
            } else {
                notify.error(getApiErrorMessage(err, "Error al cambiar contraseña"));
            }
        } finally {
            setPasswordLoading(false);
        }
    };

    const displayName =
        [user?.first_name, user?.paternal_last_name].filter(Boolean).join(" ") ||
        user?.name ||
        "Usuario";

    return (
        <div className="max-w-4xl mx-auto space-y-8 animate-in fade-in pb-content-mobile">
            <div className="grid gap-8 md:grid-cols-2">
                <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm">
                    <CardHeader>
                        <CardTitle className="uppercase font-bold text-sm">
                            INFORMACIÓN PERSONAL
                        </CardTitle>
                        <CardDescription>Actualiza tu foto y datos de contacto.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={saveProfile} className="space-y-6">
                            <div className="flex flex-col items-center gap-4 py-4">
                                <div
                                    className="relative group cursor-pointer"
                                    onClick={() => fileInputRef.current?.click()}
                                    onKeyDown={(e) => {
                                        if (e.key === "Enter" || e.key === " ") {
                                            fileInputRef.current?.click();
                                        }
                                    }}
                                    role="button"
                                    tabIndex={0}
                                >
                                    <Avatar className="h-24 w-24 border-2 border-primary/20 group-hover:border-primary transition-colors">
                                        {avatarSrc(user, preview) && !avatarError && (
                                            <AvatarImage
                                                src={avatarSrc(user, preview)}
                                                alt={displayName}
                                                className="object-cover"
                                                onError={() => setAvatarError(true)}
                                            />
                                        )}
                                        <AvatarFallback className="text-2xl font-black bg-muted">
                                            {initials(displayName)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="absolute inset-0 bg-black/40 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                        <Camera className="text-white h-6 w-6" />
                                    </div>
                                </div>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={handleFileChange}
                                />
                                <p className="text-[10px] uppercase font-bold text-muted-foreground">
                                    Click para cambiar foto
                                </p>
                            </div>

                            <div className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="first_name" className="text-[10px] font-black uppercase">
                                        Nombre(s)
                                    </Label>
                                    <Input
                                        id="first_name"
                                        className="bg-muted/20"
                                        value={profileForm.first_name}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({ ...p, first_name: e.target.value }))
                                        }
                                        required
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="paternal_last_name"
                                        className="text-[10px] font-black uppercase"
                                    >
                                        Apellido paterno
                                    </Label>
                                    <Input
                                        id="paternal_last_name"
                                        className="bg-muted/20"
                                        value={profileForm.paternal_last_name}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({
                                                ...p,
                                                paternal_last_name: e.target.value,
                                            }))
                                        }
                                        required
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="maternal_last_name"
                                        className="text-[10px] font-black uppercase"
                                    >
                                        Apellido materno (opcional)
                                    </Label>
                                    <Input
                                        id="maternal_last_name"
                                        className="bg-muted/20"
                                        value={profileForm.maternal_last_name}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({
                                                ...p,
                                                maternal_last_name: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="email" className="text-[10px] font-black uppercase">
                                        Correo electrónico
                                    </Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        className="bg-muted/20"
                                        value={profileForm.email}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({ ...p, email: e.target.value }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="phone" className="text-[10px] font-black uppercase">
                                        Teléfono (opcional)
                                    </Label>
                                    <Input
                                        id="phone"
                                        className="bg-muted/20"
                                        value={profileForm.phone}
                                        onChange={(e) =>
                                            setProfileForm((p) => ({ ...p, phone: e.target.value }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="flex items-center gap-1.5 text-[10px] font-black uppercase">
                                        <CircleDot className="h-3.5 w-3.5" />
                                        Disponibilidad
                                    </Label>
                                    <Select
                                        value={profileForm.availability}
                                        onValueChange={(v) =>
                                            setProfileForm((p) => ({ ...p, availability: v }))
                                        }
                                    >
                                        <SelectTrigger className="bg-muted/20">
                                            <SelectValue placeholder="Seleccionar estado" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="available">Disponible</SelectItem>
                                            <SelectItem value="busy">Ocupado</SelectItem>
                                            <SelectItem value="disconnected">Desconectado</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <Button
                                type="submit"
                                disabled={profileLoading}
                                className="w-full font-black uppercase tracking-widest text-xs"
                            >
                                {profileLoading && (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                )}
                                <Save className="mr-2 h-4 w-4" />
                                Guardar cambios
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card className="border-border/60 bg-card/10 backdrop-blur-sm shadow-sm h-fit">
                    <CardHeader>
                        <CardTitle className="uppercase font-bold text-sm text-destructive flex items-center gap-2">
                            <KeyRound className="h-4 w-4" /> Seguridad
                        </CardTitle>
                        <CardDescription>CAMBIAR CONTRASEÑA</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={savePassword} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="current_password"
                                    className="text-[10px] font-black uppercase"
                                >
                                    Contraseña actual
                                </Label>
                                <Input
                                    id="current_password"
                                    type="password"
                                    className="bg-muted/20"
                                    value={passwordForm.current_password}
                                    onChange={(e) =>
                                        setPasswordForm((p) => ({
                                            ...p,
                                            current_password: e.target.value,
                                        }))
                                    }
                                />
                                {passwordErrors.current_password && (
                                    <p className="text-xs text-destructive">
                                        {passwordErrors.current_password}
                                    </p>
                                )}
                            </div>
                            <Separator className="my-2 bg-border/40" />
                            <div className="space-y-1.5">
                                <Label htmlFor="password" className="text-[10px] font-black uppercase">
                                    Nueva contraseña
                                </Label>
                                <Input
                                    id="password"
                                    type="password"
                                    className="bg-muted/20"
                                    value={passwordForm.password}
                                    onChange={(e) =>
                                        setPasswordForm((p) => ({ ...p, password: e.target.value }))
                                    }
                                />
                                {passwordErrors.password && (
                                    <p className="text-xs text-destructive">{passwordErrors.password}</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="password_confirmation"
                                    className="text-[10px] font-black uppercase"
                                >
                                    Confirmar nueva contraseña
                                </Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    className="bg-muted/20"
                                    value={passwordForm.password_confirmation}
                                    onChange={(e) =>
                                        setPasswordForm((p) => ({
                                            ...p,
                                            password_confirmation: e.target.value,
                                        }))
                                    }
                                />
                                {passwordErrors.password_confirmation && (
                                    <p className="text-xs text-destructive">
                                        {passwordErrors.password_confirmation}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={passwordLoading}
                                className="w-full font-black uppercase tracking-widest text-xs mt-4"
                            >
                                {passwordLoading && (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                )}
                                Actualizar contraseña
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

Profile.layout = (page) => <AuthenticatedLayout title="Mi perfil">{page}</AuthenticatedLayout>;
