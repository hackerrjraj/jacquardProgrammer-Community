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
import { Textarea } from "@/components/ui/textarea";
import { failureText, formatSize } from "@/components/profile/profile-shared";
import { apiFetch, type AidReason, type AidRequest } from "@/lib/api";
import { fill, toAsciiDigits } from "@/lib/auth";
import { formatNumber, localizeDigits } from "@/lib/format";
import {
  AMOUNT_MAX,
  DESCRIPTION_MAX,
  FILE_MAX_BYTES,
  FILE_TYPES,
  FOR_NAME_MAX,
  FOR_RELATION_MAX,
  MAX_FILES,
  REASON_OTHER_MAX,
  REASONS,
} from "./assistance-shared";

type Field =
  | "reason_type"
  | "reason_other"
  | "amount_requested"
  | "description"
  | "applying_for_name"
  | "applying_for_relation"
  | "documents";
const FIELDS: readonly Field[] = [
  "reason_type",
  "reason_other",
  "amount_requested",
  "description",
  "applying_for_name",
  "applying_for_relation",
  "documents",
];
type ErrorCode = "required" | "amountWhole" | "amountRange" | "tooLong" | "relationRequired" | "tooMany";
// Client errors keep a code (so they follow a language switch); server errors are already bn/en.
type FieldError = { code: ErrorCode; max?: number } | { text: string };

type Chosen = { key: number; file: File; preview: string | null };

/** "Apply for assistance" form (MASTER_SPEC §29): reason, amount, description, optional "applying for", 0–5 files. */
export function AidForm({ onSubmitted }: { onSubmitted: (message: string) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const f = t.assistance.form;

  const [reason, setReason] = useState<AidReason | "">("");
  const [reasonOther, setReasonOther] = useState("");
  const [amount, setAmount] = useState("");
  const [description, setDescription] = useState("");
  const [forName, setForName] = useState("");
  const [forRelation, setForRelation] = useState("");
  const [consent, setConsent] = useState(false);
  const [files, setFiles] = useState<Chosen[]>([]);
  const [fileProblems, setFileProblems] = useState<string[]>([]);
  const [errors, setErrors] = useState<Partial<Record<Field, FieldError>>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const nextKey = useRef(1);

  // Free every image preview when the form goes away (removed files free theirs at once).
  const filesRef = useRef(files);
  useEffect(() => {
    filesRef.current = files;
  }, [files]);
  useEffect(() => {
    return () => {
      for (const c of filesRef.current) if (c.preview) URL.revokeObjectURL(c.preview);
    };
  }, []);

  const n = (v: number) => localizeDigits(String(v), locale);
  const errorText = (field: Field): string | undefined => {
    const e = errors[field];
    if (!e) return undefined;
    if ("text" in e) return e.text;
    return fill(f.errors[e.code], { max: e.code === "amountRange" ? formatNumber(AMOUNT_MAX, locale) : n(e.max ?? 0) });
  };
  const clearError = (field: Field) => setErrors((prev) => (prev[field] ? { ...prev, [field]: undefined } : prev));

  const crop = useImageCrop();

  function addFiles(list: FileList | File[] | null) {
    clearError("documents");
    const problems: string[] = [];
    const added: Chosen[] = [];
    for (const file of Array.from(list ?? [])) {
      if (filesRef.current.length + added.length >= MAX_FILES) {
        problems.push(fill(f.errors.tooMany, { max: n(MAX_FILES) }));
        break;
      }
      if (!FILE_TYPES.includes(file.type)) problems.push(fill(f.errors.badType, { name: file.name }));
      else if (file.size > FILE_MAX_BYTES)
        problems.push(fill(f.errors.tooBig, { name: file.name, size: formatSize(file.size, locale) }));
      else
        added.push({
          key: nextKey.current++,
          file,
          preview: file.type.startsWith("image/") ? URL.createObjectURL(file) : null,
        });
    }
    filesRef.current = [...filesRef.current, ...added];
    setFiles((prev) => [...prev, ...added]);
    setFileProblems(problems);
    if (fileRef.current) fileRef.current.value = "";
  }

  function removeFile(key: number) {
    const gone = files.find((c) => c.key === key);
    if (gone?.preview) URL.revokeObjectURL(gone.preview);
    setFiles((prev) => prev.filter((c) => c.key !== key));
    setFileProblems([]);
    clearError("documents");
  }

  const cleanAmount = () => toAsciiDigits(amount).replace(/[\s,]/g, "");

  function validate(): Partial<Record<Field, FieldError>> {
    const out: Partial<Record<Field, FieldError>> = {};
    if (!reason) out.reason_type = { code: "required" };
    if (reason === "other") {
      if (!reasonOther.trim()) out.reason_other = { code: "required" };
      else if (reasonOther.trim().length > REASON_OTHER_MAX) out.reason_other = { code: "tooLong", max: REASON_OTHER_MAX };
    }
    const value = cleanAmount();
    if (!value) out.amount_requested = { code: "required" };
    else if (!/^\d+$/.test(value)) out.amount_requested = { code: "amountWhole" };
    else if (Number(value) < 1 || Number(value) > AMOUNT_MAX) out.amount_requested = { code: "amountRange" };
    if (!description.trim()) out.description = { code: "required" };
    else if (description.trim().length > DESCRIPTION_MAX) out.description = { code: "tooLong", max: DESCRIPTION_MAX };
    if (forName.trim().length > FOR_NAME_MAX) out.applying_for_name = { code: "tooLong", max: FOR_NAME_MAX };
    if (forName.trim() && !forRelation.trim()) out.applying_for_relation = { code: "relationRequired" };
    else if (forRelation.trim().length > FOR_RELATION_MAX)
      out.applying_for_relation = { code: "tooLong", max: FOR_RELATION_MAX };
    return out;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setFailure(null);
    const found = validate();
    setErrors(found);
    if (Object.values(found).some(Boolean)) {
      setFailure(f.fixErrors);
      return;
    }

    const body = new FormData();
    body.append("reason_type", reason);
    if (reason === "other") body.append("reason_other", reasonOther.trim());
    body.append("amount_requested", cleanAmount());
    body.append("description", description.trim());
    if (forName.trim()) {
      body.append("applying_for_name", forName.trim());
      body.append("applying_for_relation", forRelation.trim());
    }
    if (consent) body.append("consent_public_name", "1");
    for (const c of files) body.append("documents[]", c.file);

    setBusy(true);
    const res = await apiFetch<{ message: string; data: AidRequest }>("/assistance", { method: "POST", body });
    setBusy(false);
    if (res.ok) {
      onSubmitted(res.data.message);
      return;
    }
    if (res.status === 422 && res.data?.errors) {
      // "documents.0" → documents
      const server: Partial<Record<Field, FieldError>> = {};
      for (const [key, messages] of Object.entries(res.data.errors)) {
        const field = key.split(".")[0] as Field;
        if (FIELDS.includes(field) && !server[field] && messages[0]) server[field] = { text: messages[0] };
      }
      setErrors(server);
      setFailure(Object.keys(server).length ? f.fixErrors : failureText(res, t));
      return;
    }
    setFailure(failureText(res, t));
  }

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{f.title}</h2>
      <p className="mt-1 text-sm text-muted">{f.subtitle}</p>

      <form noValidate onSubmit={(e) => void submit(e)} className="mt-5 space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={f.reason}
            value={reason}
            error={errorText("reason_type")}
            onChange={(e) => {
              setReason(e.target.value as AidReason | "");
              clearError("reason_type");
              clearError("reason_other");
            }}
          >
            <option value="">{f.chooseReason}</option>
            {REASONS.map((r) => (
              <option key={r} value={r}>
                {t.assistance.reasons[r]}
              </option>
            ))}
          </Select>
          <div>
            <Input
              label={f.amount}
              inputMode="numeric"
              autoComplete="off"
              value={amount}
              placeholder={f.amountPlaceholder}
              error={errorText("amount_requested")}
              onChange={(e) => {
                setAmount(e.target.value);
                clearError("amount_requested");
              }}
            />
            {!errors.amount_requested && <p className="mt-1 text-xs text-muted">{f.amountHint}</p>}
          </div>
          {reason === "other" && (
            <Input
              label={f.reasonOther}
              className="sm:col-span-2"
              value={reasonOther}
              maxLength={REASON_OTHER_MAX}
              placeholder={f.reasonOtherPlaceholder}
              error={errorText("reason_other")}
              onChange={(e) => {
                setReasonOther(e.target.value);
                clearError("reason_other");
              }}
            />
          )}
        </div>

        <div>
          <Textarea
            label={f.description}
            rows={5}
            value={description}
            maxLength={DESCRIPTION_MAX}
            placeholder={f.descriptionPlaceholder}
            error={errorText("description")}
            onChange={(e) => {
              setDescription(e.target.value);
              clearError("description");
            }}
          />
          <p className="mt-1 text-right text-xs text-muted">
            {fill(f.chars, { n: n(description.length), max: n(DESCRIPTION_MAX) })}
          </p>
        </div>

        <fieldset className="rounded-control border border-border bg-background p-4">
          <legend className="px-1 text-sm font-medium text-foreground">{f.forTitle}</legend>
          <p className="text-xs text-muted">{f.forHint}</p>
          <div className="mt-3 grid gap-4 sm:grid-cols-2">
            <Input
              label={f.forName}
              value={forName}
              maxLength={FOR_NAME_MAX}
              error={errorText("applying_for_name")}
              onChange={(e) => {
                setForName(e.target.value);
                clearError("applying_for_name");
                clearError("applying_for_relation");
              }}
            />
            <Input
              label={f.forRelation}
              value={forRelation}
              maxLength={FOR_RELATION_MAX}
              placeholder={f.forRelationPlaceholder}
              error={errorText("applying_for_relation")}
              onChange={(e) => {
                setForRelation(e.target.value);
                clearError("applying_for_relation");
              }}
            />
          </div>
        </fieldset>

        {/* Consent to show the name on the public Fund Report (§29 / §33) — unticked by default */}
        <div>
          <label className="flex items-start gap-2 text-sm font-medium text-foreground">
            <input
              type="checkbox"
              checked={consent}
              onChange={(e) => setConsent(e.target.checked)}
              aria-describedby="aid-consent-hint"
              className="mt-0.5 size-4 shrink-0 accent-primary"
            />
            {f.consent}
          </label>
          <p id="aid-consent-hint" className="mt-1 ml-6 text-xs text-muted">
            {f.consentHint}
          </p>
        </div>

        {/* Supporting files */}
        <div>
          <p className="text-sm font-medium text-foreground">{f.files}</p>
          <p className="mt-0.5 text-xs text-muted">{fill(f.filesHint, { max: n(MAX_FILES) })}</p>
          <input
            ref={fileRef}
            type="file"
            multiple
            accept={[...FILE_TYPES, ".jpg", ".jpeg", ".png", ".webp", ".pdf"].join(",")}
            className="sr-only"
            id="aid-files"
            aria-describedby={errors.documents || fileProblems.length ? "aid-files-error" : undefined}
            onChange={(e) => {
              crop.pick(e.target.files, "document", (file) => addFiles([file]));
              e.target.value = "";
            }}
          />
          {crop.dialog}
          {files.length > 0 && (
            <ul className="mt-2 grid gap-2 sm:grid-cols-2">
              {files.map((c) => (
                <li key={c.key} className="flex items-center gap-3 rounded-control border border-border bg-background p-2.5">
                  {c.preview ? (
                    <Image
                      src={c.preview}
                      alt={c.file.name}
                      width={56}
                      height={56}
                      unoptimized
                      className="size-14 shrink-0 rounded-lg border border-border object-cover"
                    />
                  ) : (
                    <span className="flex size-14 shrink-0 flex-col items-center justify-center rounded-lg border border-border bg-danger-soft text-danger">
                      <Icon name="fileText" className="size-5" />
                      <span className="text-[0.65rem] font-bold">{f.pdf}</span>
                    </span>
                  )}
                  <div className="min-w-0 flex-1 text-sm">
                    <p className="truncate font-medium text-heading" title={c.file.name}>
                      {c.file.name}
                    </p>
                    <p className="text-muted">{formatSize(c.file.size, locale)}</p>
                  </div>
                  <Button
                    variant="danger"
                    size="sm"
                    className="px-3"
                    aria-label={`${f.removeFile}: ${c.file.name}`}
                    onClick={() => removeFile(c.key)}
                  >
                    <Icon name="trash" className="size-4" />
                    <span className="hidden sm:inline">{f.removeFile}</span>
                  </Button>
                </li>
              ))}
            </ul>
          )}
          <div className="mt-2 flex flex-wrap items-center gap-3">
            {files.length < MAX_FILES && (
              <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()}>
                <Icon name="upload" className="size-4" />
                {f.addFiles}
              </Button>
            )}
            {files.length > 0 && (
              <span className="text-sm text-muted">{fill(f.filesCount, { n: n(files.length), max: n(MAX_FILES) })}</span>
            )}
          </div>
          {(fileProblems.length > 0 || errorText("documents")) && (
            <div id="aid-files-error" className="mt-1 space-y-0.5 text-sm text-danger">
              {fileProblems.map((p) => (
                <p key={p}>{p}</p>
              ))}
              {errorText("documents") && <p>{errorText("documents")}</p>}
            </div>
          )}
        </div>

        {failure && <Alert tone="danger">{failure}</Alert>}

        <div className="flex flex-wrap items-center gap-3 border-t border-border pt-5">
          <Button type="submit" disabled={busy}>
            <Icon name="heart" className="size-4" />
            {busy ? f.sending : f.submit}
          </Button>
          <p className="text-sm text-muted">{f.afterSubmit}</p>
        </div>
      </form>
    </Card>
  );
}
