"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Avatar } from "@/components/profile/profile-view";
import { failureText } from "@/components/profile/profile-shared";
import { apiFetch, type FeaturedCandidate, type FeaturedProgrammer, type Role } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

// Featured programmers: admin + super_admin only (same as hero slides). The API checks again.
const ROLES: readonly Role[] = ["admin", "super_admin"];

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: number; ok: true; items: FeaturedProgrammer[] } | { key: number; ok: false };
type Found = { key: string; ok: true; items: FeaturedCandidate[] } | { key: string; ok: false };

/** /dashboard/featured-programmers (Phase 11b-2) */
export function FeaturedProgrammersAdminScreen() {
  return <RequireAuth roles={ROLES}>{() => <FeaturedProgrammersAdmin />}</RequireAuth>;
}

function FeaturedProgrammersAdmin() {
  const t = useDictionary();
  const locale = useLocale();
  const f = t.featuredAdmin;
  const digits = (v: number | string) => localizeDigits(String(v), locale);

  const [reloadKey, setReloadKey] = useState(0);
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [removing, setRemoving] = useState<FeaturedProgrammer | null>(null);
  const [removeError, setRemoveError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: FeaturedProgrammer[] }>("/admin/featured-programmers").then((res) => {
      if (cancelled) return;
      setLoaded(res.ok ? { key: reloadKey, ok: true, items: res.data.data } : { key: reloadKey, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);
  const current = loaded?.key === reloadKey ? loaded : null;
  const items = current?.ok ? current.items : [];
  const shownCount = items.filter((p) => p.shown_on_home).length;
  const setItems = (next: FeaturedProgrammer[]) => setLoaded((prev) => (prev?.ok ? { ...prev, items: next } : prev));

  async function move(from: number, to: number) {
    if (to < 0 || to >= items.length) return;
    const previous = items;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    setItems(next); // show the new order at once
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: FeaturedProgrammer[] }>("/admin/featured-programmers/order", {
      method: "PUT",
      body: { ids: next.map((p) => p.id) },
    });
    setBusy(false);
    if (res.ok) {
      setItems(res.data.data);
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setItems(previous);
      setFlash({ tone: "danger", text: failureText(res, t) });
      // 422 = the list is out of date (e.g. another admin changed it) → load it again.
      if (res.status === 422) setReloadKey((k) => k + 1);
    }
  }

  async function remove(person: FeaturedProgrammer) {
    setBusy(true);
    setRemoveError(null);
    const res = await apiFetch<{ message: string; data: FeaturedProgrammer[] }>(`/admin/featured-programmers/${person.id}`, {
      method: "DELETE",
    });
    setBusy(false);
    if (!res.ok) {
      setRemoveError(failureText(res, t));
      return;
    }
    setRemoving(null);
    setItems(res.data.data);
    setFlash({ tone: "success", text: res.data.message });
  }

  async function add(person: FeaturedCandidate): Promise<boolean> {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: FeaturedProgrammer[] }>("/admin/featured-programmers", {
      method: "POST",
      body: { user_id: person.id },
    });
    setBusy(false);
    if (res.ok) {
      setItems(res.data.data);
      setFlash({ tone: "success", text: res.data.message });
      return true;
    }
    setFlash({ tone: "danger", text: failureText(res, t) });
    return false;
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
        <p className="max-w-3xl">{f.subtitle}</p>
        <ButtonLink href="/" variant="outline" size="sm" target="_blank" rel="noopener">
          <Icon name="eye" className="size-4" />
          {f.viewHome}
        </ButtonLink>
      </div>

      <Card className="p-5 sm:p-6">
        <h2 className="text-lg font-semibold">{f.listTitle}</h2>
        <p role="status" className="mt-1 text-sm text-muted">
          {current?.ok ? fill(f.total, { n: digits(items.length), shown: digits(shownCount) }) : !current ? f.loading : ""}
        </p>

        {flash && (
          <Alert tone={flash.tone} className="mt-4">
            {flash.text}
          </Alert>
        )}

        {!current ? (
          <ul className="mt-5 space-y-3" aria-busy="true">
            {Array.from({ length: 3 }, (_, i) => (
              <li key={i} className="h-20 animate-pulse rounded-card bg-border/60" />
            ))}
          </ul>
        ) : !current.ok ? (
          <div className="mt-5 text-center">
            <p className="text-danger">{f.loadFailed}</p>
            <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
              {f.retry}
            </Button>
          </div>
        ) : items.length === 0 ? (
          <p className="mt-5 text-muted">{f.empty}</p>
        ) : (
          <>
            {items.length > 1 && <p className="mt-4 text-sm text-muted">{f.orderHint}</p>}
            {/* Cards on phones, one row per person from the sm width up */}
            <ol className="mt-4 space-y-3">
              {items.map((person, i) => (
                <li key={person.id} className="flex flex-col gap-3 rounded-card border border-border p-4 sm:flex-row sm:items-center">
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    <span className="w-6 shrink-0 text-center text-sm font-semibold text-muted">{digits(i + 1)}</span>
                    <Avatar
                      name={person.name}
                      url={person.photo_url}
                      className={`size-12 ${person.shown_on_home ? "" : "opacity-60"}`}
                      rounded="rounded-full"
                    />
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold break-words text-heading">{person.name}</p>
                      <p className="text-sm break-words text-muted">
                        {person.designation || f.noDesignation}
                        {person.phone && <> · {digits(person.phone)}</>}
                      </p>
                      {!person.shown_on_home && (
                        <p className="mt-1 inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-heading ring-1 ring-warning/40" title={f.notShownHint}>
                          <Icon name="eyeOff" className="size-3.5" />
                          {f.notShown}
                        </p>
                      )}
                    </div>
                  </div>
                  {!person.shown_on_home && <p className="text-xs text-muted sm:hidden">{f.notShownHint}</p>}
                  <div className="flex flex-wrap justify-end gap-1.5 border-t border-border pt-3 sm:border-0 sm:pt-0">
                    {iconButton(f.moveUp, "arrowUp", () => void move(i, i - 1), { disabled: i === 0 })}
                    {iconButton(f.moveDown, "arrowDown", () => void move(i, i + 1), { disabled: i === items.length - 1 })}
                    <Button
                      variant="danger"
                      size="sm"
                      disabled={busy}
                      onClick={() => {
                        setRemoveError(null);
                        setRemoving(person);
                      }}
                    >
                      <Icon name="trash" className="size-4" />
                      {f.remove}
                    </Button>
                  </div>
                </li>
              ))}
            </ol>
          </>
        )}
      </Card>

      <AddProgrammer busy={busy} featuredCount={items.length} onAdd={add} />

      {removing && (
        <Modal title={f.removeTitle} onClose={() => !busy && setRemoving(null)}>
          <div className="flex items-center gap-3">
            <Avatar name={removing.name} url={removing.photo_url} className="size-12" rounded="rounded-full" />
            <p className="min-w-0 break-words">{fill(f.removeText, { name: removing.name })}</p>
          </div>
          {removeError && (
            <Alert tone="danger" className="mt-4">
              {removeError}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setRemoving(null)}>
              {f.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(removing)}>
              {busy ? f.saving : f.remove}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/** Search box + results: only programmers who can be added (the API filters). */
function AddProgrammer({
  busy,
  featuredCount,
  onAdd,
}: {
  busy: boolean;
  featuredCount: number;
  onAdd: (person: FeaturedCandidate) => Promise<boolean>;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const f = t.featuredAdmin;
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [found, setFound] = useState<Found | null>(null);
  const [added, setAdded] = useState(0);

  // Wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => setQuery(search.trim()), 400);
    return () => clearTimeout(id);
  }, [search]);

  // Search again after every add / remove so the list never offers someone already featured.
  const key = `${query}|${added}|${featuredCount}`;
  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams();
    if (query) params.set("search", query);
    void apiFetch<{ data: FeaturedCandidate[] }>(`/admin/featured-programmers/candidates?${params}`).then((res) => {
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
      <h2 className="text-lg font-semibold">{f.addTitle}</h2>
      <form role="search" onSubmit={(e) => e.preventDefault()} className="mt-4 max-w-xl">
        <Input
          label={f.searchLabel}
          type="search"
          placeholder={f.searchPlaceholder}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          maxLength={100}
        />
        <p className="mt-1.5 text-sm text-muted">{f.searchHint}</p>
      </form>

      {!current ? (
        <p role="status" className="mt-4 text-sm text-muted">
          {f.searching}
        </p>
      ) : !current.ok ? (
        <p className="mt-4 text-danger">{f.searchFailed}</p>
      ) : current.items.length === 0 ? (
        <p className="mt-4 text-muted">{f.noResults}</p>
      ) : (
        <ul className="mt-4 divide-y divide-border rounded-card border border-border">
          {current.items.map((person) => (
            <li key={person.id} className="flex items-center gap-3 p-3">
              <Avatar name={person.name} url={person.photo_url} className="size-10" rounded="rounded-full" />
              <div className="min-w-0 flex-1">
                <p className="font-medium break-words text-heading">{person.name}</p>
                <p className="text-sm break-words text-muted">
                  {person.designation || f.noDesignation}
                  {person.phone && <> · {localizeDigits(person.phone, locale)}</>}
                </p>
              </div>
              <Button
                size="sm"
                variant="outline"
                disabled={busy}
                onClick={() => void onAdd(person).then((ok) => ok && setAdded((n) => n + 1))}
              >
                <Icon name="plus" className="size-4" />
                {f.add}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
