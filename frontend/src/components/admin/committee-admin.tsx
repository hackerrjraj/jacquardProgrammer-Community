"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Avatar } from "@/components/profile/profile-view";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import {
  apiFetch,
  type CommitteeCandidate,
  type CommitteePanel,
  type CommitteePanelMember,
  type CommitteePositionItem,
} from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: number; ok: true; panel: CommitteePanel } | { key: number; ok: false };
type Found = { key: string; ok: true; items: CommitteeCandidate[] } | { key: string; ok: false };
type Result = { message: string } & CommitteePanel;

// Same limits as the backend (CommitteeController).
const POSITION_MAX = 60;
const REASON_MAX = 300;

/** /dashboard/committee — admin + super_admin only (the API checks again). */
export function CommitteeAdminScreen() {
  return <RequireAuth roles={navItem("committee").roles}>{() => <CommitteeAdmin />}</RequireAuth>;
}

function CommitteeAdmin() {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.committeeAdmin;
  const digits = (v: number | string) => localizeDigits(String(v), locale);
  const positionName = (p: { name_en: string; name_bn: string } | null) => (p ? (locale === "bn" && p.name_bn ? p.name_bn : p.name_en) : c.noPosition);
  const dateText = (iso: string) => digits(iso.split("-").reverse().join("/"));

  const [reloadKey, setReloadKey] = useState(0);
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [editing, setEditing] = useState<CommitteePanelMember | null>(null);
  const [adding, setAdding] = useState<CommitteeCandidate | null>(null);
  const [removing, setRemoving] = useState<CommitteePanelMember | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<CommitteePanel>("/admin/committee").then((res) => {
      if (cancelled) return;
      setLoaded(res.ok ? { key: reloadKey, ok: true, panel: res.data } : { key: reloadKey, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);
  const current = loaded?.key === reloadKey ? loaded : null;
  const members = current?.ok ? current.panel.data : [];
  const positions = current?.ok ? current.panel.positions : [];
  const activeCount = members.filter((m) => m.is_active).length;
  const applyPanel = (panel: CommitteePanel) => setLoaded((prev) => (prev?.ok ? { ...prev, panel } : prev));

  async function move(from: number, to: number) {
    if (to < 0 || to >= members.length) return;
    const next = [...members];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    const previous = current?.ok ? current.panel : null;
    if (previous) applyPanel({ ...previous, data: next });
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<Result>("/admin/committee/order", { method: "PUT", body: { ids: next.map((m) => m.id) } });
    setBusy(false);
    if (res.ok) {
      applyPanel(res.data);
      setFlash({ tone: "success", text: res.data.message });
    } else {
      if (previous) applyPanel(previous);
      setFlash({ tone: "danger", text: failureText(res, t) });
      if (res.status === 422) setReloadKey((k) => k + 1);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="max-w-3xl space-y-2">
          <p>{c.subtitle}</p>
          <p className="text-sm text-muted">{c.roleNote}</p>
        </div>
        <ButtonLink href="/about" variant="outline" size="sm" target="_blank" rel="noopener">
          <Icon name="eye" className="size-4" />
          {c.viewAbout}
        </ButtonLink>
      </div>

      <Card className="p-5 sm:p-6">
        <h2 className="text-lg font-semibold">{c.listTitle}</h2>
        <p role="status" className="mt-1 text-sm text-muted">
          {current?.ok ? fill(c.total, { n: digits(members.length), active: digits(activeCount) }) : !current ? c.loading : ""}
        </p>

        {flash && (
          <Alert tone={flash.tone} className="mt-4">
            {flash.text}
          </Alert>
        )}

        {!current ? (
          <ul className="mt-5 space-y-3" aria-busy="true">
            {Array.from({ length: 3 }, (_, i) => (
              <li key={i} className="h-24 animate-pulse rounded-card bg-border/60" />
            ))}
          </ul>
        ) : !current.ok ? (
          <div className="mt-5 text-center">
            <p className="text-danger">{c.loadFailed}</p>
            <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
              {c.retry}
            </Button>
          </div>
        ) : members.length === 0 ? (
          <p className="mt-5 text-muted">{c.empty}</p>
        ) : (
          <>
            {members.length > 1 && <p className="mt-4 text-sm text-muted">{c.orderHint}</p>}
            <ol className="mt-4 space-y-3">
              {members.map((m, i) => (
                <li key={m.id} className="flex flex-col gap-3 rounded-card border border-border p-4 md:flex-row md:items-center">
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    <span className="w-6 shrink-0 text-center text-sm font-semibold text-muted">{digits(i + 1)}</span>
                    <Avatar name={m.name} url={m.photo_url} className={`size-12 ${m.is_active ? "" : "opacity-60"}`} rounded="rounded-full" />
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold break-words text-heading">{m.name}</p>
                      <p className="text-sm break-words text-muted">
                        {positionName(m.position)}
                        {m.phone && <> · {digits(m.phone)}</>}
                      </p>
                      <p className="text-sm break-words text-muted">
                        {c.term}:{" "}
                        {m.term_start && m.term_end
                          ? fill(c.termRange, { from: dateText(m.term_start), to: dateText(m.term_end) })
                          : m.term_start
                            ? fill(c.termFrom, { date: dateText(m.term_start) })
                            : m.term_end
                              ? fill(c.termUntil, { date: dateText(m.term_end) })
                              : c.noTerm}
                      </p>
                      <p className="mt-1 flex flex-wrap gap-1.5">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ${m.is_active ? "bg-success-soft text-heading ring-success/40" : "bg-warning-soft text-heading ring-warning/40"}`}
                        >
                          {m.is_active ? c.active : c.inactive}
                        </span>
                        {m.account_status !== "approved" && (
                          <span className="inline-flex rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-heading ring-1 ring-warning/40">
                            {c.notPublic}
                          </span>
                        )}
                      </p>
                    </div>
                  </div>
                  <div className="flex flex-wrap justify-end gap-1.5 border-t border-border pt-3 md:border-0 md:pt-0">
                    <Button variant="outline" size="sm" className="px-2.5" aria-label={c.moveUp} title={c.moveUp} disabled={busy || i === 0} onClick={() => void move(i, i - 1)}>
                      <Icon name="arrowUp" className="size-4" />
                    </Button>
                    <Button variant="outline" size="sm" className="px-2.5" aria-label={c.moveDown} title={c.moveDown} disabled={busy || i === members.length - 1} onClick={() => void move(i, i + 1)}>
                      <Icon name="arrowDown" className="size-4" />
                    </Button>
                    <Button variant="outline" size="sm" disabled={busy} onClick={() => setEditing(m)}>
                      <Icon name="pencil" className="size-4" />
                      {c.edit}
                    </Button>
                    <Button variant="danger" size="sm" disabled={busy} onClick={() => setRemoving(m)}>
                      <Icon name="trash" className="size-4" />
                      {c.remove}
                    </Button>
                  </div>
                </li>
              ))}
            </ol>
          </>
        )}
      </Card>

      <AddMember members={members.length} onPick={setAdding} />

      <Positions
        positions={positions}
        disabled={!current?.ok}
        onChanged={(res) => {
          applyPanel(res);
          setFlash({ tone: "success", text: res.message });
        }}
      />

      {adding && (
        <MemberModal
          title={c.addModalTitle}
          person={{ name: adding.name, photo_url: adding.photo_url }}
          positions={positions}
          initial={{ position_id: null, term_start: null, term_end: null, is_active: true }}
          showActive={false}
          request={(body) => apiFetch<Result>("/admin/committee", { method: "POST", body: { user_id: adding.id, ...body } })}
          onClose={() => setAdding(null)}
          onSaved={(res) => {
            setAdding(null);
            applyPanel(res);
            setFlash({ tone: "success", text: res.message });
          }}
        />
      )}
      {editing && (
        <MemberModal
          title={c.editModalTitle}
          person={{ name: editing.name, photo_url: editing.photo_url }}
          positions={positions}
          initial={{ position_id: editing.position_id, term_start: editing.term_start, term_end: editing.term_end, is_active: editing.is_active }}
          showActive
          request={(body) => apiFetch<Result>(`/admin/committee/${editing.id}`, { method: "PATCH", body })}
          onClose={() => setEditing(null)}
          onSaved={(res) => {
            setEditing(null);
            applyPanel(res);
            setFlash({ tone: "success", text: res.message });
          }}
        />
      )}
      {removing && (
        <RemoveModal
          member={removing}
          onClose={() => setRemoving(null)}
          onRemoved={(res) => {
            setRemoving(null);
            applyPanel(res);
            setFlash({ tone: "success", text: res.message });
          }}
        />
      )}
    </div>
  );
}

type MemberForm = { position_id: number | null; term_start: string | null; term_end: string | null; is_active: boolean };

/** Add / edit dialog: position, term, active (edit only) and an optional reason for the audit log. */
function MemberModal({
  title,
  person,
  positions,
  initial,
  showActive,
  request,
  onClose,
  onSaved,
}: {
  title: string;
  person: { name: string; photo_url: string | null };
  positions: CommitteePositionItem[];
  initial: MemberForm;
  showActive: boolean;
  request: (body: Record<string, unknown>) => Promise<{ ok: boolean; status: number; data: unknown }>;
  onClose: () => void;
  onSaved: (res: Result) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.committeeAdmin;
  const [form, setForm] = useState<MemberForm>(initial);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setErrors({});
    const res = (await request({
      position_id: form.position_id,
      term_start: form.term_start || null,
      term_end: form.term_end || null,
      ...(showActive ? { is_active: form.is_active } : {}),
      reason: reason.trim() || null,
    })) as Parameters<typeof failureText>[0] & { data: Result };
    setBusy(false);
    if (res.ok) {
      onSaved(res.data);
      return;
    }
    const fields = fieldErrors(res);
    setErrors(fields);
    setError(Object.keys(fields).length ? c.fixErrors : failureText(res, t));
  }

  return (
    <Modal title={title} onClose={() => !busy && onClose()}>
      <form onSubmit={(e) => void submit(e)} className="space-y-4">
        <div className="flex items-center gap-3">
          <Avatar name={person.name} url={person.photo_url} className="size-12" rounded="rounded-full" />
          <p className="min-w-0 font-semibold break-words text-heading">{person.name}</p>
        </div>
        <Select
          label={c.position}
          value={form.position_id ?? ""}
          error={errors.position_id}
          onChange={(e) => setForm({ ...form, position_id: e.target.value ? Number(e.target.value) : null })}
        >
          <option value="">{c.positionNone}</option>
          {positions.map((p) => (
            <option key={p.id} value={p.id}>
              {locale === "bn" && p.name_bn ? p.name_bn : p.name_en}
            </option>
          ))}
        </Select>
        <div className="grid gap-4 sm:grid-cols-2">
          <Input label={c.termStart} type="date" value={form.term_start ?? ""} error={errors.term_start} onChange={(e) => setForm({ ...form, term_start: e.target.value || null })} />
          <Input label={c.termEnd} type="date" value={form.term_end ?? ""} error={errors.term_end} onChange={(e) => setForm({ ...form, term_end: e.target.value || null })} />
        </div>
        {showActive && (
          <label className="flex items-center gap-2.5 text-sm font-medium">
            <input type="checkbox" className="size-5 accent-primary" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            {c.activeLabel}
          </label>
        )}
        <div>
          <Input label={c.reason} value={reason} maxLength={REASON_MAX} error={errors.reason} onChange={(e) => setReason(e.target.value)} />
          <p className="mt-1 text-sm text-muted">{c.reasonHint}</p>
        </div>
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="ghost" disabled={busy} onClick={onClose}>
            {c.cancel}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? c.saving : c.save}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function RemoveModal({ member, onClose, onRemoved }: { member: CommitteePanelMember; onClose: () => void; onRemoved: (res: Result) => void }) {
  const t = useDictionary();
  const c = t.committeeAdmin;
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function remove() {
    setBusy(true);
    setError(null);
    const res = await apiFetch<Result>(`/admin/committee/${member.id}`, { method: "DELETE", body: { reason: reason.trim() || null } });
    setBusy(false);
    if (res.ok) onRemoved(res.data);
    else setError(failureText(res, t));
  }

  return (
    <Modal title={c.removeTitle} onClose={() => !busy && onClose()}>
      <div className="flex items-center gap-3">
        <Avatar name={member.name} url={member.photo_url} className="size-12" rounded="rounded-full" />
        <p className="min-w-0 break-words">{fill(c.removeText, { name: member.name })}</p>
      </div>
      <div className="mt-4">
        <Input label={c.reason} value={reason} maxLength={REASON_MAX} onChange={(e) => setReason(e.target.value)} />
        <p className="mt-1 text-sm text-muted">{c.reasonHint}</p>
      </div>
      {error && (
        <Alert tone="danger" className="mt-4">
          {error}
        </Alert>
      )}
      <div className="mt-5 flex flex-wrap justify-end gap-2">
        <Button variant="ghost" disabled={busy} onClick={onClose}>
          {c.cancel}
        </Button>
        <Button variant="danger" disabled={busy} onClick={() => void remove()}>
          {busy ? c.saving : c.remove}
        </Button>
      </div>
    </Modal>
  );
}

/** Search box + results: only approved members who are not on the list yet (the API filters). */
function AddMember({ members, onPick }: { members: number; onPick: (person: CommitteeCandidate) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.committeeAdmin;
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [found, setFound] = useState<Found | null>(null);

  // Wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => setQuery(search.trim()), 400);
    return () => clearTimeout(id);
  }, [search]);

  // Search again after every add / remove so the list never offers someone already on the committee.
  const key = `${query}|${members}`;
  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams();
    if (query) params.set("search", query);
    void apiFetch<{ data: CommitteeCandidate[] }>(`/admin/committee/candidates?${params}`).then((res) => {
      if (cancelled) return;
      setFound(res.ok ? { key, ok: true, items: res.data.data } : { key, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [query, key]);
  const current = found?.key === key ? found : null;

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{c.addTitle}</h2>
      <form role="search" onSubmit={(e) => e.preventDefault()} className="mt-4 max-w-xl">
        <Input label={c.searchLabel} type="search" placeholder={c.searchPlaceholder} value={search} onChange={(e) => setSearch(e.target.value)} maxLength={100} />
        <p className="mt-1.5 text-sm text-muted">{c.searchHint}</p>
      </form>

      {!current ? (
        <p role="status" className="mt-4 text-sm text-muted">
          {c.searching}
        </p>
      ) : !current.ok ? (
        <p className="mt-4 text-danger">{c.searchFailed}</p>
      ) : current.items.length === 0 ? (
        <p className="mt-4 text-muted">{c.noResults}</p>
      ) : (
        <ul className="mt-4 divide-y divide-border rounded-card border border-border">
          {current.items.map((person) => (
            <li key={person.id} className="flex items-center gap-3 p-3">
              <Avatar name={person.name} url={person.photo_url} className="size-10" rounded="rounded-full" />
              <div className="min-w-0 flex-1">
                <p className="font-medium break-words text-heading">{person.name}</p>
                <p className="text-sm break-words text-muted">
                  {person.designation || c.noDesignation}
                  {person.phone && <> · {localizeDigits(person.phone, locale)}</>}
                </p>
              </div>
              <Button size="sm" variant="outline" onClick={() => onPick(person)}>
                <Icon name="plus" className="size-4" />
                {c.add}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/** The editable position list: add, rename (both languages), delete when nobody holds it. */
function Positions({ positions, disabled, onChanged }: { positions: CommitteePositionItem[]; disabled: boolean; onChanged: (res: Result) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.committeeAdmin;
  const [editing, setEditing] = useState<{ id: number | null; name_en: string; name_bn: string } | null>(null);
  const [deleting, setDeleting] = useState<CommitteePositionItem | null>(null);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  const close = () => {
    setEditing(null);
    setDeleting(null);
    setErrors({});
    setError(null);
  };

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    setBusy(true);
    setError(null);
    setErrors({});
    const body = { name_en: editing.name_en, name_bn: editing.name_bn };
    const res =
      editing.id === null
        ? await apiFetch<Result>("/admin/committee/positions", { method: "POST", body })
        : await apiFetch<Result>(`/admin/committee/positions/${editing.id}`, { method: "PATCH", body });
    setBusy(false);
    if (res.ok) {
      close();
      onChanged(res.data);
      return;
    }
    const fields = fieldErrors(res);
    setErrors(fields);
    setError(Object.keys(fields).length ? c.fixErrors : failureText(res, t));
  }

  async function remove(p: CommitteePositionItem) {
    setBusy(true);
    setError(null);
    const res = await apiFetch<Result>(`/admin/committee/positions/${p.id}`, { method: "DELETE" });
    setBusy(false);
    if (res.ok) {
      close();
      onChanged(res.data);
    } else setError(failureText(res, t));
  }

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-lg font-semibold">{c.positionsTitle}</h2>
          <p className="mt-1 text-sm text-muted">{c.positionsHint}</p>
        </div>
        <Button variant="outline" size="sm" disabled={disabled} onClick={() => setEditing({ id: null, name_en: "", name_bn: "" })}>
          <Icon name="plus" className="size-4" />
          {c.addPosition}
        </Button>
      </div>
      <ul className="mt-4 divide-y divide-border rounded-card border border-border">
        {positions.map((p) => (
          <li key={p.id} className="flex flex-wrap items-center gap-3 p-3">
            <div className="min-w-0 flex-1">
              <p className="font-medium break-words text-heading">{locale === "bn" && p.name_bn ? p.name_bn : p.name_en}</p>
              <p className="text-sm break-words text-muted">
                {locale === "bn" ? p.name_en : p.name_bn} · {fill(c.holders, { n: localizeDigits(String(p.in_use), locale) })}
              </p>
            </div>
            <Button variant="outline" size="sm" onClick={() => setEditing({ id: p.id, name_en: p.name_en, name_bn: p.name_bn })}>
              <Icon name="pencil" className="size-4" />
              {c.edit}
            </Button>
            <Button variant="danger" size="sm" disabled={p.in_use > 0} title={p.in_use > 0 ? c.positionsHint : undefined} onClick={() => setDeleting(p)}>
              <Icon name="trash" className="size-4" />
              {c.delete}
            </Button>
          </li>
        ))}
      </ul>

      {editing && (
        <Modal title={editing.id === null ? c.addPosition : c.edit} onClose={() => !busy && close()}>
          <form onSubmit={(e) => void save(e)} className="space-y-4">
            <Input label={c.positionNameEn} value={editing.name_en} maxLength={POSITION_MAX} error={errors.name_en} onChange={(e) => setEditing({ ...editing, name_en: e.target.value })} />
            <Input label={c.positionNameBn} value={editing.name_bn} maxLength={POSITION_MAX} error={errors.name_bn} onChange={(e) => setEditing({ ...editing, name_bn: e.target.value })} />
            {error && <Alert tone="danger">{error}</Alert>}
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="ghost" disabled={busy} onClick={close}>
                {c.cancel}
              </Button>
              <Button type="submit" disabled={busy}>
                {busy ? c.saving : c.save}
              </Button>
            </div>
          </form>
        </Modal>
      )}
      {deleting && (
        <Modal title={c.deletePositionTitle} onClose={() => !busy && close()}>
          <p className="break-words">{fill(c.deletePositionText, { name: locale === "bn" && deleting.name_bn ? deleting.name_bn : deleting.name_en })}</p>
          {error && (
            <Alert tone="danger" className="mt-4">
              {error}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={close}>
              {c.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? c.saving : c.delete}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}
