export function SectionHeading({
  title,
  subtitle,
  eyebrow,
  align = "center",
  as: Tag = "h2",
}: {
  title: string;
  subtitle?: string;
  /** Small uppercase teal label above the title */
  eyebrow?: string;
  align?: "left" | "center";
  as?: "h1" | "h2" | "h3";
}) {
  const centered = align === "center";
  return (
    <div className={centered ? "mx-auto max-w-2xl text-center" : "max-w-2xl"}>
      {eyebrow && <p className="eyebrow mb-2">{eyebrow}</p>}
      <Tag className="text-2xl font-bold sm:text-3xl">{title}</Tag>
      {subtitle && <p className="mt-3 text-base text-text">{subtitle}</p>}
    </div>
  );
}
