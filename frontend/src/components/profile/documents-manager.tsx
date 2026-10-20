"use client";

import { useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { apiFetch, type MemberDocument, type Profile, type ProfileOptions } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { DocumentList } from "./profile-view";
import {
  DOCUMENTS_MAX,
  DOCUMENT_MAX_BYTES,
  DOCUMENT_TYPES,
  checkFile,
  failureText,
  fieldErrors,
  formatSize,
  optionLabel,
} from "./profile-shared";

/** Private documents (PDF/JPG/PNG, max 5 MB, max 20): upload with a type, list, download, delete. */
export function DocumentsManager({
  profile,
  options,
  onSaved,
}: {
  profile: Profile;
  options: ProfileOptions | null;
  onSaved: (p: Profile) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.profile.documents;
  const docs = profile.documents;
  const digits = (n: number) => localizeDigits(String(n), locale);
  const [type, setType] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<{ type?: string; document?: string }>({});
  const [flash, setFlash] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<MemberDocument | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const full = docs.length >= DOCUMENTS_MAX;

  const crop = useImageCrop();

  function choose(e: React.ChangeEvent<HTMLInputElement>) {
    const chosen = e.target.files?.[0] ?? null;
    setErrors((er) => ({ ...er, document: undefined }));
    e.target.value = "";
    if (!chosen) return setFile(null);
    crop.pick(chosen, "document", accept); // images: free crop first; PDF unchanged
  }

  function accept(chosen: File) {
    const problem = checkFile(chosen, DOCUMENT_TYPES, DOCUMENT_MAX_BYTES, d);
    if (problem) {
      setFile(null);
      setErrors((er) => ({ ...er, document: problem }));
      return;
    }
    setFile(chosen);
  }

  async function upload(e: React.FormEvent) {
    e.preventDefault();
    const found: typeof errors = {};
    if (!type) found.type = t.auth.errors.required;
    if (!file) found.document = errors.document ?? t.auth.errors.required;
    setErrors(found);
    if (found.type || found.document || !file) return;

    setBusy(true);
    setFlash(null);
    const body = new FormData();
    body.append("type", type);
    body.append("document", file);
    const res = await apiFetch<{ message: string; data: MemberDocument }>("/profile/documents", { method: "POST", body });
    setBusy(false);
    if (res.ok) {
      onSaved({ ...profile, documents: [...docs, res.data.data] });
      setType("");
      setFile(null);
      if (inputRef.current) inputRef.current.value = "";
      setFlash({ tone: "success", text: res.data.message });
      return;
    }
    const server = fieldErrors(res);
    setErrors({ type: server.type, document: server.document });
    setFlash({ tone: "danger", text: server.type || server.document ? t.auth.fixErrors : failureText(res, t) });
  }

  async function remove(doc: MemberDocument) {
    setBusy(true);
    const res = await apiFetch<{ message: string }>(`/profile/documents/${doc.id}`, { method: "DELETE" });
    setBusy(false);
    setDeleting(null);
    if (res.ok) {
      onSaved({ ...profile, documents: docs.filter((x) => x.id !== doc.id) });
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setFlash({ tone: "danger", text: failureText(res, t) });
    }
  }

  return (
    <div className="space-y-6">
      {crop.dialog}
      <Card className="p-5 sm:p-6">
        <h3 className="text-lg font-semibold">{d.title}</h3>
        <p className="mt-1 text-sm text-muted">{fill(d.intro, { max: digits(DOCUMENTS_MAX) })}</p>
        {full ? (
          <p className="mt-4 text-sm font-medium">{fill(d.max, { max: digits(DOCUMENTS_MAX) })}</p>
        ) : (
          <form onSubmit={upload} noValidate className="mt-4 grid gap-4 sm:grid-cols-2 sm:items-start">
            <Select label={d.type} value={type} onChange={(e) => setType(e.target.value)} error={errors.type}>
              <option value="">{t.profile.basic.choose}</option>
              {options?.document_types.map((o) => (
                <option key={o.key} value={o.key}>
                  {locale === "bn" ? o.name_bn : o.name_en}
                </option>
              ))}
            </Select>
            <div>
              <span className="mb-1.5 block text-sm font-medium text-foreground">{d.file}</span>
              <label className="flex h-11 cursor-pointer items-center gap-2 rounded-control border border-dashed border-field-border bg-field px-3 text-sm hover:border-primary focus-within:outline-2 focus-within:outline-primary">
                <Icon name="upload" className="size-4 shrink-0 text-primary-text" />
                <span className="min-w-0 truncate">
                  {file ? `${file.name} (${formatSize(file.size, locale)})` : d.choose}
                </span>
                <input ref={inputRef} type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={choose} className="sr-only" />
              </label>
              <p className="mt-1 text-sm text-muted">{d.hint}</p>
              {errors.document && <p className="mt-1 text-sm text-danger">{errors.document}</p>}
            </div>
            <div className="sm:col-span-2">
              <Button type="submit" disabled={busy}>
                {busy ? d.uploading : d.upload}
              </Button>
            </div>
          </form>
        )}
        {flash && (
          <Alert tone={flash.tone} className="mt-4">
            {flash.text}
          </Alert>
        )}
      </Card>

      <DocumentList documents={docs} options={options} onDelete={setDeleting} />

      {deleting && (
        <Modal title={d.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <p>
            {fill(d.deleteText, {
              name: `${optionLabel(options?.document_types, deleting.type, locale)} — ${deleting.original_name ?? ""}`,
            })}
          </p>
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setDeleting(null)}>
              {t.profile.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? t.profile.saving : t.profile.delete}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}
