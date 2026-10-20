"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { failureText } from "@/components/profile/profile-shared";
import { apiFetch, type AdminExpertiseCard, type Role } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { ExpertiseForm } from "./expertise-form";
import { EXPERTISE_MAX_CARDS, ExpertisePreview, expertiseTitle, homeIcon } from "./expertise-shared";

// Expertise cards management: admin + super_admin only (same as the gallery / notices).
const ROLES: readonly Role[] = ["admin", "super_admin"];

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: string; ok: true; items: AdminExpertiseCard[] } | { key: string; ok: false };
type View = { mode: "list" } | { mode: "form"; card: AdminExpertiseCard | null };

/** /dashboard/expertise-cards */
export function ExpertiseAdminScreen() {
  return <RequireAuth roles={ROLES}>{() => <ExpertiseAdmin />}</RequireAuth>;
}

function ExpertiseAdmin() {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.expertiseAdmin;
  const [view, setView] = useState<View>({ mode: "list" });
  const [flash, setFlash] = useState<Flash>(null);
  const [reload, setReload] = useState(0);
  const [count, setCount] = useState(0);
  const full = count >= EXPERTISE_MAX_CARDS;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl">{a.subtitle}</p>
        <div className="flex flex-wrap gap-2">
          <ButtonLink href="/" variant="outline" size="sm" target="_blank" rel="noopener">
            <Icon name="eye" className="size-4" />
            {a.viewHome}
          </ButtonLink>
          {view.mode === "list" && (
            <Button
              size="sm"
              disabled={full}
              onClick={() => {
                setFlash(null);
                setView({ mode: "form", card: null });
              }}
            >
              <Icon name="plus" className="size-4" />
              {a.new}
            </Button>
          )}
        </div>
      </div>

      {view.mode === "list" && full && (
        <p className="text-sm text-muted">{fill(a.limitReached, { max: localizeDigits(String(EXPERTISE_MAX_CARDS), locale) })}</p>
      )}

      {view.mode === "form" ? (
        <ExpertiseForm
          key={view.card?.id ?? "new"}
          card={view.card}
          onCancel={() => setView({ mode: "list" })}
          onDone={(message) => {
            setFlash({ tone: "success", text: message });
            setReload((k) => k + 1);
            setView({ mode: "list" });
          }}
        />
      ) : (
        <ExpertiseList reloadSignal={reload} flash={flash} setFlash={setFlash} onCount={setCount} onEdit={(card) => {
          setFlash(null);
          setView({ mode: "form", card });
        }} />
      )}
    </div>
  );
}

function ExpertiseList({
  reloadSignal,
  flash,
  setFlash,
  onCount,
  onEdit,
}: {
  reloadSignal: number;
  flash: Flash;
  setFlash: (f: Flash) => void;
  onCount: (n: number) => void;
  onEdit: (card: AdminExpertiseCard) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.expertiseAdmin;
  const digits = (v: number) => localizeDigits(String(v), locale);

  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${reloadKey}|${reloadSignal}`;
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<AdminExpertiseCard | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: AdminExpertiseCard[] }>("/admin/expertise-cards").then((res) => {
      if (cancelled) return;
      setLoaded(res.ok ? { key: requestKey, ok: true, items: res.data.data } : { key: requestKey, ok: false });
      if (res.ok) onCount(res.data.data.length);
    });
    return () => {
      cancelled = true;
    };
  }, [requestKey, onCount]);
  const current = loaded?.key === requestKey ? loaded : null;
  const items = current?.ok ? current.items : [];

  const setItems = (next: AdminExpertiseCard[]) => setLoaded((prev) => (prev?.ok ? { ...prev, items: next } : prev));

  async function move(from: number, to: number) {
    if (to < 0 || to >= items.length) return;
    const previous = items;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    setItems(next); // show the new order at once
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminExpertiseCard[] }>("/admin/expertise-cards/order", {
      method: "PUT",
      body: { ids: next.map((c) => c.id) },
    });
    setBusy(false);
    if (res.ok) {
      setItems(res.data.data);
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setItems(previous);
      setFlash({ tone: "danger", text: failureText(res, t) });
      // 422 = the list is out of date (e.g. another admin added a card) → load it again.
      if (res.status === 422) setReloadKey((k) => k + 1);
    }
  }

  async function togglePublish(card: AdminExpertiseCard) {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminExpertiseCard }>(
      `/admin/expertise-cards/${card.id}/${card.is_published ? "unpublish" : "publish"}`,
      { method: "POST" },
    );
    setBusy(false);
    if (res.ok) {
      setItems(items.map((c) => (c.id === card.id ? res.data.data : c)));
      setFlash({ tone: "success", text: res.data.message });
    } else setFlash({ tone: "danger", text: failureText(res, t) });
  }

  async function remove(card: AdminExpertiseCard) {
    setBusy(true);
    setDeleteError(null);
    const res = await apiFetch<{ message: string }>(`/admin/expertise-cards/${card.id}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      setDeleteError(failureText(res, t));
      return;
    }
    setDeleting(null);
    setFlash({ tone: "success", text: res.data.message });
    setReloadKey((k) => k + 1);
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

  const statusBadge = (card: AdminExpertiseCard) =>
    card.is_published ? (
      <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-semibold text-success">
        <span className="size-1.5 rounded-full bg-success" />
        {a.statusShown}
      </span>
    ) : (
      <span className="inline-flex items-center gap-1 rounded-full bg-background px-2.5 py-0.5 text-xs font-semibold text-muted ring-1 ring-border">
        <Icon name="eyeOff" className="size-3.5" />
        {a.statusHidden}
      </span>
    );

  return (
    <Card className="p-5 sm:p-6">
      <div>
        <h2 className="text-lg font-semibold">{a.listTitle}</h2>
        <p role="status" className="mt-1 text-sm text-muted">
          {current?.ok ? fill(a.total, { n: digits(items.length), max: digits(EXPERTISE_MAX_CARDS) }) : !current ? a.loading : ""}
        </p>
      </div>

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
          <p className="text-danger">{a.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {a.retry}
          </Button>
        </div>
      ) : items.length === 0 ? (
        <p className="mt-5 text-muted">{a.empty}</p>
      ) : (
        <>
          {items.length > 1 && <p className="mt-4 text-sm text-muted">{a.orderHint}</p>}
          <ol className="mt-4 divide-y divide-border rounded-card border border-border">
            {items.map((card, i) => (
              <li key={card.id} className="flex flex-col gap-3 p-4 md:flex-row md:items-center">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                  <div className={card.is_published ? "" : "opacity-60"}>
                    <ExpertisePreview imageUrl={card.image_url} icon={homeIcon(card.icon)} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="text-xs font-semibold text-muted">#{digits(i + 1)}</span>
                      {statusBadge(card)}
                      {card.image_url && (
                        <span className="inline-flex items-center text-muted" title={a.hasPicture}>
                          <Icon name="image" className="size-4" />
                          <span className="sr-only">{a.hasPicture}</span>
                        </span>
                      )}
                    </div>
                    <p className="mt-1.5 font-semibold break-words text-heading">{expertiseTitle(card, locale)}</p>
                    <p className="mt-0.5 line-clamp-2 text-sm break-words text-muted">
                      {locale === "en" ? card.description_en || card.description_bn : card.description_bn}
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {iconButton(a.moveUp, "arrowUp", () => void move(i, i - 1), { disabled: i === 0 })}
                  {iconButton(a.moveDown, "arrowDown", () => void move(i, i + 1), { disabled: i === items.length - 1 })}
                  {iconButton(a.edit, "pencil", () => onEdit(card))}
                  {iconButton(card.is_published ? a.hide : a.show, card.is_published ? "eyeOff" : "eye", () => void togglePublish(card))}
                  {iconButton(
                    a.delete,
                    "trash",
                    () => {
                      setDeleteError(null);
                      setDeleting(card);
                    },
                    { danger: true },
                  )}
                </div>
              </li>
            ))}
          </ol>
        </>
      )}

      {deleting && (
        <Modal title={a.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <div className="flex gap-3">
            <ExpertisePreview imageUrl={deleting.image_url} icon={homeIcon(deleting.icon)} />
            <p className="min-w-0 break-words">{fill(a.deleteText, { title: expertiseTitle(deleting, locale) })}</p>
          </div>
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
    </Card>
  );
}
