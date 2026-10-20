const tones = {
  success: "border-success/30 bg-success-soft text-success",
  danger: "border-danger/30 bg-danger-soft text-danger",
  warning: "border-warning/40 bg-warning-soft text-foreground",
};

/** A coloured message box (success / error / warning). */
export function Alert({
  tone = "danger",
  className,
  ...props
}: { tone?: keyof typeof tones } & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      role={tone === "success" ? "status" : "alert"}
      className={[`rounded-control border px-4 py-3 text-sm`, tones[tone], className]
        .filter(Boolean)
        .join(" ")}
      {...props}
    />
  );
}
