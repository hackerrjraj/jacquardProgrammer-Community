"use client";

import { useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { apiFetch, type Profile, type WorkEntry } from "@/lib/api";
import { fill, toAsciiDigits } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { MIN_YEAR, WORK_HISTORY_MAX, failureText, fieldErrors, thisYear } from "./profile-shared";

type Draft = { company: string; position: string; start_year: string; end_year: string; present: boolean };
type DraftErrors = Partial<Record<"company" | "position" | "start_year" | "end_year", string>>;
type Editing = { index: number | null; draft: Draft } | null; // index null = new entry

const EMPTY: Draft = { company: "", position: "", start_year: "", end_year: "", present: false };

function toDraft(w: WorkEntry): Draft {
  return {
    company: w.company,
    position: w.position,
    start_year: String(w.start_year),
    end_year: w.end_year === "present" ? "" : String(w.end_year),
    present: w.end_year === "present",
  };
}

function toEntry(d: Draft): WorkEntry {
  return {
    company: d.company.trim(),
    position: d.position.trim(),
    start_year: Number(toAsciiDigits(d.start_year.trim())),
    end_year: d.present ? "present" : Number(toAsciiDigits(d.end_year.trim())),
  };
}

/** Work history: add / edit / delete. Each change saves the whole list (PUT /api/profile). */
export function WorkHistoryEditor({ profile, onSaved }: { profile: Profile; onSaved: (p: Profile) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const w = t.profile.work;
  const max = thisYear();
  const digits = (n: number | string) => localizeDigits(String(n), locale);
  const [editing, setEditing] = useState<Editing>(null);
  const [errors, setErrors] = useState<DraftErrors>({});
  const [flash, setFlash] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<number | null>(null);
  const list = profile.work_history;

  function validate(d: Draft): DraftErrors {
    const e: DraftErrors = {};
    if (!d.company.trim()) e.company = t.auth.errors.required;
    else if (d.company.trim().length > 150) e.company = t.auth.errors.tooLong.replace("{max}", digits(150));
    if (!d.position.trim()) e.position = t.auth.errors.required;
    else if (d.position.trim().length > 100) e.position = t.auth.errors.tooLong.replace("{max}", digits(100));
    const start = toAsciiDigits(d.start_year.trim());
    const startOk = /^\d{4}$/.test(start) && Number(start) >= MIN_YEAR && Number(start) <= max;
    if (!startOk) e.start_year = fill(w.startYearError, { min: digits(MIN_YEAR), max: digits(max) });
    if (!d.present) {
      const end = toAsciiDigits(d.end_year.trim());
      if (!/^\d{4}$/.test(end) || Number(end) > max || (startOk && Number(end) < Number(start)))
        e.end_year = fill(w.endYearError, { max: digits(max) });
    }
    return e;
  }

  /** Saves the whole list; `index` = the entry the form was editing (to map server errors). */
  async function save(next: WorkEntry[], index: number | null, successText: string): Promise<boolean> {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: Profile }>("/profile", { method: "PUT", body: { work_history: next } });
    setBusy(false);
    if (res.ok) {
      onSaved(res.data.data);
      setFlash({ tone: "success", text: successText });
      return true;
    }
    const server = fieldErrors(res);
    const pos = index ?? next.length - 1;
    const mine: DraftErrors = {};
    for (const field of ["company", "position", "start_year", "end_year"] as const) {
      const text = server[`work_history.${pos}.${field}`];
      if (text) mine[field] = text;
    }
    setErrors(mine);
    setFlash({ tone: "danger", text: server.work_history ?? (Object.keys(mine).length ? t.auth.fixErrors : failureText(res, t)) });
    return false;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    const found = validate(editing.draft);
    setErrors(found);
    if (Object.keys(found).length) return;
    const entry = toEntry(editing.draft);
    const next =
      editing.index === null ? [...list, entry] : list.map((old, i) => (i === editing.index ? entry : old));
    if (await save(next, editing.index === null ? next.length - 1 : editing.index, w.saved)) setEditing(null);
  }

  async function remove(index: number) {
    if (await save(list.filter((_, i) => i !== index), null, w.deleted)) setDeleting(null);
  }

  const setDraft = (patch: Partial<Draft>) => {
    setEditing((cur) => (cur ? { ...cur, draft: { ...cur.draft, ...patch } } : cur));
    setErrors((er) => ({ ...er, ...Object.fromEntries(Object.keys(patch).map((k) => [k === "present" ? "end_year" : k, undefined])) }));
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-lg font-semibold">{w.title}</h3>
          <p className="mt-1 text-sm text-muted">{fill(w.intro, { max: digits(WORK_HISTORY_MAX) })}</p>
        </div>
        {!editing && (
          <Button
            variant="outline"
            disabled={list.length >= WORK_HISTORY_MAX}
            onClick={() => {
              setFlash(null);
              setErrors({});
              setEditing({ index: null, draft: EMPTY });
            }}
          >
            <Icon name="plus" className="size-4" />
            {w.add}
          </Button>
        )}
      </div>
      {list.length >= WORK_HISTORY_MAX && !editing && (
        <p className="mt-3 text-sm text-muted">{fill(w.max, { max: digits(WORK_HISTORY_MAX) })}</p>
      )}

      {flash && !editing && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {editing && (
        <form onSubmit={submit} noValidate className="mt-5 rounded-card border border-border bg-background p-4 sm:p-5">
          <p className="font-semibold text-heading">{editing.index === null ? w.add : w.edit}</p>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <Input label={w.company} value={editing.draft.company} onChange={(e) => setDraft({ company: e.target.value })} error={errors.company} maxLength={150} />
            <Input label={w.position} value={editing.draft.position} onChange={(e) => setDraft({ position: e.target.value })} error={errors.position} maxLength={100} />
            <Input
              label={w.startYear}
              inputMode="numeric"
              placeholder={digits(max)}
              value={editing.draft.start_year}
              onChange={(e) => setDraft({ start_year: e.target.value })}
              error={errors.start_year}
              maxLength={4}
            />
            <div>
              <Input
                label={w.endYear}
                inputMode="numeric"
                placeholder={digits(max)}
                value={editing.draft.present ? "" : editing.draft.end_year}
                onChange={(e) => setDraft({ end_year: e.target.value })}
                error={errors.end_year}
                maxLength={4}
                disabled={editing.draft.present}
              />
              <label className="mt-2 flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  className="size-4 accent-primary"
                  checked={editing.draft.present}
                  onChange={(e) => setDraft({ present: e.target.checked })}
                />
                {w.present}
              </label>
            </div>
          </div>
          {flash?.tone === "danger" && (
            <Alert tone="danger" className="mt-4">
              {flash.text}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap gap-2">
            <Button type="submit" disabled={busy}>
              {busy ? t.profile.saving : t.profile.save}
            </Button>
            <Button
              variant="ghost"
              disabled={busy}
              onClick={() => {
                setEditing(null);
                setErrors({});
                setFlash(null);
              }}
            >
              {t.profile.cancel}
            </Button>
          </div>
        </form>
      )}

      {list.length === 0 ? (
        !editing && <p className="mt-4 text-muted">{t.profile.noExperience}</p>
      ) : (
        <ul className="mt-5 divide-y divide-border">
          {list.map((entry, i) => (
            <li key={i} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center">
              <div className="min-w-0 flex-1">
                <p className="font-semibold break-words text-heading">{entry.company}</p>
                <p className="text-sm break-words text-muted">
                  {entry.position} · {digits(entry.start_year)} — {entry.end_year === "present" ? t.profile.present : digits(entry.end_year)}
                </p>
              </div>
              <div className="flex shrink-0 gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={busy || editing !== null}
                  onClick={() => {
                    setFlash(null);
                    setErrors({});
                    setEditing({ index: i, draft: toDraft(entry) });
                  }}
                >
                  <Icon name="pencil" className="size-4" />
                  {w.edit}
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  disabled={busy || editing !== null}
                  onClick={() => setDeleting(i)}
                >
                  <Icon name="trash" className="size-4" />
                  {t.profile.delete}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {deleting !== null && list[deleting] && (
        <Modal title={w.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <p>{fill(w.deleteText, { company: list[deleting].company })}</p>
          {flash?.tone === "danger" && (
            <Alert tone="danger" className="mt-4">
              {flash.text}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setDeleting(null)}>
              {t.profile.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? t.profile.saving : t.profile.delete}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}
