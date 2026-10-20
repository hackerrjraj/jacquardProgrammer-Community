export function Card({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={[
        "rounded-card border border-border bg-surface p-5 shadow-card",
        className,
      ]
        .filter(Boolean)
        .join(" ")}
      {...props}
    />
  );
}
