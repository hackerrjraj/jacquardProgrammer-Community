"use client";

import { useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { failureText } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch, type AccountType, type FundSettings, type MobileMethod, type PaymentAccountsSettings } from "@/lib/api";
import { fill, normalizePhone, PHONE_RE } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { SectionTitle, type SectionProps } from "./settings-shared";

const MOBILE: readonly MobileMethod[] = ["bkash", "nagad", "rocket"];
const TYPES: readonly AccountType[] = ["personal", "agent", "merchant"];
// Same rules as the backend (Admin\SettingsController::updateAccounts).
const ROCKET_RE = /^01[3-9]\d{8}\d?$/;
const ACCOUNT_RE = /^\d[\d -]*$/;
const MAX = { bank_name: 100, account_name: 100, account_number: 30, branch: 100, routing_number: 20, instructions: 2000 };

type BankField = "bank_name" | "account_name" | "account_number" | "branch" | "routing_number";
const BANK_FIELDS: readonly BankField[] = ["bank_name", "account_name", "account_number", "branch", "routing_number"];
type ErrorCode = "required" | "phone" | "rocket" | "accountNumber" | "routing" | "bnRequired" | "tooLong";
type FieldError = { code: ErrorCode; max?: number } | { text: string };

/** The form keeps text fields as strings ("" = empty). */
type Form = Record<MobileMethod, { number: string; type: AccountType; visible: boolean }> & {
  bank: Record<BankField, string> & { visible: boolean };
  instructions: { bn: string; en: string; visible: boolean };
};

function toForm(a: PaymentAccountsSettings): Form {
  const mobile = (m: MobileMethod) => ({ number: a[m].number ?? "", type: a[m].type, visible: a[m].visible });
  return {
    bkash: mobile("bkash"),
    nagad: mobile("nagad"),
    rocket: mobile("rocket"),
    bank: {
      bank_name: a.bank.bank_name ?? "",
      account_name: a.bank.account_name ?? "",
      account_number: a.bank.account_number ?? "",
      branch: a.bank.branch ?? "",
      routing_number: a.bank.routing_number ?? "",
      visible: a.bank.visible,
    },
    instructions: { bn: a.instructions.bn ?? "", en: a.instructions.en ?? "", visible: a.instructions.visible },
  };
}

const orNull = (v: string) => (v.trim() === "" ? null : v.trim());

/** Receiving accounts (bKash / Nagad / Rocket / bank) + payment instructions — each part can be shown or hidden. */
export function AccountsSection({ data, onSaved, onAuthError }: SectionProps) {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.fundSettings.accounts;
  const [form, setForm] = useState<Form>(() => toForm(data.accounts));
  const [errors, setErrors] = useState<Record<string, FieldError>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const errorText = (key: string): string | undefined => {
    const e = errors[key];
    if (!e) return undefined;
    return "text" in e ? e.text : fill(s.errors[e.code], { max: localizeDigits(String(e.max ?? 0), locale) });
  };

  function update<P extends keyof Form>(part: P, patch: Partial<Form[P]>) {
    setForm((prev) => ({ ...prev, [part]: { ...prev[part], ...patch } }));
    setFlash(null);
    const keys = Object.keys(patch).map((k) => `${part}.${k}`);
    setErrors((prev) => {
      const next = { ...prev };
      for (const k of keys) delete next[k];
      if (part === "instructions") delete next["instructions.bn"];
      if ("visible" in patch) for (const k of Object.keys(next)) if (k.startsWith(`${part}.`)) delete next[k];
      return next;
    });
  }

  function validate(): Record<string, FieldError> {
    const out: Record<string, FieldError> = {};
    for (const m of MOBILE) {
      const n = normalizePhone(form[m].number);
      if (!n) {
        if (form[m].visible) out[`${m}.number`] = { code: "required" };
      } else if (!(m === "rocket" ? ROCKET_RE : PHONE_RE).test(n)) {
        out[`${m}.number`] = { code: m === "rocket" ? "rocket" : "phone" };
      }
    }
    const b = form.bank;
    for (const f of BANK_FIELDS) {
      const v = b[f].trim();
      if (!v) {
        if (b.visible && (f === "bank_name" || f === "account_name" || f === "account_number")) out[`bank.${f}`] = { code: "required" };
      } else if (v.length > MAX[f]) out[`bank.${f}`] = { code: "tooLong", max: MAX[f] };
      else if (f === "account_number" && !ACCOUNT_RE.test(v)) out[`bank.${f}`] = { code: "accountNumber" };
      else if (f === "routing_number" && !/^\d+$/.test(v)) out[`bank.${f}`] = { code: "routing" };
    }
    const ins = form.instructions;
    if (!ins.bn.trim() && (ins.visible || ins.en.trim())) out["instructions.bn"] = { code: "bnRequired" };
    if (ins.bn.length > MAX.instructions) out["instructions.bn"] = { code: "tooLong", max: MAX.instructions };
    if (ins.en.length > MAX.instructions) out["instructions.en"] = { code: "tooLong", max: MAX.instructions };
    return out;
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setFailure(null);
    setFlash(null);
    const found = validate();
    setErrors(found);
    if (Object.keys(found).length) {
      setFailure(t.fundSettings.fixErrors);
      return;
    }

    const mobile = (m: MobileMethod) => ({ number: orNull(normalizePhone(form[m].number)), type: form[m].type, visible: form[m].visible });
    const body = {
      bkash: mobile("bkash"),
      nagad: mobile("nagad"),
      rocket: mobile("rocket"),
      bank: { ...Object.fromEntries(BANK_FIELDS.map((f) => [f, orNull(form.bank[f])])), visible: form.bank.visible },
      instructions: { bn: orNull(form.instructions.bn), en: orNull(form.instructions.en), visible: form.instructions.visible },
    };

    setBusy(true);
    const res = await apiFetch<{ message: string; data: FundSettings }>("/admin/settings/accounts", { method: "PUT", body });
    setBusy(false);
    if (res.ok) {
      onSaved(res.data.data);
      setForm(toForm(res.data.data.accounts));
      setFlash(res.data.message);
      return;
    }
    onAuthError(res);
    if (res.status === 422 && res.data?.errors) {
      const server: Record<string, FieldError> = {};
      for (const [key, messages] of Object.entries(res.data.errors)) if (messages[0]) server[key] = { text: messages[0] };
      setErrors(server);
      setFailure(t.fundSettings.fixErrors);
      return;
    }
    setFailure(failureText(res, t));
  }

  const showBox = (checked: boolean, onChange: (v: boolean) => void) => (
    <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium">
      <input type="checkbox" className="size-4 accent-primary" checked={checked} onChange={(e) => onChange(e.target.checked)} />
      {s.show}
    </label>
  );
  const badge = (visible: boolean) => (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${visible ? "bg-success-soft text-success" : "bg-border/60 text-muted"}`}
    >
      {visible ? s.shown : s.hidden}
    </span>
  );

  return (
    <Card className="p-5 sm:p-6">
      <SectionTitle icon="wallet" title={s.title} subtitle={s.subtitle} />

      {flash && (
        <Alert tone="success" className="mt-4">
          {flash}
        </Alert>
      )}

      <form noValidate onSubmit={(e) => void save(e)} className="mt-5 space-y-5">
        {MOBILE.map((m) => (
          <fieldset key={m} className="rounded-control border border-border p-4">
            <legend className="flex items-center gap-2 px-1 font-semibold text-heading">
              {t.payments.methods[m]} {badge(form[m].visible)}
            </legend>
            <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
              <Input
                label={m === "rocket" ? s.rocketNumber : s.number}
                type="tel"
                inputMode="tel"
                autoComplete="off"
                placeholder={m === "rocket" ? "01XXXXXXXXXX" : "01XXXXXXXXX"}
                value={form[m].number}
                error={errorText(`${m}.number`)}
                onChange={(e) => update(m, { number: e.target.value })}
              />
              <Select
                label={s.type}
                value={form[m].type}
                error={errorText(`${m}.type`)}
                onChange={(e) => update(m, { type: e.target.value as AccountType })}
              >
                {TYPES.map((type) => (
                  <option key={type} value={type}>
                    {t.receiving.types[type]}
                  </option>
                ))}
              </Select>
            </div>
            <div className="mt-3">{showBox(form[m].visible, (v) => update(m, { visible: v }))}</div>
          </fieldset>
        ))}

        <fieldset className="rounded-control border border-border p-4">
          <legend className="flex items-center gap-2 px-1 font-semibold text-heading">
            {s.bankTitle} {badge(form.bank.visible)}
          </legend>
          <div className="grid gap-4 sm:grid-cols-2">
            {BANK_FIELDS.map((f) => (
              <Input
                key={f}
                label={{ bank_name: s.bankName, account_name: s.accountName, account_number: s.accountNumber, branch: s.branch, routing_number: s.routing }[f]}
                inputMode={f === "account_number" || f === "routing_number" ? "numeric" : undefined}
                autoComplete="off"
                maxLength={MAX[f] + 10}
                value={form.bank[f]}
                error={errorText(`bank.${f}`)}
                onChange={(e) => update("bank", { [f]: e.target.value })}
              />
            ))}
          </div>
          <div className="mt-3">{showBox(form.bank.visible, (v) => update("bank", { visible: v }))}</div>
        </fieldset>

        <fieldset className="rounded-control border border-border p-4">
          <legend className="flex items-center gap-2 px-1 font-semibold text-heading">
            {s.instructionsTitle} {badge(form.instructions.visible)}
          </legend>
          <div className="grid gap-4">
            <Textarea
              label={s.instructionsBn}
              lang="bn"
              placeholder={s.instructionsPlaceholder}
              value={form.instructions.bn}
              error={errorText("instructions.bn")}
              onChange={(e) => update("instructions", { bn: e.target.value })}
            />
            <div>
              <Textarea
                label={s.instructionsEn}
                lang="en"
                value={form.instructions.en}
                error={errorText("instructions.en")}
                onChange={(e) => update("instructions", { en: e.target.value })}
              />
              <p className="mt-1 text-xs text-muted">{s.instructionsEnHint}</p>
            </div>
          </div>
          <div className="mt-3">{showBox(form.instructions.visible, (v) => update("instructions", { visible: v }))}</div>
        </fieldset>

        {failure && <Alert tone="danger">{failure}</Alert>}
        <Button type="submit" disabled={busy}>
          {busy ? t.fundSettings.saving : s.submit}
        </Button>
      </form>
    </Card>
  );
}
