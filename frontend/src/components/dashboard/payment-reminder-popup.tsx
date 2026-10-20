"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Button, ButtonLink } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import { apiFetch, type PaymentReminderStatus } from "@/lib/api";
import { fill } from "@/lib/auth";
import { formatTaka } from "@/lib/format";

const SEEN_KEY = "jpphaf_payment_reminder_seen";

/** Dashboard popup for a member with unpaid dues (once per browser session; nothing when no dues or the reminder is off). */
export function PaymentReminderPopup() {
  const s = useDictionary().paymentReminder;
  const locale = useLocale();
  const [status, setStatus] = useState<PaymentReminderStatus | null>(null);
  const [open, setOpen] = useState(false);

  useEffect(() => {
    let seen = false;
    try {
      seen = sessionStorage.getItem(SEEN_KEY) === "1";
    } catch {
      // storage blocked: show it on every visit
    }
    if (seen) return;
    let cancelled = false;
    void apiFetch<{ data: PaymentReminderStatus }>("/payment-reminder/status").then((res) => {
      if (cancelled || !res.ok || !res.data.data.show) return;
      setStatus(res.data.data);
      setOpen(true);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  function close() {
    try {
      sessionStorage.setItem(SEEN_KEY, "1");
    } catch {
      // ignore
    }
    setOpen(false);
  }

  if (!open || !status) return null;
  return (
    <Modal title={s.popup.title} onClose={close}>
      <p className="whitespace-pre-line text-base">{status.message}</p>
      <p className="mt-3 text-lg font-semibold text-danger">
        {fill(s.popup.amount, { amount: formatTaka(status.total_due, locale) })}
      </p>
      <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" onClick={close}>
          {s.popup.later}
        </Button>
        <ButtonLink href={status.pay_url} onClick={close}>
          {s.popup.payNow}
        </ButtonLink>
      </div>
    </Modal>
  );
}
