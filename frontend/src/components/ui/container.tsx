/** Centers content with the site's max width and side padding. */
export function Container({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={["mx-auto w-full max-w-[1600px] px-4 sm:px-8 lg:px-12", className]
        .filter(Boolean)
        .join(" ")}
      {...props}
    />
  );
}
