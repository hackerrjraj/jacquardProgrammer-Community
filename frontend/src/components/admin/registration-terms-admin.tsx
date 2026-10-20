"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { apiFetch, type RegistrationTerms } from "@/lib/api";
import { fill } from "@/lib/auth";

type Form = Omit<RegistrationTerms, "version" | "updated_at" | "active">;
type TextField = Exclude<keyof Form, "enabled">;
type Flash = { tone: "success" | "danger"; text: string } | null;

// Same limits as the backend (RegistrationTerms::FIELDS).
const MAX = { heading: 150, body: 10000, checkbox: 250 } as const;

function split(data: RegistrationTerms): { form: Form; version: number; updated_at: string | null } {
  return {
    form: {
      enabled: data.enabled,
      heading_bn: data.heading_bn,
      heading_en: data.heading_en,
      body_bn: data.body_bn,
      body_en: data.body_en,
      checkbox_bn: data.checkbox_bn,
      checkbox_en: data.checkbox_en,
    },
    version: data.version,
    updated_at: data.updated_at,
  };
}

/** /dashboard/registration-terms */
export function RegistrationTermsScreen() {
  return <RequireAuth roles={navItem("registrationTerms").roles}>{() => <RegistrationTermsForm />}</RequireAuth>;
}

/** "Registration Terms" card on Dashboard → Site Settings: heading, rules text (bn + en), tick label, on/off switch. */
export function RegistrationTermsForm() {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.terms.admin;
  const [form, setForm] = useState<Form | null>(null);
  const [meta, setMeta] = useState<{ version: number; updated_at: string | null }>({ version: 0, updated_at: null });
  const [failed, setFailed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [tab, setTab] = useState<"bn" | "en">(locale);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: RegistrationTerms }>("/admin/registration-terms").then((res) => {
      if (cancelled) return;
      if (res.ok) {
        const d = split(res.data.data);
        setForm(d.form);
        setMeta({ version: d.version, updated_at: d.updated_at });
      }
      setFailed(!res.ok);
    });
    return () => {
      cancelled = true;
    };
  }, [attempt]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setFlash(null);
    setBusy(true);
    const res = await apiFetch<{ message: string; data: RegistrationTerms }>("/admin/registration-terms", {
      method: "PUT",
      body: form,
    });
    setBusy(false);
    if (res.ok) {
      const d = split(res.data.data);
      setForm(d.form);
      setMeta({ version: d.version, updated_at: d.updated_at });
      setErrors({});
      setFlash({ tone: "success", text: res.data.message });
      return;
    }
    setErrors(fieldErrors(res) as Record<string, string | undefined>);
    setFlash({ tone: "danger", text: res.status === 422 ? s.fixErrors : failureText(res, t) });
  }

  if (failed) {
    return (
      <Card className="p-5 text-center sm:p-6">
        <p className="text-danger">{s.loadFailed}</p>
        <Button
          variant="outline"
          className="mt-4"
          onClick={() => {
            setFailed(false);
            setAttempt((n) => n + 1);
          }}
        >
          {s.retry}
        </Button>
      </Card>
    );
  }
  if (!form) {
    return (
      <Card className="p-5 sm:p-6" aria-busy="true">
        <p role="status" className="text-sm text-muted">
          {s.loading}
        </p>
      </Card>
    );
  }

  const set = (field: keyof Form, value: string | boolean) => {
    setForm((prev) => (prev ? { ...prev, [field]: value } : prev));
    setErrors((prev) => ({ ...prev, [field]: undefined }));
  };
  const f = (base: "heading" | "body" | "checkbox") => `${base}_${tab}` as TextField;
  const updated = meta.updated_at
    ? new Date(meta.updated_at).toLocaleString(locale === "bn" ? "bn-BD" : "en-GB", { timeZone: "Asia/Dhaka" })
    : null;

  return (
    <form onSubmit={save} noValidate>
      <Card className="p-5 sm:p-6">
        <h2 className="text-lg font-semibold">{s.title}</h2>
        <p className="mt-1 text-sm text-muted">{s.hint}</p>
        <label className="mt-4 flex items-center gap-3 font-medium">
          <input
            type="checkbox"
            checked={form.enabled}
            onChange={(e) => set("enabled", e.target.checked)}
            className="size-5 accent-primary"
          />
          {s.enabled}
        </label>
        <p className="mt-1 text-sm text-muted">
          {updated ? fill(s.version, { n: String(meta.version), date: updated }) : s.neverSaved}
        </p>

        <div role="tablist" className="mt-4 flex gap-2">
          {(["bn", "en"] as const).map((key) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={key === tab}
              onClick={() => setTab(key)}
              className={`shrink-0 rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors ${
                key === tab
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {key === "bn" ? t.siteSettings.tabBn : t.siteSettings.tabEn}
            </button>
          ))}
        </div>
        <div role="tabpanel" className="mt-4 space-y-4">
          <Input
            key={f("heading")}
            label={s.heading}
            lang={tab}
            value={form[f("heading")]}
            onChange={(e) => set(f("heading"), e.target.value)}
            maxLength={MAX.heading}
            error={errors[f("heading")]}
          />
          <Textarea
            key={f("body")}
            label={s.body}
            lang={tab}
            rows={12}
            value={form[f("body")]}
            onChange={(e) => set(f("body"), e.target.value)}
            maxLength={MAX.body}
            error={errors[f("body")]}
          />
          <Input
            key={f("checkbox")}
            label={s.checkbox}
            lang={tab}
            value={form[f("checkbox")]}
            onChange={(e) => set(f("checkbox"), e.target.value)}
            maxLength={MAX.checkbox}
            error={errors[f("checkbox")]}
          />
        </div>
        <p className="mt-3 text-sm text-muted">{s.emptyNote}</p>
        {flash && (
          <Alert tone={flash.tone} className="mt-4">
            {flash.text}
          </Alert>
        )}
        <div className="mt-4 flex justify-end">
          <Button type="submit" disabled={busy} className="w-full sm:w-auto">
            {busy ? s.saving : s.save}
          </Button>
        </div>
      </Card>
    </form>
  );
}
