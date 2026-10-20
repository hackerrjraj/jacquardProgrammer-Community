import Link from "next/link";

type Variant =
  | "primary"
  | "secondary"
  | "outline"
  | "ghost"
  | "outlineLight"
  | "ghostLight"
  | "danger";
type Size = "sm" | "md" | "lg";

// Pill-shaped buttons (MASTER_SPEC §8a).
const base =
  "inline-flex items-center justify-center gap-2 rounded-full font-semibold whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:pointer-events-none disabled:opacity-50";

const variants: Record<Variant, string> = {
  // Teal gradient
  primary: "bg-brand text-primary-foreground shadow-sm hover:brightness-95 hover:shadow-md",
  // Solid blue
  secondary: "bg-secondary text-secondary-foreground hover:bg-secondary-hover",
  outline:
    "border border-border bg-surface text-secondary hover:border-primary hover:text-primary-text",
  ghost: "text-foreground hover:bg-primary-soft hover:text-primary-text",
  // For dark backgrounds (hero, header, footer)
  outlineLight: "border border-white/40 text-white hover:border-white hover:bg-white/10",
  ghostLight: "text-white/85 hover:bg-white/10 hover:text-white",
  // Delete buttons
  danger: "border border-danger/30 bg-surface text-danger hover:bg-danger-soft",
};

const sizes: Record<Size, string> = {
  sm: "h-9 px-4 text-sm",
  md: "h-10 px-5 text-sm",
  lg: "h-12 px-7 text-base",
};

type StyleProps = { variant?: Variant; size?: Size; className?: string };

function classes({ variant = "primary", size = "md", className }: StyleProps) {
  return [base, variants[variant], sizes[size], className]
    .filter(Boolean)
    .join(" ");
}

export function Button({
  variant,
  size,
  className,
  type = "button",
  ...props
}: StyleProps & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type={type}
      className={classes({ variant, size, className })}
      {...props}
    />
  );
}

/** A link that looks like a Button. */
export function ButtonLink({
  variant,
  size,
  className,
  ...props
}: StyleProps & React.ComponentProps<typeof Link>) {
  return <Link className={classes({ variant, size, className })} {...props} />;
}
