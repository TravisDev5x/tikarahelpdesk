import * as z from "zod";

// Reusable strong-password policy used across forms
export const strongPasswordSchema = z
    .string()
    .min(12, "Mínimo 12 caracteres")
    .regex(/[a-z]/, "Falta minúscula")
    .regex(/[A-Z]/, "Falta mayúscula")
    .regex(/[0-9]/, "Falta número")
    .regex(/[^A-Za-z0-9]/, "Falta carácter especial");

export const passwordWithConfirmationSchema = z
    .object({
        password: strongPasswordSchema,
        password_confirmation: strongPasswordSchema,
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: "Las contraseñas no coinciden",
        path: ["password_confirmation"],
    });

export const PASSWORD_REQUIREMENT_ITEMS = [
    { key: "length", label: "Mínimo 12 caracteres" },
    { key: "lowercase", label: "Al menos una minúscula" },
    { key: "uppercase", label: "Al menos una mayúscula" },
    { key: "number", label: "Al menos un número" },
    { key: "special", label: "Al menos un carácter especial" },
];

/** Estado visual de requisitos de contraseña (checklist en auth). */
export function getPasswordChecks(password) {
    const value = password ?? "";
    return {
        length: value.length >= 12,
        lowercase: /[a-z]/.test(value),
        uppercase: /[A-Z]/.test(value),
        number: /[0-9]/.test(value),
        special: /[^A-Za-z0-9]/.test(value),
    };
}

export const registerFormSchema = z
    .object({
        first_name: z.string().trim().min(1, "El nombre(s) es obligatorio."),
        paternal_last_name: z.string().trim().min(1, "El apellido paterno es obligatorio."),
        maternal_last_name: z.string().optional(),
        email: z
            .string()
            .trim()
            .min(1, "El correo electrónico es obligatorio.")
            .email("Ingresa un correo válido."),
        phone: z
            .string()
            .optional()
            .refine((value) => !value || value.length === 10, {
                message: "El teléfono debe tener 10 dígitos.",
            }),
        password: strongPasswordSchema,
        password_confirmation: strongPasswordSchema,
        privacy_notice_accepted: z.boolean().refine((value) => value === true, {
            message: "Debes aceptar el aviso de privacidad para continuar.",
        }),
    })
    .refine((data) => data.password === data.password_confirmation, {
        message: "Las contraseñas no coinciden",
        path: ["password_confirmation"],
    });

export const resetPasswordFormSchema = passwordWithConfirmationSchema;
