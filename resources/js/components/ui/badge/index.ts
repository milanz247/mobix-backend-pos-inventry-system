import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-primary/15 bg-primary/10 text-primary dark:bg-primary/20 dark:text-blue-200 [a&]:hover:bg-primary/15",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        destructive:
         "border-destructive/15 bg-destructive/10 text-destructive [a&]:hover:bg-destructive/15 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/20 dark:text-red-300",
        success:
          "border-success/15 bg-success/10 text-success [a&]:hover:bg-success/15 dark:bg-success/15 dark:text-emerald-300",
        warning:
          "border-warning/20 bg-warning/15 text-amber-800 [a&]:hover:bg-warning/20 dark:text-amber-300",
        info:
          "border-info/15 bg-info/10 text-sky-700 [a&]:hover:bg-info/15 dark:text-sky-300",
        outline:
          "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
