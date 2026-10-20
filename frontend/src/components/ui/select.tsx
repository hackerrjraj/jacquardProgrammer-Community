import { useId } from "react";

/** Dropdown, styled like Input (label + error). */
export function Select({
  label,
  error,
  id,
  className,
  children,
  ...props
}: {
  label?: string;
  error?: string;
} & React.SelectHTMLAttributes<HTMLSelectElement>) {
  const autoId = useId();
  const selectId = id ?? autoId;
  const errorId = `${selectId}-error`;

  return (
    <div className={className}>
      {label && (
        <label
          htmlFor={selectId}
          className="mb-1.5 block text-sm font-medium text-foreground"
        >
          {label}
        </label>
      )}
      <select
        id={selectId}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={`h-11 w-full rounded-control border bg-field px-3 text-base text-foreground focus:bg-surface focus:outline-2 focus:outline-offset-0 focus:outline-primary ${error ? "border-danger" : "border-field-border"}`}
        {...props}
      >
        {children}
      </select>
      {error && (
        <p id={errorId} className="mt-1 text-sm text-danger">
          {error}
        </p>
      )}
    </div>
  );
}
