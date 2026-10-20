"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { failureText, formatSize } from "@/components/profile/profile-shared";
import { apiFetch, type Contribution, type DueMonth, type PaymentMethod } from "@/lib/api";
import { fill, normalizePhone, PHONE_RE, toAsciiDigits } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";
import {
  MAX_MONTHS,
  METHODS,
  MOBILE_METHODS,
  monthLabel,
  PROOF_MAX_BYTES,
  PROOF_TYPES,
  TXID_MAX,
  TXID_RE,
} from "./payments-shared";
import { ReceivingAccounts } from "./receiving-accounts";

type Field = "method" | "sender_number" | "transaction_id" | "amount" | "months" | "proof";
type ErrorCode = "required" | "monthsRequired" | "monthsMax" | "phone" | "txid" | "txidLong" | "amountWhole" | "amountMismatch" | "badType" | "tooBig";
// Client errors keep a code (so they follow a language switch); server errors are already bn/en.
type FieldError = { code: ErrorCode } | { text: string };

/** "Send payment" form (MASTER_SPEC §26). Only months that are still due can be chosen. */
export function PaymentForm({ dueMonths, onSubmitted }: { dueMonths: DueMonth[]; onSubmitted: (message: string) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const f = t.payments.form;

  const [selected, setSelected] = useState<string[]>([]);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<PaymentMethod | "">("");
  const [sender, setSender] = useState("");
  const [txid, setTxid] = useState("");
  const [proof, setProof] = useState<{ file: File; url: string } | null>(null);
  const [errors, setErrors] = useState<Partial<Record<Field, FieldError>>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  // Free the preview's object URL when it is replaced or the form goes away.
  const previewUrl = proof?.url;
  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
    };
  }, [previewUrl]);

  const isMobile = method !== "" && MOBILE_METHODS.includes(method);
  const needsTxid = method !== "" && method !== "cash";
  const amountOf = (months: string[]) => dueMonths.filter((m) => months.includes(m.month)).reduce((sum, m) => sum + m.amount, 0);
  const total = amountOf(selected);

  const errorText = (field: Field): string | undefined => {
    const e = errors[field];
    if (!e) return undefined;
    if ("text" in e) return e.text;
    return fill(f.errors[e.code], {
      max: localizeDigits(String(e.code === "txidLong" ? TXID_MAX : MAX_MONTHS), locale),
      total: formatTaka(total, locale),
    });
  };
  const clearError = (field: Field) => setErrors((prev) => (prev[field] ? { ...prev, [field]: undefined } : prev));

  /** Choosing months fills the amount with their total (the API wants exactly that). */
  function chooseMonths(next: string[]) {
    const sorted = dueMonths.map((m) => m.month).filter((m) => next.includes(m));
    setSelected(sorted);
    setAmount(sorted.length ? String(amountOf(sorted)) : "");
    clearError("months");
    clearError("amount");
  }

  const crop = useImageCrop();

  function chooseFile(file: File | undefined) {
    clearError("proof");
    if (!file) return;
    if (!PROOF_TYPES.includes(file.type)) {
      setErrors((prev) => ({ ...prev, proof: { code: "badType" } }));
    } else if (file.size > PROOF_MAX_BYTES) {
      setErrors((prev) => ({ ...prev, proof: { text: fill(f.errors.tooBig, { size: formatSize(file.size, locale) }) } }));
    } else {
      setProof({ file, url: URL.createObjectURL(file) });
    }
    if (fileRef.current) fileRef.current.value = "";
  }

  function validate(): Partial<Record<Field, FieldError>> {
    const out: Partial<Record<Field, FieldError>> = {};
    if (selected.length === 0) out.months = { code: "monthsRequired" };
    else if (selected.length > MAX_MONTHS) out.months = { code: "monthsMax" };
    if (!method) out.method = { code: "required" };
    if (isMobile) {
      const phone = normalizePhone(sender);
      if (!phone) out.sender_number = { code: "required" };
      else if (!PHONE_RE.test(phone)) out.sender_number = { code: "phone" };
    }
    if (needsTxid) {
      const id = toAsciiDigits(txid).replace(/\s+/g, "").toUpperCase();
      if (!id) out.transaction_id = { code: "required" };
      else if (id.length > TXID_MAX) out.transaction_id = { code: "txidLong" };
      else if (!TXID_RE.test(id)) out.transaction_id = { code: "txid" };
    }
    const value = toAsciiDigits(amount).trim();
    if (!value) out.amount = { code: "required" };
    else if (!/^\d+$/.test(value)) out.amount = { code: "amountWhole" };
    else if (selected.length && Number(value) !== total) out.amount = { code: "amountMismatch" };
    if (errors.proof) out.proof = errors.proof;
    return out;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setFailure(null);
    const found = validate();
    setErrors(found);
    if (Object.values(found).some(Boolean)) return;

    const body = new FormData();
    body.append("method", method);
    if (isMobile) body.append("sender_number", normalizePhone(sender));
    if (needsTxid) body.append("transaction_id", toAsciiDigits(txid).replace(/\s+/g, "").toUpperCase());
    body.append("amount", toAsciiDigits(amount).trim());
    for (const m of selected) body.append("months[]", m);
    if (proof) body.append("proof", proof.file);

    setBusy(true);
    const res = await apiFetch<{ message: string; data: Contribution }>("/contributions", { method: "POST", body });
    setBusy(false);
    if (res.ok) {
      onSubmitted(res.data.message);
      return;
    }
    if (res.status === 422 && res.data?.errors) {
      // "months.0" → months
      const server: Partial<Record<Field, FieldError>> = {};
      for (const [key, messages] of Object.entries(res.data.errors)) {
        const field = key.split(".")[0] as Field;
        if (!server[field] && messages[0]) server[field] = { text: messages[0] };
      }
      setErrors(server);
      const known: string[] = ["method", "sender_number", "transaction_id", "amount", "months", "proof"];
      if (!Object.keys(server).some((k) => known.includes(k))) setFailure(failureText(res, t));
      else setFailure(f.fixErrors);
      return;
    }
    setFailure(failureText(res, t));
  }

  const allSelected = dueMonths.length > 0 && dueMonths.slice(0, MAX_MONTHS).every((m) => selected.includes(m.month));

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{f.title}</h2>
      <p className="mt-1 text-sm text-muted">{f.subtitle}</p>

      {/* Fund receiving numbers + instructions from Fund Settings (Phase 7e). */}
      <ReceivingAccounts />

      <form noValidate onSubmit={(e) => void submit(e)} className="mt-5 space-y-5">
        {/* Months */}
        <fieldset aria-describedby={errors.months ? "pay-months-error" : undefined}>
          <legend className="text-sm font-medium text-foreground">{f.months}</legend>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs text-muted">{f.monthsHint}</p>
            <Button
              variant="ghost"
              size="sm"
              className="h-8 px-3"
              onClick={() => chooseMonths(allSelected ? [] : dueMonths.slice(0, MAX_MONTHS).map((m) => m.month))}
            >
              {allSelected ? f.clearMonths : dueMonths.length > MAX_MONTHS ? fill(f.selectFirst, { n: localizeDigits(String(MAX_MONTHS), locale) }) : f.selectAll}
            </Button>
          </div>
          <div className="mt-2 flex flex-wrap gap-2">
            {dueMonths.map((m) => {
              const on = selected.includes(m.month);
              return (
                <label
                  key={m.month}
                  className={`inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition has-focus-visible:outline-2 has-focus-visible:outline-primary ${
                    on ? "border-secondary bg-secondary text-white" : "border-field-border bg-field text-foreground hover:border-primary"
                  }`}
                >
                  <input
                    type="checkbox"
                    className="sr-only"
                    checked={on}
                    onChange={() => chooseMonths(on ? selected.filter((x) => x !== m.month) : [...selected, m.month])}
                  />
                  {on && <Icon name="checkCircle" className="size-4" />}
                  <span className="font-medium">{monthLabel(m.month, locale)}</span>
                  <span className={on ? "text-white/80" : "text-muted"}>{formatTaka(m.amount, locale)}</span>
                </label>
              );
            })}
          </div>
          {errorText("months") && (
            <p id="pay-months-error" className="mt-1 text-sm text-danger">
              {errorText("months")}
            </p>
          )}
          {selected.length > 0 && (
            <p className="mt-2 text-sm">
              {fill(f.selectedTotal, { n: localizeDigits(String(selected.length), locale), total: formatTaka(total, locale) })}
            </p>
          )}
        </fieldset>

        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={f.method}
            value={method}
            error={errorText("method")}
            onChange={(e) => {
              setMethod(e.target.value as PaymentMethod | "");
              clearError("method");
              clearError("sender_number");
              clearError("transaction_id");
            }}
          >
            <option value="">{f.chooseMethod}</option>
            {METHODS.map((m) => (
              <option key={m} value={m}>
                {t.payments.methods[m]}
              </option>
            ))}
          </Select>
          <Input
            label={f.amount}
            inputMode="numeric"
            value={amount}
            error={errorText("amount")}
            placeholder={f.amountPlaceholder}
            onChange={(e) => {
              setAmount(e.target.value);
              clearError("amount");
            }}
          />
          {isMobile && (
            <Input
              label={f.sender}
              type="tel"
              inputMode="tel"
              autoComplete="off"
              placeholder="01XXXXXXXXX"
              value={sender}
              error={errorText("sender_number")}
              onChange={(e) => {
                setSender(e.target.value);
                clearError("sender_number");
              }}
            />
          )}
          {needsTxid && (
            <Input
              label={f.txid}
              autoComplete="off"
              autoCapitalize="characters"
              spellCheck={false}
              maxLength={TXID_MAX + 10}
              placeholder={method === "bank" ? f.txidBankPlaceholder : f.txidPlaceholder}
              value={txid}
              error={errorText("transaction_id")}
              onChange={(e) => {
                setTxid(e.target.value);
                clearError("transaction_id");
              }}
            />
          )}
        </div>
        {method === "cash" && <p className="text-sm text-muted">{f.cashNote}</p>}

        {/* Screenshot */}
        <div>
          <p className="text-sm font-medium text-foreground">{f.proof}</p>
          <p className="mt-0.5 text-xs text-muted">{f.proofHint}</p>
          <input
            ref={fileRef}
            type="file"
            accept={PROOF_TYPES.join(",")}
            className="sr-only"
            id="pay-proof"
            aria-describedby={errors.proof ? "pay-proof-error" : undefined}
            onChange={(e) => {
              clearError("proof");
              crop.pick(e.target.files?.[0], "document", chooseFile);
              e.target.value = "";
            }}
          />
          {crop.dialog}
          {proof ? (
            <div className="mt-2 flex flex-wrap items-start gap-4 rounded-control border border-border bg-background p-3">
              <Image
                src={proof.url}
                alt={f.proofPreview}
                width={240}
                height={320}
                unoptimized
                className="h-40 w-auto max-w-full rounded-xl border border-border object-contain"
              />
              <div className="min-w-0 flex-1 text-sm">
                <p className="truncate font-medium text-heading">{proof.file.name}</p>
                <p className="text-muted">{formatSize(proof.file.size, locale)}</p>
                <div className="mt-3 flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()}>
                    <Icon name="upload" className="size-4" />
                    {f.replaceProof}
                  </Button>
                  <Button variant="danger" size="sm" onClick={() => setProof(null)}>
                    <Icon name="trash" className="size-4" />
                    {f.removeProof}
                  </Button>
                </div>
              </div>
            </div>
          ) : (
            <Button variant="outline" size="sm" className="mt-2" onClick={() => fileRef.current?.click()}>
              <Icon name="upload" className="size-4" />
              {f.chooseProof}
            </Button>
          )}
          {errorText("proof") && (
            <p id="pay-proof-error" className="mt-1 text-sm text-danger">
              {errorText("proof")}{" "}
              {/* The screenshot is optional: let the member drop the wrong file and send without one. */}
              {!proof && (
                <button type="button" className="font-semibold underline" onClick={() => clearError("proof")}>
                  {f.removeProof}
                </button>
              )}
            </p>
          )}
        </div>

        {failure && <Alert tone="danger">{failure}</Alert>}

        <div className="flex flex-wrap items-center gap-3 border-t border-border pt-5">
          <Button type="submit" disabled={busy}>
            <Icon name="banknote" className="size-4" />
            {busy ? f.sending : f.submit}
          </Button>
          <p className="text-sm text-muted">{f.afterSubmit}</p>
        </div>
      </form>
    </Card>
  );
}
