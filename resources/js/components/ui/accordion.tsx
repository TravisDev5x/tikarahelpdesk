import * as React from "react"
import { cn } from "@/lib/utils"

type Item = {
  value: string
  title: React.ReactNode
  content: React.ReactNode
}

type AccordionProps = {
  items: Item[]
  className?: string
  defaultOpen?: string
}

export function AccordionSimple({ items, className, defaultOpen }: AccordionProps) {
  return (
    <div className={cn("space-y-2", className)}>
      {items.map((item) => (
        <details
          key={item.value}
          className="group rounded-lg border border-border/70 bg-card shadow-sm"
          open={item.value === defaultOpen}
        >
          <summary className="flex cursor-pointer items-center justify-between px-3 py-2 text-sm font-semibold text-foreground/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background">
            {item.title}
            <span className="text-xs text-muted-foreground transition-transform duration-200 group-open:rotate-180">
              ▾
            </span>
          </summary>
          <div className="px-3 pb-3 text-sm text-muted-foreground leading-relaxed">
            {item.content}
          </div>
        </details>
      ))}
    </div>
  )
}
