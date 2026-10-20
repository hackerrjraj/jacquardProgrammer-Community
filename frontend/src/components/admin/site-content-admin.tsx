"use client";

import { useCallback, useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import type { Dictionary } from "@/i18n/config";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { apiFetch } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { SiteImagesPanel } from "./site-images-panel";
import { revalidateSiteContent } from "@/lib/revalidate-site-content";

/** GET /api/admin/site-content — one editable text (Phase 14a). value_* = null means "default". */
type Item = {
  key: string;
  group: Group;
  max: number;
  multiline: boolean;
  default_bn: string;
  default_en: string;
  value_bn: string | null;
  value_en: string | null;
};
type Group = "home" | "about" | "contact" | "join" | "footer" | "seo";
type Draft = { bn: string; en: string };
type Flash = { tone: "success" | "danger"; text: string } | null;

const GROUPS: Group[] = ["home", "about", "contact", "join", "footer", "seo"];
/** The text tabs + the Images tab (part 2: uploads save on their own, not with "Save changes"). */
type Tab = Group | "images";
const TABS: Tab[] = [...GROUPS, "images"];

/** Public page each tab edits (the "View page" link). */
const GROUP_PATH: Record<Group, string> = { home: "/", about: "/about", contact: "/contact", join: "/register", footer: "/", seo: "/" };
const SEO_PATH: Record<string, string> = {
  home: "/", about: "/about", contact: "/contact", programmers: "/programmers", gallery: "/gallery", notices: "/notices", register: "/register",
};

const pagePath = (item: Item) => (item.group === "seo" ? (SEO_PATH[item.key.split(".")[1]] ?? "/") : GROUP_PATH[item.group]);

const effective = (item: Item): Draft => ({ bn: item.value_bn ?? item.default_bn, en: item.value_en ?? item.default_en });
const isCustom = (item: Item) => item.value_bn !== null || item.value_en !== null;

/** A readable name for a key, in the admin's language ("home.stats.programmers" → "Stats · Programmers"). */
function labelFor(key: string, s: Dictionary["siteContent"]): string {
  const override = (s.keyLabels as Record<string, string>)[key];
  if (override) return override;
  const [page, ...rest] = key.split(".");
  if (page === "seo") {
    return `${(s.seoPages as Record<string, string>)[rest[0]] ?? rest[0]} · ${(s.seoFields as Record<string, string>)[rest[1]] ?? rest[1]}`;
  }
  const parts = s.parts as Record<string, string>;
  const words = rest
    .flatMap((seg) => seg.split(/(?=[A-Z])/))
    .map((w) => (/^\d+$/.test(w) ? String(Number(w) + 1) : (parts[w.charAt(0).toLowerCase() + w.slice(1)] ?? w)));
  return words.join(" · ");
}

/** /dashboard/site-content (Phase 14a) */
export function SiteContentScreen() {
  return <RequireAuth roles={navItem("siteContent").roles}>{() => <SiteContentPage />}</RequireAuth>;
}

function SiteContentPage() {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.siteContent;
  const [reloadKey, setReloadKey] = useState(0);
  const [items, setItems] = useState<Item[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [tab, setTab] = useState<Tab>("home");
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [resetting, setResetting] = useState<Item | null>(null);

  const load = useCallback(async () => {
    const res = await apiFetch<{ data: Item[] }>("/admin/site-content");
    if (res.ok) {
      setItems(res.data.data);
      setDrafts(Object.fromEntries(res.data.data.map((i) => [i.key, effective(i)])));
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- initial data load
    void load();
  }, [load, reloadKey]);

  const isDirty = (item: Item) => {
    const d = drafts[item.key];
    const e = effective(item);
    return !!d && (d.bn !== e.bn || d.en !== e.en);
  };
  const dirtyIn = (group: Group) => (items ?? []).filter((i) => i.group === group && isDirty(i));
  const allDirty = (items ?? []).filter(isDirty);

  const edit = (key: string, lang: "bn" | "en", value: string) => {
    setDrafts((prev) => ({ ...prev, [key]: { ...prev[key], [lang]: value } }));
    setErrors((prev) => ({ ...prev, [`items.${key}.${lang}`]: "" }));
  };

  async function save() {
    setFlash(null);
    if (allDirty.length === 0) {
      setFlash({ tone: "success", text: s.nothingToSave });
      return;
    }
    // Checked in the browser first (the API checks again): plain text, length.
    const found: Record<string, string> = {};
    for (const item of allDirty) {
      for (const lang of ["bn", "en"] as const) {
        const v = drafts[item.key][lang];
        if (/[<>]/.test(v)) found[`items.${item.key}.${lang}`] = t.siteContent.plainText;
        else if (v.length > item.max) found[`items.${item.key}.${lang}`] = fill(s.tooLong, { max: String(item.max) });
      }
    }
    setErrors(found);
    if (Object.keys(found).length > 0) {
      const first = allDirty.find((i) => found[`items.${i.key}.bn`] || found[`items.${i.key}.en`]);
      if (first) setTab(first.group);
      setFlash({ tone: "danger", text: s.fixErrors });
      return;
    }
    setBusy(true);
    const body = { items: Object.fromEntries(allDirty.map((i) => [i.key, drafts[i.key]])) };
    const res = await apiFetch<{ message: string }>("/admin/site-content", { method: "PUT", body });
    if (res.ok) {
      void revalidateSiteContent();
      await load();
      setFlash({ tone: "success", text: res.data.message });
    } else {
      const apiErrors = fieldErrors(res);
      setErrors(apiErrors);
      const bad = allDirty.find((i) => apiErrors[`items.${i.key}.bn`] || apiErrors[`items.${i.key}.en`]);
      if (bad) setTab(bad.group);
      setFlash({ tone: "danger", text: res.status === 422 ? s.fixErrors : failureText(res, t) });
    }
    setBusy(false);
  }

  async function confirmReset() {
    if (!resetting) return;
    setBusy(true);
    const res = await apiFetch<{ message: string }>("/admin/site-content/reset", { method: "POST", body: { key: resetting.key } });
    if (res.ok) {
      void revalidateSiteContent();
      await load();
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setFlash({ tone: "danger", text: failureText(res, t) });
    }
    setBusy(false);
    setResetting(null);
  }

  if (failed && !items) {
    return (
      <Card className="p-5 text-center sm:p-6">
        <p className="text-danger">{s.loadFailed}</p>
        <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
          {s.retry}
        </Button>
      </Card>
    );
  }
  if (!items) {
    return (
      <Card className="p-5 sm:p-6" aria-busy="true">
        <p role="status" className="text-sm text-muted">
          {s.loading}
        </p>
        <div className="mt-4 h-40 animate-pulse rounded-card bg-border/60" />
      </Card>
    );
  }

  const notes: Partial<Record<Group, string>> = { home: s.homeNote, about: s.aboutNote, contact: s.contactNote, footer: s.footerNote, join: s.joinNote, seo: s.seoHint };
  const showSettingsLink = tab === "about" || tab === "contact" || tab === "footer";
  const textTab = tab === "images" ? "home" : tab;
  const visible = tab === "images" ? [] : items.filter((i) => i.group === tab);
  const num = (n: number) => localizeDigits(String(n), locale);

  return (
    <div className="space-y-6">
      <p className="max-w-3xl">{s.subtitle}</p>

      <div role="tablist" aria-label={s.tabsLabel} className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
        {TABS.map((g) => {
          const active = g === tab;
          const dirty = g === "images" ? 0 : dirtyIn(g).length;
          return (
            <button
              key={g}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => setTab(g)}
              className={`shrink-0 rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {s.tabs[g]}
              {dirty > 0 && <span className="ms-2 inline-block size-2 rounded-full bg-danger align-middle" title={s.unsaved} />}
            </button>
          );
        })}
      </div>

      {tab === "images" ? (
        <SiteImagesPanel />
      ) : (
        <>
      <Card className="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
        <p className="max-w-3xl text-sm text-muted">{notes[textTab]}</p>
        <div className="flex flex-wrap gap-2">
          {tab === "home" && (
            <ButtonLink href="/dashboard/hero-slides" variant="outline" size="sm">
              {s.openHero}
            </ButtonLink>
          )}
          {showSettingsLink && (
            <ButtonLink href="/dashboard/site-settings" variant="outline" size="sm">
              {s.openSettings}
            </ButtonLink>
          )}
          <ButtonLink href={GROUP_PATH[textTab]} variant="outline" size="sm" target="_blank" rel="noopener">
            <Icon name="eye" className="size-4" />
            {s.viewPage}
          </ButtonLink>
        </div>
      </Card>

      <ul role="tabpanel" className="space-y-4">
        {visible.map((item) => {
          const d = drafts[item.key] ?? effective(item);
          const dirty = isDirty(item);
          const custom = isCustom(item);
          return (
            <li key={item.key}>
              <Card className="p-4 sm:p-5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <h3 className="text-base font-semibold">
                    {labelFor(item.key, s)}
                    {dirty ? (
                      <span className="ms-2 rounded-full bg-danger-soft px-2 py-0.5 text-xs font-medium text-danger">{s.unsaved}</span>
                    ) : custom ? (
                      <span className="ms-2 rounded-full bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary-text">{s.customised}</span>
                    ) : null}
                  </h3>
                  <div className="flex gap-2">
                    <ButtonLink href={pagePath(item)} variant="ghost" size="sm" target="_blank" rel="noopener">
                      <Icon name="eye" className="size-4" />
                      {s.viewPage}
                    </ButtonLink>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={busy || (!custom && !dirty)}
                      onClick={() => {
                        if (custom) setResetting(item);
                        else setDrafts((prev) => ({ ...prev, [item.key]: effective(item) }));
                      }}
                    >
                      {s.reset}
                    </Button>
                  </div>
                </div>
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                  {(["bn", "en"] as const).map((lang) => (
                    <Box
                      key={lang}
                      item={item}
                      lang={lang}
                      label={lang === "bn" ? s.bnBox : s.enBox}
                      value={d[lang]}
                      error={errors[`items.${item.key}.${lang}`]}
                      counter={fill(s.chars, { n: num(d[lang].length), max: num(item.max) })}
                      onChange={(v) => edit(item.key, lang, v)}
                    />
                  ))}
                </div>
              </Card>
            </li>
          );
        })}
      </ul>

      {flash && <Alert tone={flash.tone}>{flash.text}</Alert>}
      <div className="sticky bottom-3 flex justify-end">
        <Button onClick={save} disabled={busy} className="w-full shadow-md sm:w-auto">
          {busy ? s.saving : s.save}
          {allDirty.length > 0 && !busy ? ` (${num(allDirty.length)})` : ""}
        </Button>
      </div>
        </>
      )}

      {resetting && (
        <Modal title={s.reset} onClose={() => !busy && setResetting(null)}>
          <p>{s.resetConfirm}</p>
          <p className="mt-2 text-sm text-muted">{labelFor(resetting.key, s)}</p>
          <div className="mt-5 flex justify-end gap-2">
            <Button variant="outline" disabled={busy} onClick={() => setResetting(null)}>
              {t.admin.cancel}
            </Button>
            <Button disabled={busy} onClick={confirmReset}>
              {s.reset}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/** One language box: a single line or a textarea, with a character counter and the default as a hint. */
function Box({
  item, lang, label, value, error, counter, onChange,
}: {
  item: Item;
  lang: "bn" | "en";
  label: string;
  value: string;
  error?: string;
  counter: string;
  onChange: (v: string) => void;
}) {
  const id = `sc-${item.key}-${lang}`;
  const over = value.length > item.max;
  const common = `w-full rounded-control border bg-field px-3 py-2.5 text-base text-foreground focus:bg-surface focus:outline-2 focus:outline-offset-0 focus:outline-primary ${error || over ? "border-danger" : "border-field-border"}`;
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-foreground">
        {label}
      </label>
      {item.multiline ? (
        <textarea id={id} lang={lang} rows={Math.min(8, Math.max(3, Math.ceil(value.length / 60)))} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={error ? true : undefined} className={`${common} min-h-24`} />
      ) : (
        <input id={id} lang={lang} type="text" value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={error ? true : undefined} className={common} autoComplete="off" />
      )}
      <p className={`mt-1 text-right text-xs ${over ? "text-danger" : "text-muted"}`}>{counter}</p>
      {error && <p className="mt-1 text-sm text-danger">{error}</p>}
    </div>
  );
}
