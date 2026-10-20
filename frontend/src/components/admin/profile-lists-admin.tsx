"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import {
  apiFetch,
  type AdminProfileListItem,
  type AdminProfileLists,
  type OtherEntries,
  type ProfileListType,
  type Role,
} from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

// Machine + Skill lists (Phase 11d-1): admin + super_admin; only super_admin deletes (unused items only).
const ROLES: readonly Role[] = ["admin", "super_admin"];
const NAME_MAX = 100;
const TABS: { type: ProfileListType; list: keyof AdminProfileLists }[] = [
  { type: "machine", list: "machines" },
  { type: "skill", list: "skills" },
];

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: string; ok: true; lists: AdminProfileLists } | { key: string; ok: false };
type Editing = { type: ProfileListType; item: AdminProfileListItem | null };

/** /dashboard/lists */
export function ProfileListsAdminScreen() {
  return <RequireAuth roles={ROLES}>{() => <ProfileListsAdmin />}</RequireAuth>;
}

function ProfileListsAdmin() {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.listsAdmin;
  const { user } = useAuth();
  const isSuper = user?.role === "super_admin";
  const digits = (v: number) => localizeDigits(String(v), locale);

  const [tab, setTab] = useState<ProfileListType>("machine");
  const [reloadKey, setReloadKey] = useState(0);
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [flash, setFlash] = useState<Flash>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [deleting, setDeleting] = useState<AdminProfileListItem | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const requestKey = String(reloadKey);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: AdminProfileLists }>("/admin/profile-lists").then((res) => {
      if (cancelled) return;
      setLoaded(res.ok ? { key: requestKey, ok: true, lists: res.data.data } : { key: requestKey, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const listName = TABS.find((x) => x.type === tab)!.list;
  const items = current?.ok ? current.lists[listName] : [];
  const activeCount = items.filter((i) => i.is_active).length;

  const setItems = (next: AdminProfileListItem[]) =>
    setLoaded((prev) => (prev?.ok ? { ...prev, lists: { ...prev.lists, [listName]: next } } : prev));
  const replaceItem = (item: AdminProfileListItem) => setItems(items.map((i) => (i.id === item.id ? item : i)));
  const reload = () => setReloadKey((k) => k + 1);

  async function move(from: number, to: number) {
    if (to < 0 || to >= items.length) return;
    const previous = items;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    setItems(next); // show the new order at once
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminProfileLists }>("/admin/profile-lists/order", {
      method: "PUT",
      body: { type: tab, ids: next.map((i) => i.id) },
    });
    setBusy(false);
    if (res.ok) {
      setLoaded((prev) => (prev?.ok ? { ...prev, lists: res.data.data } : prev));
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setItems(previous);
      setFlash({ tone: "danger", text: failureText(res, t) });
      // 422 = the list is out of date (e.g. another admin added an item) → load it again.
      if (res.status === 422) reload();
    }
  }

  async function toggleActive(item: AdminProfileListItem) {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminProfileListItem }>(`/admin/profile-lists/${item.id}/active`, {
      method: "POST",
      body: { is_active: !item.is_active },
    });
    setBusy(false);
    if (res.ok) {
      replaceItem(res.data.data);
      setFlash({ tone: "success", text: res.data.message });
    } else setFlash({ tone: "danger", text: failureText(res, t) });
  }

  async function remove(item: AdminProfileListItem) {
    setBusy(true);
    setDeleteError(null);
    const res = await apiFetch<{ message: string }>(`/admin/profile-lists/${item.id}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      setDeleteError(failureText(res, t));
      return;
    }
    setDeleting(null);
    setFlash({ tone: "success", text: res.data.message });
    reload();
  }

  const iconButton = (label: string, icon: IconName, onClick: () => void, opts: { danger?: boolean; disabled?: boolean } = {}) => (
    <Button
      variant={opts.danger ? "danger" : "outline"}
      size="sm"
      className="px-2.5"
      aria-label={label}
      title={label}
      disabled={busy || opts.disabled}
      onClick={onClick}
    >
      <Icon name={icon} className="size-4" />
    </Button>
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl">{a.subtitle}</p>
        <Button
          size="sm"
          disabled={!current?.ok}
          onClick={() => {
            setFlash(null);
            setEditing({ type: tab, item: null });
          }}
        >
          <Icon name="plus" className="size-4" />
          {tab === "machine" ? a.addMachine : a.addSkill}
        </Button>
      </div>

      <div role="tablist" aria-label={a.tabsLabel} className="flex gap-2 border-b border-border">
        {TABS.map(({ type, list }) => {
          const selected = tab === type;
          return (
            <button
              key={type}
              type="button"
              role="tab"
              aria-selected={selected}
              onClick={() => {
                setTab(type);
                setFlash(null);
              }}
              className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition-colors ${
                selected ? "border-primary text-primary-text" : "border-transparent text-muted hover:text-heading"
              }`}
            >
              {type === "machine" ? a.tabMachines : a.tabSkills}
              {current?.ok && <span className="ml-1.5 text-xs font-normal">({digits(current.lists[list].length)})</span>}
            </button>
          );
        })}
      </div>

      <Card className="p-5 sm:p-6" role="tabpanel">
        <p role="status" className="text-sm text-muted">
          {current?.ok ? fill(a.total, { n: digits(items.length), active: digits(activeCount) }) : !current ? a.loading : ""}
        </p>

        {flash && (
          <Alert tone={flash.tone} className="mt-4">
            {flash.text}
          </Alert>
        )}

        {!current ? (
          <ul className="mt-5 space-y-3" aria-busy="true">
            {Array.from({ length: 4 }, (_, i) => (
              <li key={i} className="h-16 animate-pulse rounded-card bg-border/60" />
            ))}
          </ul>
        ) : !current.ok ? (
          <div className="mt-5 text-center">
            <p className="text-danger">{a.loadFailed}</p>
            <Button variant="outline" className="mt-4" onClick={reload}>
              {a.retry}
            </Button>
          </div>
        ) : items.length === 0 ? (
          <p className="mt-5 text-muted">{a.empty}</p>
        ) : (
          <>
            <p className="mt-4 text-sm text-muted">
              {items.length > 1 && `${a.orderHint} `}
              {a.hiddenHint}
            </p>
            {!isSuper && <p className="mt-1 text-sm text-muted">{a.deleteOnlySuper}</p>}
            {/* Cards on phones, one row per item from the md width up */}
            <ol className="mt-4 space-y-3">
              {items.map((item, i) => (
                <li key={item.id} className="flex flex-col gap-3 rounded-card border border-border p-4 md:flex-row md:items-center">
                  <div className={`min-w-0 flex-1 ${item.is_active ? "" : "opacity-70"}`}>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-xs font-semibold text-muted">#{digits(i + 1)}</span>
                      {item.is_active ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-semibold text-success">
                          <span className="size-1.5 rounded-full bg-success" />
                          {a.statusShown}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-background px-2.5 py-0.5 text-xs font-semibold text-muted ring-1 ring-border">
                          <Icon name="eyeOff" className="size-3.5" />
                          {a.statusHidden}
                        </span>
                      )}
                      <span className="text-xs text-muted">
                        · {item.used_by > 0 ? fill(a.usedBy, { n: digits(item.used_by) }) : a.unused}
                      </span>
                    </div>
                    <p className="mt-1.5 font-semibold break-words text-heading">{item.name_en}</p>
                    <p className="text-sm break-words text-muted" lang="bn">
                      {item.name_bn}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 md:justify-end md:border-0 md:pt-0">
                    <button
                      type="button"
                      role="switch"
                      aria-checked={item.is_active}
                      aria-label={`${a.activeLabel}: ${item.name_en}`}
                      disabled={busy}
                      onClick={() => void toggleActive(item)}
                      className="inline-flex items-center gap-2 rounded-control text-sm font-medium text-heading disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      <span
                        aria-hidden="true"
                        className={`inline-flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition-colors ${item.is_active ? "bg-primary" : "bg-border"}`}
                      >
                        <span className={`size-6 rounded-full bg-white shadow transition-transform ${item.is_active ? "translate-x-5" : "translate-x-0"}`} />
                      </span>
                      {a.activeLabel}
                    </button>
                    <div className="flex flex-wrap gap-1.5">
                      {iconButton(a.moveUp, "arrowUp", () => void move(i, i - 1), { disabled: i === 0 })}
                      {iconButton(a.moveDown, "arrowDown", () => void move(i, i + 1), { disabled: i === items.length - 1 })}
                      {iconButton(a.edit, "pencil", () => {
                        setFlash(null);
                        setEditing({ type: tab, item });
                      })}
                      {isSuper &&
                        item.used_by === 0 &&
                        iconButton(
                          a.delete,
                          "trash",
                          () => {
                            setDeleteError(null);
                            setDeleting(item);
                          },
                          { danger: true },
                        )}
                    </div>
                  </div>
                </li>
              ))}
            </ol>
          </>
        )}
      </Card>

      <OtherEntriesCard type={tab} reloadKey={reloadKey} onAdded={reload} />

      {editing && (
        <ItemForm
          key={editing.item?.id ?? "new"}
          editing={editing}
          onClose={() => setEditing(null)}
          onSaved={(item, message, created) => {
            setEditing(null);
            setFlash({ tone: "success", text: message });
            if (created) reload();
            else replaceItem(item);
          }}
        />
      )}

      {deleting && (
        <Modal title={a.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <p className="break-words">{fill(a.deleteText, { name: deleting.name_en })}</p>
          {deleteError && (
            <Alert tone="danger" className="mt-4">
              {deleteError}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setDeleting(null)}>
              {a.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? a.saving : a.delete}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/** Add / rename an item: Bangla + English name (both required, ≤ 100 characters). */
function ItemForm({
  editing,
  onClose,
  onSaved,
}: {
  editing: Editing;
  onClose: () => void;
  onSaved: (item: AdminProfileListItem, message: string, created: boolean) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.listsAdmin;
  const { type, item } = editing;
  const [values, setValues] = useState({ name_bn: item?.name_bn ?? "", name_en: item?.name_en ?? "" });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const set = (field: "name_bn" | "name_en") => (e: React.ChangeEvent<HTMLInputElement>) => {
    setValues((v) => ({ ...v, [field]: e.target.value }));
    setErrors((er) => ({ ...er, [field]: "" }));
  };

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    const clean = { name_bn: values.name_bn.trim(), name_en: values.name_en.trim() };
    const found: Record<string, string> = {};
    for (const field of ["name_bn", "name_en"] as const) {
      if (!clean[field]) found[field] = a.required;
      else if (clean[field].length > NAME_MAX) found[field] = fill(a.tooLong, { max: localizeDigits(String(NAME_MAX), locale) });
    }
    setErrors(found);
    if (Object.keys(found).length) return;

    // Edit: send only what changed.
    const body = item
      ? Object.fromEntries(Object.entries(clean).filter(([k, v]) => v !== item[k as "name_bn" | "name_en"]))
      : { type, ...clean };
    if (item && Object.keys(body).length === 0) {
      setError(a.nothingChanged);
      return;
    }

    setBusy(true);
    const res = await apiFetch<{ message: string; data: AdminProfileListItem }>(
      item ? `/admin/profile-lists/${item.id}` : "/admin/profile-lists",
      { method: item ? "PATCH" : "POST", body },
    );
    setBusy(false);
    if (res.ok) {
      onSaved(res.data.data, res.data.message, !item);
      return;
    }
    const server = fieldErrors(res);
    setErrors({ name_bn: server.name_bn ?? "", name_en: server.name_en ?? "" });
    if (!server.name_bn && !server.name_en) setError(failureText(res, t));
  }

  const title = item ? a.editTitle : `${a.createTitle} — ${type === "machine" ? a.tabMachines : a.tabSkills}`;

  return (
    <Modal title={title} onClose={() => !busy && onClose()}>
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        <Input label={a.nameEn} value={values.name_en} onChange={set("name_en")} error={errors.name_en || undefined} maxLength={NAME_MAX} autoFocus />
        <Input label={a.nameBn} lang="bn" value={values.name_bn} onChange={set("name_bn")} error={errors.name_bn || undefined} maxLength={NAME_MAX} />
        {item && <p className="text-sm text-muted">{a.renameNote}</p>}
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="ghost" disabled={busy} onClick={onClose}>
            {a.cancel}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? a.saving : a.save}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

/** "Other" texts members typed (simple list) with an "Add to official list" button for each. */
function OtherEntriesCard({ type, reloadKey, onAdded }: { type: ProfileListType; reloadKey: number; onAdded: () => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.listsAdmin;
  const [others, setOthers] = useState<OtherEntries | null>(null);
  const [busyText, setBusyText] = useState<string | null>(null);
  const [flash, setFlash] = useState<Flash>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: OtherEntries }>("/admin/profile-lists/others").then((res) => {
      if (!cancelled && res.ok) setOthers(res.data.data);
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const entries = others ? others[type === "machine" ? "machines" : "skills"] : [];

  async function add(text: string) {
    setBusyText(text);
    setFlash(null);
    const res = await apiFetch<{ message: string; others: OtherEntries }>("/admin/profile-lists/others/add", {
      method: "POST",
      body: { type, text },
    });
    setBusyText(null);
    if (res.ok) {
      setOthers(res.data.others);
      setFlash({ tone: "success", text: a.othersAdded });
      onAdded();
    } else setFlash({ tone: "danger", text: failureText(res, t) });
  }

  if (!others) return null;
  return (
    <Card className="p-5 sm:p-6">
      <h3 className="text-lg font-semibold">{a.othersTitle}</h3>
      <p className="mt-1 text-sm text-muted">{a.othersHint}</p>
      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}
      {entries.length === 0 ? (
        <p className="mt-4 text-muted">{a.othersNone}</p>
      ) : (
        <ul className="mt-4 space-y-3">
          {entries.map((entry) => (
            <li key={entry.text} className="flex flex-col gap-3 rounded-card border border-border p-4 sm:flex-row sm:items-center">
              <div className="min-w-0 flex-1">
                <p className="font-semibold break-words text-heading">{entry.text}</p>
                <p className="text-sm break-words text-muted">
                  {fill(a.othersMembers, { n: localizeDigits(String(entry.count), locale) })} · {entry.members.join(", ")}
                </p>
              </div>
              <Button size="sm" disabled={busyText !== null} onClick={() => void add(entry.text)}>
                <Icon name="plus" className="size-4" />
                {a.othersAdd}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
