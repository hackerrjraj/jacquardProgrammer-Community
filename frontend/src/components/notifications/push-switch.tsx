"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { Alert } from "@/components/ui/alert";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { loadPushState, turnPushOff, turnPushOn, type PushProblem, type PushState } from "@/components/pwa/push-client";

type Note = { tone: "success" | "danger" | "warning"; text: string } | null;

/**
 * "Phone notifications" on/off switch at the top of /dashboard/notifications (Phase 10c-2).
 * Approved real members only (the push API answers 403 to everyone else → hidden).
 * Shows the real state of THIS device: browser subscription + server setting.
 */
export function PushSwitch() {
  const { user } = useAuth();
  const locale = useLocale();
  const t = useDictionary();
  const p = t.notifications.push;
  const [state, setState] = useState<PushState | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<Note>(null);
  const approved = user?.status === "approved";

  useEffect(() => {
    if (!approved) return;
    let cancelled = false;
    void loadPushState(locale).then((next) => {
      if (!cancelled) setState(next);
    });
    return () => {
      cancelled = true;
    };
  }, [approved, locale]);

  if (!approved || state?.kind === "hidden") return null;

  const problemText = (problem: PushProblem) => p[problem];
  const on = state?.kind === "ready" && state.on;
  const problem = state?.kind === "problem" ? state.problem : null;
  const disabled = busy || !state || state.kind === "error" || problem === "unsupported" || problem === "iosInstall";

  const toggle = async () => {
    setBusy(true);
    setNote(null);
    const result = on ? await turnPushOff(locale) : await turnPushOn(locale);
    if (result.ok) {
      setState({ kind: "ready", on: result.on });
      setNote({ tone: "success", text: result.on ? p.turnedOn : p.turnedOff });
    } else if (result.problem) {
      setState({ kind: "problem", problem: result.problem, on: false });
    } else if (!on && typeof Notification !== "undefined" && Notification.permission === "default") {
      setNote({ tone: "warning", text: p.notAllowed });
    } else {
      setNote({ tone: "danger", text: result.message ?? p.failed });
    }
    setBusy(false);
  };

  return (
    <Card className="space-y-3">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-text">
          <Icon name="bell" className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p id="push-switch-label" className="font-semibold text-heading">
            {p.title}
          </p>
          <p className="mt-0.5 text-sm text-muted">{!state ? p.checking : busy ? p.working : p.hint}</p>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={on}
          aria-labelledby="push-switch-label"
          disabled={disabled}
          onClick={() => void toggle()}
          className={`mt-1 inline-flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
            on ? "bg-primary" : "bg-border"
          }`}
        >
          <span className="sr-only">{on ? p.on : p.off}</span>
          <span
            aria-hidden="true"
            className={`size-6 rounded-full bg-white shadow transition-transform ${on ? "translate-x-5" : "translate-x-0"}`}
          />
        </button>
      </div>
      {state?.kind === "error" && <Alert>{p.loadFailed}</Alert>}
      {problem && (
        <Alert tone="warning">
          {problem === "iosInstall" ? (
            <>
              <span className="block">{problemText(problem)}</span>
              <span className="mt-2 flex items-start gap-2">
                <Icon name="download" className="mt-0.5 size-4 shrink-0 text-primary-text" />
                <span>
                  <span className="font-semibold">{t.pwa.iosTitle}</span> — {t.pwa.iosHint}
                </span>
              </span>
            </>
          ) : (
            problemText(problem)
          )}
        </Alert>
      )}
      {note && <Alert tone={note.tone}>{note.text}</Alert>}
    </Card>
  );
}
