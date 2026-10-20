import { useId } from "react";

/** Multi-line text field, styled like Input (label + error). */
export function Textarea({
  label,
  error,
  id,
  className,
  ...props
}: {
  label?: string;
  error?: string;
} & React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const autoId = useId();
  const inputId = id ?? autoId;
  const errorId = `${inputId}-error`;

  return (
    <div className={className}>
      {label && (
        <label
          htmlFor={inputId}
          className="mb-1.5 block text-sm font-medium text-foreground"
        >
          {label}
        </label>
      )}
      <textarea
        id={inputId}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className={`min-h-32 w-full rounded-control border bg-field px-3 py-2.5 text-base text-foreground placeholder:text-muted focus:bg-surface focus:outline-2 focus:outline-offset-0 focus:outline-primary ${error ? "border-danger" : "border-field-border"}`}
        {...props}
      />
      {error && (
        <p id={errorId} className="mt-1 text-sm text-danger">
          {error}
        </p>
      )}
    </div>
  );
}
