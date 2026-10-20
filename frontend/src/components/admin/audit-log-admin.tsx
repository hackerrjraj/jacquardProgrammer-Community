"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import type { Dictionary, Locale } from "@/i18n/config";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { apiFetch, type AuditLogEntry, type Paginated } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { formatWhen } from "./admin-format";

type Load = { key: string; ok: true; page: Paginated<AuditLogEntry> } | { key: string; ok: false };

/** /dashboard/audit-log — who did what and when (Phase 11a, admin + super_admin, read-only). */
export function AuditLogScreen() {
  return <RequireAuth roles={navItem("audit").roles}>{() => <AuditLog />}</RequireAuth>;
}

/** Readable name of an action; unknown (new) actions show their code. */
function actionLabel(action: string, t: Dictionary): string {
  return (t.auditLog.actions as Record<string, string>)[action] ?? action;
}

function AuditLog() {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.auditLog;
  const [actorInput, setActorInput] = useState("");
  const [actor, setActor] = useState("");
  const [action, setAction] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [load, setLoad] = useState<Load | null>(null);
  const [actions, setActions] = useState<string[]>([]);
  const digits = (n: number) => localizeDigits(String(n), locale);

  useEffect(() => {
    void apiFetch<{ data: string[] }>("/admin/audit-log/actions").then((res) => {
      if (res.ok) setActions(res.data.data);
    });
  }, []);

  // Search: wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => {
      setActor(actorInput.trim());
      setPage(1);
    }, 400);
    return () => clearTimeout(id);
  }, [actorInput]);

  const badRange = Boolean(from && to && to < from);
  const key = `${actor}|${action}|${from}|${to}|${page}|${reloadKey}`;
  useEffect(() => {
    if (badRange) return;
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (actor) params.set("actor", actor);
    if (action) params.set("action", action);
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    void apiFetch<Paginated<AuditLogEntry>>(`/admin/audit-log?${params}`).then((res) => {
      if (!cancelled) setLoad(res.ok ? { key, ok: true, page: res.data } : { key, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [actor, action, from, to, page, badRange, key]);

  const list = load?.ok ? load.page : null;
  const stale = load?.key !== key;
  const filtered = Boolean(actor || action || from || to);
  const set = (setter: (v: string) => void) => (v: string) => {
    setter(v);
    setPage(1);
  };
  const clear = () => {
    setActorInput("");
    setActor("");
    setAction("");
    setFrom("");
    setTo("");
    setPage(1);
  };
  // Options sorted by their readable name in the current language.
  const actionOptions = actions.map((code) => ({ code, label: actionLabel(code, t) })).sort((x, y) => x.label.localeCompare(y.label, locale));

  return (
    <div className="space-y-5">
      <p className="max-w-3xl">
        {a.subtitle}
        <span className="ml-1 inline-flex items-center gap-1 font-semibold text-heading">
          <Icon name="lock" className="size-4" />
          {a.readOnly}
        </span>
      </p>

      <Card className="p-4 sm:p-5">
        <form role="search" onSubmit={(e) => e.preventDefault()} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <Input
            label={a.user}
            type="search"
            placeholder={a.userPlaceholder}
            value={actorInput}
            onChange={(e) => setActorInput(e.target.value)}
            maxLength={100}
          />
          <Select label={a.action} value={action} onChange={(e) => set(setAction)(e.target.value)}>
            <option value="">{a.allActions}</option>
            {actionOptions.map((o) => (
              <option key={o.code} value={o.code}>
                {o.label}
              </option>
            ))}
          </Select>
          <Input label={a.from} type="date" value={from} max={to || undefined} onChange={(e) => set(setFrom)(e.target.value)} />
          <Input
            label={a.to}
            type="date"
            value={to}
            min={from || undefined}
            onChange={(e) => set(setTo)(e.target.value)}
            error={badRange ? a.badRange : undefined}
          />
        </form>
        {filtered && (
          <div className="mt-3 flex justify-end">
            <Button variant="ghost" size="sm" onClick={clear}>
              <Icon name="close" className="size-4" />
              {a.clear}
            </Button>
          </div>
        )}
      </Card>

      {badRange ? null : load && !load.ok && !stale ? (
        <Card className="text-center">
          <p className="text-danger">{a.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {a.retry}
          </Button>
        </Card>
      ) : !list ? (
        <p role="status" className="py-8 text-center text-muted">
          {a.loading}
        </p>
      ) : list.data.length === 0 ? (
        <Card className="text-center text-muted">{filtered ? a.emptyFiltered : a.empty}</Card>
      ) : (
        <div className={`space-y-3 ${stale ? "opacity-60 transition-opacity" : ""}`}>
          <p className="text-sm text-muted">{fill(a.total, { n: digits(list.meta.total) })}</p>

          {/* Desktop: table */}
          <Card className="hidden overflow-x-auto p-0! xl:block">
            <table className="w-full text-left text-sm">
              <thead className="bg-background text-xs font-semibold text-muted">
                <tr>
                  <th scope="col" className="px-4 py-3 whitespace-nowrap">{a.time}</th>
                  <th scope="col" className="px-4 py-3">{a.user}</th>
                  <th scope="col" className="px-4 py-3">{a.action}</th>
                  <th scope="col" className="px-4 py-3">{a.target}</th>
                  <th scope="col" className="px-4 py-3">{a.details}</th>
                  <th scope="col" className="px-4 py-3">{a.ip}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border align-top">
                {list.data.map((e) => (
                  <tr key={e.id}>
                    <td className="px-4 py-3 whitespace-nowrap text-muted">{formatWhen(e.created_at, locale)}</td>
                    <td className="px-4 py-3">
                      <Actor entry={e} t={t} />
                    </td>
                    <td className="px-4 py-3 font-medium text-heading">{actionLabel(e.action, t)}</td>
                    <td className="px-4 py-3">
                      <Target entry={e} t={t} locale={locale} />
                    </td>
                    <td className="max-w-md px-4 py-3">
                      <Details details={e.details} t={t} locale={locale} />
                    </td>
                    <td className="px-4 py-3 text-xs whitespace-nowrap text-muted">{e.ip_address ?? "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          {/* Phones, tablets + small laptops (the sidebar takes room): cards */}
          <ul className="space-y-3 xl:hidden">
            {list.data.map((e) => (
              <li key={e.id}>
                <Card className="space-y-3 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <p className="font-semibold break-words text-heading">{actionLabel(e.action, t)}</p>
                    <span className="text-xs text-muted">{formatWhen(e.created_at, locale)}</span>
                  </div>
                  <dl className="grid gap-2 text-sm">
                    <div>
                      <dt className="text-xs text-muted">{a.user}</dt>
                      <dd>
                        <Actor entry={e} t={t} />
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs text-muted">{a.target}</dt>
                      <dd>
                        <Target entry={e} t={t} locale={locale} />
                      </dd>
                    </div>
                    {e.details && (
                      <div>
                        <dt className="text-xs text-muted">{a.details}</dt>
                        <dd>
                          <Details details={e.details} t={t} locale={locale} />
                        </dd>
                      </div>
                    )}
                    <div>
                      <dt className="text-xs text-muted">{a.ip}</dt>
                      <dd className="text-xs text-muted">{e.ip_address ?? "—"}</dd>
                    </div>
                  </dl>
                </Card>
              </li>
            ))}
          </ul>

          {list.meta.last_page > 1 && (
            <nav className="flex items-center justify-between gap-2 pt-2">
              <Button variant="outline" size="sm" disabled={page <= 1 || stale} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {a.prev}
              </Button>
              <span className="text-sm text-muted">{fill(a.page, { page: digits(page), last: digits(list.meta.last_page) })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page || stale} onClick={() => setPage(page + 1)}>
                {a.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}
    </div>
  );
}

function Actor({ entry, t }: { entry: AuditLogEntry; t: Dictionary }) {
  const locale = useLocale();
  if (!entry.actor) return <span className="text-muted italic">{t.auditLog.system}</span>;
  return (
    <div className="min-w-0">
      <p className="font-medium break-words text-heading">{entry.actor.name}</p>
      <p className="text-xs text-muted">
        {t.roles[entry.actor.role]} · {localizeDigits(entry.actor.phone, locale)}
      </p>
    </div>
  );
}

function Target({ entry, t, locale }: { entry: AuditLogEntry; t: Dictionary; locale: Locale }) {
  if (!entry.target_type) return <span className="text-muted">—</span>;
  const type = (t.auditLog.targets as Record<string, string>)[entry.target_type] ?? entry.target_type;
  const id = entry.target_id !== null ? `#${localizeDigits(String(entry.target_id), locale)}` : "";
  if (entry.target_type === "User" && entry.target_id !== null) {
    return (
      <span className="break-words">
        <span className="text-muted">{type}: </span>
        <Link href={`/dashboard/programmers/${entry.target_id}`} className="font-medium text-primary-text hover:underline">
          {entry.target_name ?? id}
        </Link>
      </span>
    );
  }
  return (
    <span>
      {type} <span className="text-muted">{id}</span>
    </span>
  );
}

/** "2026-10-16T17:44:29+06:00" — shown as a readable date + time. */
const ISO_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;

const isPlainObject = (v: unknown): v is Record<string, unknown> => typeof v === "object" && v !== null && !Array.isArray(v);

/** "first_name" → "First name"; known keys get a bn / en label. */
function fieldLabel(key: string, t: Dictionary): string {
  const known = (t.auditLog.fields as Record<string, string>)[key];
  if (known) return known;
  const words = key.replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/** One value as short text: yes / no, lists joined, "old → new" for changes. */
function valueText(value: unknown, t: Dictionary, locale: Locale): string {
  if (value === null || value === undefined || value === "") return "—";
  if (typeof value === "boolean") return value ? t.auditLog.yes : t.auditLog.no;
  if (typeof value === "string" && ISO_TIME.test(value)) return formatWhen(value, locale);
  if (Array.isArray(value)) return value.length ? value.map((v) => valueText(v, t, locale)).join(", ") : "—";
  if (isPlainObject(value)) {
    if ("from" in value || "to" in value) return `${valueText(value.from, t, locale)} → ${valueText(value.to, t, locale)}`;
    if ("old" in value || "new" in value) return `${valueText(value.old, t, locale)} → ${valueText(value.new, t, locale)}`;
    return Object.entries(value)
      .map(([k, v]) => `${fieldLabel(k, t)}: ${valueText(v, t, locale)}`)
      .join("; ");
  }
  return String(value);
}

/** Details as a short "label: value" list instead of raw JSON; "changes" are shown one field per line. */
function Details({ details, t, locale }: { details: AuditLogEntry["details"]; t: Dictionary; locale: Locale }) {
  if (!details || (Array.isArray(details) && details.length === 0)) return <span className="text-muted">—</span>;
  if (!isPlainObject(details)) return <span className="wrap-anywhere">{valueText(details, t, locale)}</span>;

  const rows: [string, string][] = [];
  for (const [key, value] of Object.entries(details)) {
    if (key === "changes" && isPlainObject(value)) {
      for (const [field, change] of Object.entries(value)) rows.push([fieldLabel(field, t), valueText(change, t, locale)]);
    } else if (key === "fields" && Array.isArray(value)) {
      // Site settings (Phase 11c): only the names of the changed fields are logged.
      rows.push([fieldLabel(key, t), value.map((f) => fieldLabel(String(f), t)).join(", ")]);
    } else rows.push([fieldLabel(key, t), valueText(value, t, locale)]);
  }

  return (
    <dl className="space-y-0.5 text-xs">
      {rows.map(([label, text], i) => (
        <div key={i} className="wrap-anywhere">
          <dt className="inline font-semibold text-muted">{label}: </dt>
          <dd className="inline text-foreground">{text}</dd>
        </div>
      ))}
    </dl>
  );
}
