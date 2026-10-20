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
import { Textarea } from "@/components/ui/textarea";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { apiFetch, type SiteInfo } from "@/lib/api";
import { revalidateSiteContent } from "@/lib/revalidate-site-content";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

type Field = keyof SiteInfo;
type Form = Record<Field, string>;
type Flash = { tone: "success" | "danger"; text: string } | null;

// Same limits as the backend (SiteSettingsController).
const MAX: Record<Field, number> = {
  phone_1: 20, phone_2: 20, whatsapp: 20, email: 150, facebook_url: 255,
  address_bn: 300, address_en: 300, office_hours_bn: 150, office_hours_en: 150, about_bn: 5000, about_en: 5000,
};
const PHONE_FIELDS: Field[] = ["phone_1", "phone_2", "whatsapp"];

/** "+880 1712-345678" → "01712345678" (same as the backend); anything else is returned trimmed. */
function normalizePhone(value: string): string {
  const digits = value.trim().replace(/[\s-]/g, "");
  const m = digits.match(/^\+?880(1\d{9})$/);
  return m ? `0${m[1]}` : digits;
}

const toForm = (info: SiteInfo): Form =>
  Object.fromEntries(Object.entries(info).map(([k, v]) => [k, v ?? ""])) as Form;

/** /dashboard/site-settings (Phase 11c) */
export function SiteSettingsScreen() {
  return (
    <RequireAuth roles={navItem("siteSettings").roles}>{() => <SiteSettingsPage />}</RequireAuth>
  );
}

function SiteSettingsPage() {
  const s = useDictionary().siteSettings;
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl">{s.subtitle}</p>
        <ButtonLink href="/about" variant="outline" size="sm" target="_blank" rel="noopener">
          <Icon name="eye" className="size-4" />
          {s.viewAbout}
        </ButtonLink>
      </div>
      <SiteInfoForm />
      <CommitteeMoved />
    </div>
  );
}

function SiteInfoForm() {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.siteSettings;
  const [reloadKey, setReloadKey] = useState(0);
  const [loaded, setLoaded] = useState<{ key: number; ok: boolean } | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [tab, setTab] = useState<"bn" | "en">(locale);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: SiteInfo }>("/admin/site-settings").then((res) => {
      if (cancelled) return;
      if (res.ok) setForm(toForm(res.data.data));
      setLoaded({ key: reloadKey, ok: res.ok });
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);
  const current = loaded?.key === reloadKey ? loaded : null;

  const set = (field: Field, value: string) => {
    setForm((prev) => (prev ? { ...prev, [field]: value } : prev));
    setErrors((prev) => ({ ...prev, [field]: undefined }));
  };

  /** Checked in the browser first; the API checks again. */
  function check(values: Form): Partial<Record<Field, string>> {
    const out: Partial<Record<Field, string>> = {};
    for (const f of PHONE_FIELDS) {
      const v = normalizePhone(values[f]);
      if (v && !/^01[3-9]\d{8}$/.test(v)) out[f] = s.phoneError;
    }
    const email = values.email.trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) out.email = s.emailError;
    const url = values.facebook_url.trim();
    if (url) {
      let ok = false;
      try {
        ok = url.startsWith("https://") && new URL(url).hostname.includes(".");
      } catch {
        ok = false;
      }
      if (!ok) out.facebook_url = s.urlError;
    }
    return out;
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setFlash(null);
    const found = check(form);
    setErrors(found);
    if (Object.keys(found).length > 0) {
      setFlash({ tone: "danger", text: s.fixErrors });
      return;
    }
    const body = Object.fromEntries(
      Object.entries(form).map(([k, v]) => [k, PHONE_FIELDS.includes(k as Field) ? normalizePhone(v) : v]),
    );
    setBusy(true);
    const res = await apiFetch<{ message: string; data: SiteInfo }>("/admin/site-settings", { method: "PUT", body });
    setBusy(false);
    if (res.ok) {
      void revalidateSiteContent();
      setForm(toForm(res.data.data));
      setFlash({ tone: "success", text: res.data.message });
      return;
    }
    const apiErrors = fieldErrors(res) as Partial<Record<Field, string>>;
    setErrors(apiErrors);
    if (apiErrors.about_bn) setTab("bn");
    else if (apiErrors.about_en) setTab("en");
    setFlash({ tone: "danger", text: res.status === 422 ? s.fixErrors : failureText(res, t) });
  }

  if (!current || (current.ok && !form)) {
    return (
      <Card className="p-5 sm:p-6" aria-busy="true">
        <p role="status" className="text-sm text-muted">
          {s.loading}
        </p>
        <div className="mt-4 h-40 animate-pulse rounded-card bg-border/60" />
      </Card>
    );
  }
  if (!current.ok || !form) {
    return (
      <Card className="p-5 text-center sm:p-6">
        <p className="text-danger">{s.loadFailed}</p>
        <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
          {s.retry}
        </Button>
      </Card>
    );
  }

  const input = (field: Field, label: string, opts: { type?: string; hint?: string; placeholder?: string } = {}) => (
    <div>
      <Input
        label={label}
        type={opts.type ?? "text"}
        inputMode={PHONE_FIELDS.includes(field) ? "tel" : undefined}
        value={form[field]}
        onChange={(e) => set(field, e.target.value)}
        maxLength={MAX[field]}
        placeholder={opts.placeholder}
        error={errors[field]}
        autoComplete="off"
      />
      {opts.hint && !errors[field] && <p className="mt-1 text-sm text-muted">{opts.hint}</p>}
    </div>
  );
  const aboutField: Field = tab === "bn" ? "about_bn" : "about_en";

  return (
    <form onSubmit={save} noValidate className="space-y-6">
      <Card className="p-5 sm:p-6">
        <h2 className="text-lg font-semibold">{s.contactTitle}</h2>
        <div className="mt-5 grid gap-4 sm:grid-cols-2">
          {input("phone_1", s.phone1, { type: "tel", hint: s.phoneHint, placeholder: "01XXXXXXXXX" })}
          {input("phone_2", s.phone2, { type: "tel", hint: s.phoneHint, placeholder: "01XXXXXXXXX" })}
          {input("whatsapp", s.whatsapp, { type: "tel", hint: s.phoneHint, placeholder: "01XXXXXXXXX" })}
          {input("email", s.email, { type: "email", placeholder: "info@example.com" })}
          <div className="sm:col-span-2">
            {input("facebook_url", s.facebook, { type: "url", hint: s.facebookHint, placeholder: "https://facebook.com/…" })}
          </div>
          {input("address_bn", s.addressBn)}
          {input("address_en", s.addressEn)}
          {input("office_hours_bn", s.officeHoursBn, { placeholder: s.officeHoursPlaceholder })}
          {input("office_hours_en", s.officeHoursEn, { placeholder: s.officeHoursPlaceholder })}
        </div>
        <p className="mt-4 text-sm text-muted">{s.bothLanguagesHint}</p>
      </Card>

      <Card className="p-5 sm:p-6">
        <h2 className="text-lg font-semibold">{s.aboutTitle}</h2>
        <p className="mt-1 text-sm text-muted">{s.aboutHint}</p>
        <div role="tablist" aria-label={s.tabsLabel} className="mt-4 flex gap-2">
          {(["bn", "en"] as const).map((key) => {
            const active = key === tab;
            const field: Field = key === "bn" ? "about_bn" : "about_en";
            return (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => setTab(key)}
                className={`shrink-0 rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors ${
                  active
                    ? "border-secondary bg-secondary text-secondary-foreground"
                    : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
                } ${errors[field] ? "ring-2 ring-danger" : ""}`}
              >
                {key === "bn" ? s.tabBn : s.tabEn}
              </button>
            );
          })}
        </div>
        <div role="tabpanel" className="mt-4">
          <Textarea
            key={aboutField}
            label={tab === "bn" ? s.aboutBn : s.aboutEn}
            lang={tab}
            rows={10}
            value={form[aboutField]}
            onChange={(e) => set(aboutField, e.target.value)}
            maxLength={MAX[aboutField]}
            error={errors[aboutField]}
          />
          <p className="mt-1 text-right text-xs text-muted">
            {fill(s.chars, {
              n: localizeDigits(String(form[aboutField].length), locale),
              max: localizeDigits(String(MAX[aboutField]), locale),
            })}
          </p>
        </div>
      </Card>

      {flash && <Alert tone={flash.tone}>{flash.text}</Alert>}
      <div className="flex justify-end">
        <Button type="submit" disabled={busy} className="w-full sm:w-auto">
          {busy ? s.saving : s.save}
        </Button>
      </div>
    </form>
  );
}

/** The committee list moved to Dashboard → Committee (committee panel). */
function CommitteeMoved() {
  const m = useDictionary().siteSettings.committeeMoved;
  return (
    <Card className="flex flex-wrap items-center justify-between gap-3 p-5 sm:p-6">
      <div className="min-w-0">
        <h2 className="text-lg font-semibold">{m.title}</h2>
        <p className="mt-1 text-sm text-muted">{m.text}</p>
      </div>
      <ButtonLink href="/dashboard/committee" variant="outline" size="sm">
        <Icon name="scale" className="size-4" />
        {m.open}
      </ButtonLink>
    </Card>
  );
}
