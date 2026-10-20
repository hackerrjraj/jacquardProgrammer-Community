"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { StatusBadge } from "@/components/auth/account-view";
import { useDistricts } from "@/components/auth/use-districts";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch, type Paginated, type Role, type Status, type User } from "@/lib/api";
import { fill, formatDate, isAdminRole } from "@/lib/auth";
import { localizeDigits, optionNames } from "@/lib/format";

const TABS: Status[] = ["pending", "approved", "suspended", "rejected"];
const ROLES: Role[] = ["member", "committee", "admin", "super_admin"];
const PER_PAGE = 20;
const REASON_MAX = 500;

type Dialog =
  | { kind: "reject" | "suspend" | "reset" | "role"; member: User }
  | { kind: "temp"; member: User; password: string };

type Flash = { tone: "success" | "danger"; text: string };

/** Same rule as User::canManage on the backend — hide what the API would refuse. */
function canManage(me: User, target: User): boolean {
  if (me.id === target.id) return false;
  return me.role === "super_admin" || !isAdminRole(target.role);
}

/** /dashboard/members — admin and super_admin only. */
export function MembersAdminScreen() {
  return <RequireAuth admin>{(me) => <MembersAdmin me={me} />}</RequireAuth>;
}

function MembersAdmin({ me }: { me: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const { refresh: refreshAuth } = useAuth();
  const { districts } = useDistricts();
  const m = t.admin;
  const digits = (n: number) => localizeDigits(String(n), locale);

  const [tab, setTab] = useState<Status>("pending");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [list, setList] = useState<Paginated<User> | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [counts, setCounts] = useState<Partial<Record<Status, number>>>({});
  const [flash, setFlash] = useState<Flash | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState<string | null>(null);
  const [role, setRole] = useState<Role>("member");
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [copied, setCopied] = useState<"yes" | "failed" | null>(null);

  // Search: wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 400);
    return () => clearTimeout(id);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ status: tab, page: String(page), per_page: String(PER_PAGE) });
    if (query) params.set("search", query);
    void apiFetch<Paginated<User>>(`/admin/members?${params}`).then((res) => {
      if (cancelled) return;
      if (res.ok) {
        setList(res.data);
        setLoadFailed(false);
      } else {
        if (res.status === 401 || res.status === 403) void refreshAuth();
        setLoadFailed(true);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [tab, query, page, reloadKey, refreshAuth]);

  // Tab counts (all members per status, ignoring the search box).
  useEffect(() => {
    let cancelled = false;
    void Promise.all(
      TABS.map((s) => apiFetch<Paginated<User>>(`/admin/members?status=${s}&per_page=1`)),
    ).then((results) => {
      if (cancelled) return;
      const next: Partial<Record<Status, number>> = {};
      results.forEach((res, i) => {
        if (res.ok) next[TABS[i]] = res.data.meta.total;
      });
      setCounts(next);
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  function open(kind: Exclude<Dialog["kind"], "temp">, member: User) {
    setDialog({ kind, member });
    setReason("");
    setReasonError(null);
    setDialogError(null);
    setRole(member.role);
    setCopied(null);
  }

  /** Runs one admin action; returns the response body on success. */
  async function act(
    member: User,
    action: "approve" | "reject" | "suspend" | "reset-password" | "role",
    body?: unknown,
  ): Promise<{ message: string; temporary_password?: string } | null> {
    setBusy(true);
    setDialogError(null);
    const res = await apiFetch<{ message: string; temporary_password?: string }>(
      `/admin/members/${member.id}/${action}`,
      { method: "POST", locale, body },
    );
    setBusy(false);
    if (res.ok) {
      setReloadKey((k) => k + 1);
      return res.data;
    }
    if (res.status === 401) void refreshAuth();
    const text =
      res.status === 0
        ? t.auth.networkError
        : (res.data?.errors && Object.values(res.data.errors)[0]?.[0]) ||
          res.data?.message ||
          t.auth.genericError;
    if (dialog) setDialogError(text);
    else setFlash({ tone: "danger", text });
    return null;
  }

  async function approve(member: User) {
    setFlash(null);
    const data = await act(member, "approve");
    if (data) setFlash({ tone: "success", text: `${member.name}: ${data.message}` });
  }

  async function confirmDialog() {
    if (!dialog || dialog.kind === "temp") return;
    const { kind, member } = dialog;
    if (kind === "reject") {
      const text = reason.trim();
      if (!text) return setReasonError(t.auth.errors.required);
      if (text.length > REASON_MAX)
        return setReasonError(fill(t.auth.errors.tooLong, { max: digits(REASON_MAX) }));
    }
    const action = kind === "reset" ? "reset-password" : kind;
    const body = kind === "reject" ? { reason: reason.trim() } : kind === "role" ? { role } : undefined;
    const data = await act(member, action, body);
    if (!data) return;
    if (kind === "reset" && data.temporary_password) {
      setDialog({ kind: "temp", member, password: data.temporary_password });
    } else {
      setDialog(null);
      setFlash({ tone: "success", text: `${member.name}: ${data.message}` });
    }
  }

  async function copy(password: string) {
    try {
      await navigator.clipboard.writeText(password);
      setCopied("yes");
      return;
    } catch {
      // Clipboard API blocked (older/in-app browsers) → old copy method below
    }
    const box = document.createElement("textarea");
    box.value = password;
    box.setAttribute("readonly", "");
    box.style.position = "fixed";
    box.style.opacity = "0";
    // Inside the <dialog>, so the modal's focus trap allows selecting it
    (document.querySelector("dialog[open]") ?? document.body).appendChild(box);
    box.select();
    const ok = document.execCommand("copy");
    box.remove();
    setCopied(ok ? "yes" : "failed");
  }

  function closeDialog() {
    if (busy) return;
    // Wipe the temporary password from memory when its box is closed.
    setDialog(null);
  }

  const districtName = (key: string | null) => {
    const d = districts?.find((x) => x.key === key);
    return d ? (locale === "bn" ? d.name_bn : d.name_en) : key;
  };

  return (
    <div>
      <p className="mb-6 max-w-3xl">{m.subtitle}</p>
      <div role="group" aria-label={m.tabsLabel} className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {TABS.map((s) => {
          const active = s === tab;
          return (
            <button
              key={s}
              type="button"
              aria-pressed={active}
              onClick={() => {
                setTab(s);
                setPage(1);
                setFlash(null);
              }}
              className={`rounded-full border px-3 py-2.5 text-sm font-semibold transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {t.statuses[s]}
              {counts[s] !== undefined && ` (${digits(counts[s])})`}
            </button>
          );
        })}
      </div>

      <form role="search" onSubmit={(e) => e.preventDefault()} className="mt-4">
        <Input
          label={m.search}
          type="search"
          placeholder={m.searchPlaceholder}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          maxLength={100}
        />
      </form>

      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {loadFailed ? (
        <Card className="mt-6 text-center">
          <p className="text-danger">{m.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {m.retry}
          </Button>
        </Card>
      ) : !list ? (
        <p role="status" className="mt-6 text-center text-muted">
          {t.auth.loading}
        </p>
      ) : list.data.length === 0 ? (
        <Card className="mt-6 text-center text-muted">{m.empty}</Card>
      ) : (
        <>
          <p className="mt-6 text-sm text-muted">{fill(m.total, { n: digits(list.meta.total) })}</p>
          <ul className="mt-3 grid gap-4 lg:grid-cols-2">
            {list.data.map((member) => {
              const manage = canManage(me, member);
              return (
                <li key={member.id}>
                  <Card className="flex h-full flex-col">
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="mr-auto min-w-0 break-words text-lg font-semibold">{member.name}</h2>
                      {me.id === member.id && (
                        <span className="rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-semibold text-primary-text">
                          {m.you}
                        </span>
                      )}
                      <span className="rounded-full border border-border px-2.5 py-0.5 text-xs font-semibold">
                        {t.roles[member.role]}
                      </span>
                      <StatusBadge status={member.status} />
                    </div>
                    <p className="mt-1 break-words text-sm text-muted">
                      {[member.phone, member.email].filter(Boolean).join(" · ")}
                    </p>
                    <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                      <dt className="text-muted">{m.district}</dt>
                      <dd className="min-w-0 break-words">{districtName(member.district)}</dd>
                      <dt className="text-muted">{m.workplace}</dt>
                      <dd className="min-w-0 break-words">{member.workplace}</dd>
                      <dt className="text-muted">{m.experience}</dt>
                      <dd>
                        {member.experience_years !== null &&
                          fill(t.account.years, { n: digits(member.experience_years) })}
                      </dd>
                      <dt className="text-muted">{m.machines}</dt>
                      <dd className="min-w-0 break-words">{optionNames(member.machines, locale)}</dd>
                      <dt className="text-muted">{m.skills}</dt>
                      <dd className="min-w-0 break-words">{optionNames(member.skills, locale)}</dd>
                      <dt className="text-muted">{m.registered}</dt>
                      <dd>{formatDate(member.created_at, locale)}</dd>
                      {member.rejection_reason && (
                        <>
                          <dt className="text-muted">{m.rejectionReason}</dt>
                          <dd className="min-w-0 break-words text-danger">{member.rejection_reason}</dd>
                        </>
                      )}
                    </dl>

                    {manage && (
                      <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                        {member.status !== "approved" && (
                          <Button size="sm" disabled={busy} onClick={() => void approve(member)}>
                            {member.status === "pending" ? m.approve : m.reApprove}
                          </Button>
                        )}
                        {member.status === "pending" && (
                          <Button size="sm" variant="outline" disabled={busy} onClick={() => open("reject", member)}>
                            {m.reject}
                          </Button>
                        )}
                        {member.status === "approved" && (
                          <Button size="sm" variant="outline" disabled={busy} onClick={() => open("suspend", member)}>
                            {m.suspend}
                          </Button>
                        )}
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => open("reset", member)}>
                          {m.resetPassword}
                        </Button>
                        {me.role === "super_admin" && member.status === "approved" && (
                          <Button size="sm" variant="ghost" disabled={busy} onClick={() => open("role", member)}>
                            {m.changeRole}
                          </Button>
                        )}
                      </div>
                    )}
                  </Card>
                </li>
              );
            })}
          </ul>

          {list.meta.last_page > 1 && (
            <nav className="mt-6 flex items-center justify-between gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                {m.prev}
              </Button>
              <span className="text-sm text-muted">
                {fill(m.page, { page: digits(list.meta.current_page), last: digits(list.meta.last_page) })}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= list.meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                {m.next}
              </Button>
            </nav>
          )}
        </>
      )}

      {dialog && (
        <Modal
          title={
            {
              reject: m.rejectTitle,
              suspend: m.suspendTitle,
              reset: m.resetTitle,
              role: m.roleTitle,
              temp: m.tempTitle,
            }[dialog.kind]
          }
          onClose={closeDialog}
        >
          {dialog.kind === "temp" ? (
            <>
              <p>{fill(m.tempText, { name: dialog.member.name })}</p>
              <p className="mt-3 select-all break-all rounded-control border border-border bg-background px-4 py-3 text-center font-mono text-xl tracking-wider">
                {dialog.password}
              </p>
              <Alert tone="warning" className="mt-3">
                {m.tempWarning}
              </Alert>
              {copied === "failed" && <p className="mt-2 text-sm text-danger">{m.copyFailed}</p>}
              <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button variant="ghost" onClick={closeDialog}>
                  {m.done}
                </Button>
                <Button onClick={() => void copy(dialog.password)}>
                  {copied === "yes" ? `✓ ${m.copied}` : m.copy}
                </Button>
              </div>
            </>
          ) : (
            <>
              <p>
                {fill(
                  { reject: m.rejectText, suspend: m.suspendText, reset: m.resetText, role: m.roleText }[
                    dialog.kind
                  ],
                  { name: dialog.member.name },
                )}
              </p>
              {dialog.kind === "reject" && (
                <Textarea
                  label={m.rejectReason}
                  className="mt-4"
                  rows={3}
                  value={reason}
                  maxLength={REASON_MAX}
                  onChange={(e) => {
                    setReason(e.target.value);
                    setReasonError(null);
                  }}
                  error={reasonError ?? undefined}
                  required
                />
              )}
              {dialog.kind === "role" && (
                <Select
                  label={m.roleLabel}
                  className="mt-4"
                  value={role}
                  onChange={(e) => setRole(e.target.value as Role)}
                >
                  {ROLES.map((r) => (
                    <option key={r} value={r}>
                      {t.roles[r]}
                    </option>
                  ))}
                </Select>
              )}
              {dialogError && <Alert className="mt-4">{dialogError}</Alert>}
              <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button variant="ghost" disabled={busy} onClick={closeDialog}>
                  {m.cancel}
                </Button>
                <Button
                  variant={dialog.kind === "role" ? "primary" : "secondary"}
                  disabled={busy || (dialog.kind === "role" && role === dialog.member.role)}
                  onClick={() => void confirmDialog()}
                >
                  {busy
                    ? m.working
                    : { reject: m.rejectConfirm, suspend: m.suspendConfirm, reset: m.resetConfirm, role: m.roleSave }[
                        dialog.kind
                      ]}
                </Button>
              </div>
            </>
          )}
        </Modal>
      )}
    </div>
  );
}
