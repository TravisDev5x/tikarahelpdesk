import { Button } from "@/components/ui/button";
import { Bold, Code, List } from "lucide-react";

function applyWrap(textarea, before, after, placeholder) {
    const { selectionStart, selectionEnd, value } = textarea;
    const selected = value.slice(selectionStart, selectionEnd) || placeholder;
    const newValue = value.slice(0, selectionStart) + before + selected + after + value.slice(selectionEnd);
    const cursorStart = selectionStart + before.length;
    return { newValue, cursorStart, cursorEnd: cursorStart + selected.length };
}

function applyLinePrefix(textarea, prefix) {
    const { selectionStart, selectionEnd, value } = textarea;
    const lineStart = value.lastIndexOf("\n", selectionStart - 1) + 1;
    const nextBreak = value.indexOf("\n", selectionEnd);
    const lineEnd = nextBreak === -1 ? value.length : nextBreak;
    const segment = value.slice(lineStart, lineEnd);
    const prefixed = segment
        .split("\n")
        .map((line) => (line.startsWith(prefix) ? line : prefix + line))
        .join("\n");
    const newValue = value.slice(0, lineStart) + prefixed + value.slice(lineEnd);
    return { newValue, cursorStart: lineStart, cursorEnd: lineStart + prefixed.length };
}

const ACTIONS = [
    { key: "bold", icon: Bold, label: "Negrita", apply: (t) => applyWrap(t, "**", "**", "texto") },
    { key: "list", icon: List, label: "Lista", apply: (t) => applyLinePrefix(t, "- ") },
    { key: "code", icon: Code, label: "Código", apply: (t) => applyWrap(t, "`", "`", "código") },
];

/**
 * Barra de formato Markdown sobre un Textarea controlado -- sin editor
 * WYSIWYG ni dependencias nuevas, solo inserta sintaxis en el texto.
 */
export function MarkdownToolbar({ textareaRef, onChange, disabled }) {
    const runAction = (apply) => {
        const textarea = textareaRef.current;
        if (!textarea || disabled) return;
        const { newValue, cursorStart, cursorEnd } = apply(textarea);
        onChange(newValue);
        requestAnimationFrame(() => {
            textarea.focus();
            textarea.setSelectionRange(cursorStart, cursorEnd);
        });
    };

    return (
        <div className="flex items-center gap-1 rounded-md border border-border/60 bg-muted/20 p-1">
            {ACTIONS.map(({ key, icon: Icon, label, apply }) => (
                <Button
                    key={key}
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7"
                    title={label}
                    disabled={disabled}
                    onClick={() => runAction(apply)}
                >
                    <Icon className="h-3.5 w-3.5" aria-hidden />
                    <span className="sr-only">{label}</span>
                </Button>
            ))}
        </div>
    );
}
