"use client";

import { useEffect, useId, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type Option, type ProfileOptions } from "@/lib/api";
import { optionName } from "@/lib/format";

/** Same limit as the backend (other_machine / other_skill). */
export const OTHER_MAX = 100;

type Lists = Pick<ProfileOptions, "machines" | "skills">;

/** Active Machine + Skill items from GET /api/profile-options, with a failed flag and retry (registration form). */
export function useChoiceLists(): { lists: Lists | null; failed: boolean; retry: () => void } {
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState<{ attempt: number; lists: Lists | null } | null>(null);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: ProfileOptions }>("/profile-options").then((res) => {
      if (!cancelled) setResult({ attempt, lists: res.ok ? res.data.data : null });
    });
    return () => {
      cancelled = true;
    };
  }, [attempt]);
  const current = result?.attempt === attempt ? result : null;
  return { lists: current?.lists ?? null, failed: current !== null && current.lists === null, retry: () => setAttempt((a) => a + 1) };
}

/**
 * Pick machines or skills from the admin-managed list (Phase 11d-1): one toggle chip per item.
 * `options` = active items (null while loading). `saved` = items already on the profile — a saved item that is
 * hidden now (not in `options`) is still shown, marked "no longer offered", so the member can keep or remove it.
 */
export function ChoicePicker({
  label,
  hint,
  options,
  saved = [],
  value,
  onChange,
  error,
  loadFailed = false,
  other,
  onOtherChange,
}: {
  label: string;
  hint?: string;
  options: Option[] | null;
  saved?: Option[];
  value: string[];
  onChange: (keys: string[]) => void;
  error?: string;
  loadFailed?: boolean;
  /** "Other" free text: null = not chosen, a string (maybe empty while typing) = chosen. Omit both to hide the chip. */
  other?: string | null;
  onOtherChange?: (text: string | null) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const id = useId();
  const c = t.choicePicker;

  const extra = saved.filter((s) => !options?.some((o) => o.key === s.key));
  const toggle = (key: string) => onChange(value.includes(key) ? value.filter((k) => k !== key) : [...value, key]);

  const otherOn = other !== null && other !== undefined;

  const chip = (option: Option, retired: boolean) => {
    const on = value.includes(option.key);
    return (
      <li key={option.key}>
        <button
          type="button"
          aria-pressed={on}
          onClick={() => toggle(option.key)}
          className={`inline-flex min-h-10 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
            on ? "border-primary bg-primary text-white" : "border-field-border bg-field text-foreground hover:border-primary"
          }`}
        >
          {on && <Icon name="checkCircle" className="size-4 shrink-0" />}
          <span className="break-words">{optionName(option, locale)}</span>
          {retired && <span className={`text-xs ${on ? "text-white/80" : "text-muted"}`}>({c.retired})</span>}
        </button>
      </li>
    );
  };

  return (
    <fieldset aria-describedby={`${id}-hint${error ? ` ${id}-error` : ""}`}>
      <legend className="mb-1.5 block text-sm font-medium text-foreground">{label}</legend>
      {options === null ? (
        <p className={`text-sm ${loadFailed ? "text-danger" : "text-muted"}`}>{loadFailed ? c.loadFailed : c.loading}</p>
      ) : options.length === 0 && extra.length === 0 && !onOtherChange ? (
        <p className="text-sm text-muted">{c.empty}</p>
      ) : (
        <ul className={`flex flex-wrap gap-2 rounded-control border p-2 ${error ? "border-danger" : "border-transparent"}`}>
          {options.map((o) => chip(o, false))}
          {extra.map((o) => chip(o, true))}
          {onOtherChange && (
            <li>
              <button
                type="button"
                aria-pressed={otherOn}
                onClick={() => onOtherChange(otherOn ? null : "")}
                className={`inline-flex min-h-10 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
                  otherOn ? "border-primary bg-primary text-white" : "border-field-border bg-field text-foreground hover:border-primary"
                }`}
              >
                {otherOn && <Icon name="checkCircle" className="size-4 shrink-0" />}
                <span>{c.other}</span>
              </button>
            </li>
          )}
        </ul>
      )}
      {onOtherChange && otherOn && (
        <div className="mt-2">
          <input
            type="text"
            value={other ?? ""}
            maxLength={OTHER_MAX}
            onChange={(e) => onOtherChange(e.target.value)}
            placeholder={c.otherPlaceholder}
            aria-label={`${label}: ${c.other}`}
            className="min-h-11 w-full rounded-control border border-field-border bg-field px-3 py-2 text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          />
        </div>
      )}
      {error ? (
        <p id={`${id}-error`} className="mt-1 text-sm text-danger">
          {error}
        </p>
      ) : null}
      <p id={`${id}-hint`} className="mt-1 text-sm text-muted">
        {hint ?? c.hint}
      </p>
    </fieldset>
  );
}
