"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type ReceivingAccounts as Accounts } from "@/lib/api";
import { fill } from "@/lib/auth";

/** Copies text; falls back to the old copy method where the Clipboard API is blocked (older / in-app browsers). */
export async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    // fall through
  }
  const box = document.createElement("textarea");
  box.value = text;
  box.setAttribute("readonly", "");
  box.style.position = "fixed";
  box.style.opacity = "0";
  document.body.appendChild(box);
  box.select();
  const ok = document.execCommand("copy");
  box.remove();
  return ok;
}

/** Small "Copy" button that shows "Copied" (or a failure note) for a moment. */
export function CopyButton({ value, what }: { value: string; what: string }) {
  const t = useDictionary();
  const r = t.receiving;
  const [state, setState] = useState<"idle" | "yes" | "failed">("idle");

  useEffect(() => {
    if (state === "idle") return;
    const id = setTimeout(() => setState("idle"), 2500);
    return () => clearTimeout(id);
  }, [state]);

  return (
    <span className="inline-flex flex-col items-end">
      <button
        type="button"
        aria-label={fill(r.copyLabel, { what })}
        onClick={() => void copyText(value).then((ok) => setState(ok ? "yes" : "failed"))}
        className={`inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-sm font-semibold whitespace-nowrap transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
          state === "yes"
            ? "border-success/40 bg-success-soft text-success"
            : "border-border bg-surface text-secondary hover:border-primary hover:text-primary-text"
        }`}
      >
        <Icon name={state === "yes" ? "checkCircle" : "copy"} className="size-4" />
        {state === "yes" ? r.copied : r.copy}
      </button>
      <span role="status" className="text-xs text-danger">
        {state === "failed" ? r.copyFailed : ""}
      </span>
    </span>
  );
}

/**
 * "Where to send the money" box on the Payments page: the fund's bKash / Nagad / Rocket numbers, bank account and
 * instructions that the Super Admin chose to show (Fund Settings, Phase 7e). Nothing shown → "ask an admin".
 */
export function ReceivingAccounts() {
  const t = useDictionary();
  const locale = useLocale();
  const r = t.receiving;
  const f = t.payments.form;
  const [accounts, setAccounts] = useState<Accounts | null | "failed">(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: Accounts }>("/contributions/accounts").then((res) => {
      if (!cancelled) setAccounts(res.ok ? res.data.data : "failed");
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const data = accounts && accounts !== "failed" ? accounts : null;
  const empty = accounts === "failed" || (data && data.mobile.length === 0 && !data.bank && !data.instructions);
  const instructions = data?.instructions ? (locale === "en" && data.instructions.en) || data.instructions.bn : null;

  return (
    <div className="mt-4 rounded-control border border-primary/30 bg-primary-soft px-4 py-3 text-sm">
      <div className="flex items-center gap-2">
        <Icon name="wallet" className="size-5 shrink-0 text-primary-text" />
        <p className="font-semibold text-heading">{f.receivingTitle}</p>
      </div>

      {accounts === null ? (
        <div aria-busy="true" className="mt-3 space-y-2">
          <p role="status" className="sr-only">
            {r.loading}
          </p>
          <div className="h-14 animate-pulse rounded-control bg-surface/70" />
        </div>
      ) : empty ? (
        <p className="mt-1">{f.receivingPlaceholder}</p>
      ) : (
        data && (
          <div className="mt-3 space-y-3">
            {data.mobile.length > 0 && (
              <ul className="grid gap-2 sm:grid-cols-2">
                {data.mobile.map((a) => (
                  <li key={a.method} className="flex items-start justify-between gap-3 rounded-control border border-border bg-surface px-3 py-2.5">
                    <div className="min-w-0">
                      <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="font-semibold text-heading">{t.payments.methods[a.method]}</span>
                        <span className="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary-text">
                          {r.types[a.type]}
                        </span>
                      </p>
                      <p className="mt-1 font-mono text-lg font-semibold tracking-wide break-all text-foreground" translate="no">
                        {a.number}
                      </p>
                      <p className="text-xs text-muted">{r.typeHints[a.type]}</p>
                    </div>
                    <CopyButton value={a.number} what={t.payments.methods[a.method]} />
                  </li>
                ))}
              </ul>
            )}

            {data.bank && (
              <div className="rounded-control border border-border bg-surface px-3 py-2.5">
                <p className="font-semibold text-heading">{r.bankTitle}</p>
                <dl className="mt-1 grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]">
                  {(
                    [
                      [r.bankName, data.bank.bank_name],
                      [r.accountName, data.bank.account_name],
                      [r.accountNumber, data.bank.account_number],
                      [r.branch, data.bank.branch],
                      [r.routing, data.bank.routing_number],
                    ] as const
                  )
                    .filter(([, v]) => v)
                    .map(([label, value]) => (
                      <div key={label} className="contents">
                        <dt className="text-muted">{label}</dt>
                        <dd className="flex flex-wrap items-center justify-between gap-2 font-medium break-words text-foreground">
                          {label === r.accountNumber ? (
                            <>
                              <span className="font-mono text-base" translate="no">
                                {value}
                              </span>
                              <CopyButton value={(value ?? "").replace(/[\s-]/g, "")} what={r.accountNumber} />
                            </>
                          ) : label === r.routing ? (
                            <span className="font-mono">{value}</span>
                          ) : (
                            value
                          )}
                        </dd>
                      </div>
                    ))}
                </dl>
              </div>
            )}

            {instructions && (
              <div>
                <p className="font-semibold text-heading">{r.instructionsTitle}</p>
                <p className="mt-0.5 whitespace-pre-line">{instructions}</p>
              </div>
            )}
          </div>
        )
      )}
    </div>
  );
}
