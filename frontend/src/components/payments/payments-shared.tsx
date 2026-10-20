"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { LOCALE_COOKIE, toLocale, type Dictionary, type Locale } from "@/i18n/config";
import { useDictionary } from "@/i18n/client";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import type { ContributionStatus, DueMonth, PaymentMethod } from "@/lib/api";

// Same rules as the backend (ContributionController, MASTER_SPEC §26, §38).
export const METHODS: readonly PaymentMethod[] = ["bkash", "nagad", "rocket", "bank", "cash"];
export const MOBILE_METHODS: readonly PaymentMethod[] = ["bkash", "nagad", "rocket"];
export const MAX_MONTHS = 24;
export const PROOF_MAX_BYTES = 2 * 1024 * 1024;
export const PROOF_TYPES = ["image/jpeg", "image/png", "image/webp"];
export const TXID_MAX = 50;
export const TXID_RE = /^[A-Z0-9\-/]+$/;

/** "2026-08" → "আগস্ট ২০২৬" / "August 2026". */
export function monthLabel(month: string, locale: Locale): string {
  const [y, m] = month.split("-").map(Number);
  if (!y || !m) return month;
  return new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(y, m - 1, 1)));
}

const badgeTones = {
  paid: "bg-success-soft text-success",
  approved: "bg-success-soft text-success",
  pending: "bg-warning-soft text-foreground",
  due: "bg-danger-soft text-danger",
  rejected: "bg-danger-soft text-danger",
} as const;

/** Paid / Waiting for check / Due (a month) or Verified / Waiting / Rejected (a payment). */
export function PaymentBadge({ status, t }: { status: DueMonth["status"] | ContributionStatus; t: Dictionary }) {
  const p = t.payments;
  const text =
    status === "paid" ? p.monthStatus.paid : status === "due" ? p.monthStatus.due : p.statuses[status];
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ${badgeTones[status]}`}>
      <span className="size-1.5 rounded-full bg-current" />
      {text}
    </span>
  );
}

/** Fetches a login-only file with the session cookie (null on any failure). */
async function fetchPrivate(url: string): Promise<Blob | null> {
  try {
    const lang = toLocale(document.cookie.match(new RegExp(`(?:^|; )${LOCALE_COOKIE}=([^;]*)`))?.[1]);
    const res = await fetch(url, { credentials: "include", cache: "no-store", headers: { "Accept-Language": lang } });
    return res.ok ? await res.blob() : null;
  } catch {
    return null;
  }
}

/** The member's own payment screenshot (private file, loaded with the login cookie) in a dialog. */
export function ProofModal({ url, onClose }: { url: string; onClose: () => void }) {
  const t = useDictionary();
  const h = t.payments.history;
  const [src, setSrc] = useState<string | "failed" | null>(null);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;
    void fetchPrivate(url).then((blob) => {
      if (cancelled) return;
      if (!blob) return setSrc("failed");
      objectUrl = URL.createObjectURL(blob);
      setSrc(objectUrl);
    });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [url]);

  return (
    <Modal title={h.proofTitle} onClose={onClose}>
      {src === null ? (
        <div role="status" className="flex aspect-[3/4] w-full animate-pulse items-center justify-center rounded-2xl bg-border text-sm text-muted">
          {t.payments.loading}
        </div>
      ) : src === "failed" ? (
        <p className="text-danger">{h.proofFailed}</p>
      ) : (
        <Image
          src={src}
          alt={h.proofTitle}
          width={800}
          height={1200}
          unoptimized
          className="mx-auto h-auto max-h-[70vh] w-auto max-w-full rounded-2xl border border-border object-contain"
        />
      )}
      <div className="mt-5 flex justify-end">
        <Button variant="outline" onClick={onClose}>
          {h.close}
        </Button>
      </div>
    </Modal>
  );
}
