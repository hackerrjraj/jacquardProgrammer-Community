"use client";

import { useEffect, useId, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Button } from "@/components/ui/button";
import { apiFetch, type RegistrationTerms } from "@/lib/api";

/** The current terms from GET /api/registration-terms. `terms` is null until loaded (or if loading failed). */
export function useRegistrationTerms() {
  const [terms, setTerms] = useState<RegistrationTerms | null>(null);
  const [loaded, setLoaded] = useState(false);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: RegistrationTerms }>("/registration-terms").then((res) => {
      if (cancelled) return;
      if (res.ok) setTerms(res.data.data);
      setLoaded(true);
    });
    return () => {
      cancelled = true;
    };
  }, []);
  return { terms, loaded };
}

/**
 * Terms popup. Accept mode (default): cannot be closed by Esc or by clicking outside — only "Agree" (needs the tick)
 * or "Go back". Read-only mode (`onClose` given): just the text and a Close button.
 */
export function TermsDialog({
  terms,
  onAccept,
  onBack,
  onClose,
}: {
  terms: RegistrationTerms;
  onAccept?: () => void;
  onBack?: () => void;
  onClose?: () => void;
}) {
  const tt = useDictionary().terms;
  const locale = useLocale();
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const [ticked, setTicked] = useState(false);
  const readOnly = Boolean(onClose);

  useEffect(() => {
    const dialog = ref.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  const pick = (bn: string, en: string) => (locale === "bn" ? bn || en : en || bn);
  const heading = pick(terms.heading_bn, terms.heading_en);
  const body = pick(terms.body_bn, terms.body_en);
  const label = pick(terms.checkbox_bn, terms.checkbox_en);

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      // Esc must not close the accept popup; the backdrop is not clickable (no onClick handler).
      onCancel={(e) => {
        e.preventDefault();
        onClose?.();
      }}
      className="m-auto w-[calc(100%-1.5rem)] max-w-2xl rounded-card border border-border bg-surface p-0 text-text shadow-panel backdrop:bg-navy/70"
    >
      <div className="flex max-h-[90dvh] flex-col p-4 sm:p-6">
        <h2 id={titleId} className="text-lg font-semibold sm:text-xl">
          {heading}
        </h2>
        <div
          tabIndex={0}
          className="mt-3 min-h-0 flex-1 overflow-y-auto rounded-control border border-border bg-background px-3 py-3 text-base leading-relaxed wrap-anywhere whitespace-pre-line sm:px-4"
        >
          {body}
        </div>
        {readOnly ? (
          <div className="mt-4 flex justify-end">
            <Button type="button" onClick={onClose}>
              {tt.close}
            </Button>
          </div>
        ) : (
          <>
            <label className="mt-4 flex items-start gap-3 text-base leading-snug">
              <input
                type="checkbox"
                checked={ticked}
                onChange={(e) => setTicked(e.target.checked)}
                className="mt-1 size-5 shrink-0 accent-primary"
              />
              <span>{label}</span>
            </label>
            <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <Button type="button" variant="outline" onClick={onBack}>
                {tt.back}
              </Button>
              <Button type="button" disabled={!ticked} onClick={onAccept}>
                {tt.agree}
              </Button>
            </div>
          </>
        )}
      </div>
    </dialog>
  );
}
