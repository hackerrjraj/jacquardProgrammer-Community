"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { apiFetch, type PaymentReminderAdmin } from "@/lib/api";
import { fill } from "@/lib/auth";
import { formatNumber, formatTaka } from "@/lib/format";

type Flash = { tone: "success" | "danger"; text: string } | null;

const MAX = 300; // same as PaymentReminder::MAX in the backend

/** /dashboard/payment-reminder */
export function PaymentReminderScreen() {
  return <RequireAuth roles={navItem("paymentReminder").roles}>{() => <PaymentReminderForm />}</RequireAuth>;
}

function PaymentReminderForm() {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.paymentReminder;
  const [data, setData] = useState<PaymentReminderAdmin | null>(null);
  const [form, setForm] = useState({ enabled: true, message_bn: "", message_en: "" });
  const [failed, setFailed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [attempt, setAttempt] = useState(0);

  function load(d: PaymentReminderAdmin) {
    setData(d);
    setForm({ enabled: d.enabled, message_bn: d.message_bn, message_en: d.message_en });
  }

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: PaymentReminderAdmin }>("/admin/payment-reminder").then((res) => {
      if (cancelled) return;
      if (res.ok) load(res.data.data);
      setFailed(!res.ok);
    });
    return () => {
      cancelled = true;
    };
  }, [attempt]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setFlash(null);
    setBusy(true);
    const res = await apiFetch<{ message: string; data: PaymentReminderAdmin }>("/admin/payment-reminder", {
      method: "PUT",
      body: form,
    });
    setBusy(false);
    if (res.ok) {
      load(res.data.data);
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
  if (!data) {
    return (
      <Card className="p-5 sm:p-6" aria-busy="true">
        <p role="status" className="text-sm text-muted">
          {s.loading}
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      <form onSubmit={save} noValidate>
        <Card className="p-5 sm:p-6">
          <p className="text-sm text-muted">{s.hint}</p>
          <label className="mt-4 flex items-center gap-3 font-medium">
            <input
              type="checkbox"
              checked={form.enabled}
              onChange={(e) => setForm((f) => ({ ...f, enabled: e.target.checked }))}
              className="size-5 accent-primary"
            />
            {s.enabled}
          </label>
          <div className="mt-4 space-y-4">
            <Textarea
              label={s.messageBn}
              lang="bn"
              rows={3}
              value={form.message_bn}
              onChange={(e) => setForm((f) => ({ ...f, message_bn: e.target.value }))}
              maxLength={MAX}
              error={errors.message_bn}
            />
            <Textarea
              label={s.messageEn}
              lang="en"
              rows={3}
              value={form.message_en}
              onChange={(e) => setForm((f) => ({ ...f, message_en: e.target.value }))}
              maxLength={MAX}
              error={errors.message_en}
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

      <Card className="p-5 sm:p-6">
        <h2 className="text-lg font-semibold">{fill(s.recipientsTitle, { n: formatNumber(data.recipient_count, locale) })}</h2>
        <p className="mt-1 text-sm text-muted">{fill(s.totalDue, { amount: formatTaka(data.total_due, locale) })}</p>
        {data.recipients.length === 0 ? (
          <p className="mt-4 text-muted">{s.none}</p>
        ) : (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[32rem] text-left text-sm">
              <thead>
                <tr className="border-b border-border text-muted">
                  <th className="py-2 pr-3 font-medium">{s.colName}</th>
                  <th className="py-2 pr-3 font-medium">{s.colPhone}</th>
                  <th className="py-2 pr-3 font-medium">{s.colDue}</th>
                  <th className="py-2 font-medium">{s.colMonths}</th>
                </tr>
              </thead>
              <tbody>
                {data.recipients.map((r) => (
                  <tr key={r.id} className="border-b border-border/60">
                    <td className="py-2 pr-3">{r.name}</td>
                    <td className="py-2 pr-3">{r.phone}</td>
                    <td className="py-2 pr-3">{formatTaka(r.total_due, locale)}</td>
                    <td className="py-2">
                      {fill(s.monthsValue, {
                        due: formatNumber(r.months_due, locale),
                        pending: formatNumber(r.months_pending, locale),
                      })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
