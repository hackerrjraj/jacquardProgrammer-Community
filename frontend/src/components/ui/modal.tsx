"use client";

import { useEffect, useId, useRef } from "react";

/**
 * Dialog box on top of the page (native <dialog>: focus trap + Esc built in).
 * Rendered only while open; `onClose` runs on Esc or the backdrop.
 */
export function Modal({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: React.ReactNode;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => {
        if (e.target === ref.current) onClose();
      }}
      className="m-auto w-[calc(100%-2rem)] max-w-md rounded-card border border-border bg-surface p-0 text-text shadow-panel backdrop:bg-navy/60"
    >
      <div className="p-5 sm:p-6">
        <h2 id={titleId} className="text-lg font-semibold">
          {title}
        </h2>
        <div className="mt-3">{children}</div>
      </div>
    </dialog>
  );
}
