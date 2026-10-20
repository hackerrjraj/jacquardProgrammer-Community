"use client";

import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useDistricts } from "@/components/auth/use-districts";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch, type Profile, type ProfileOptions, type Visibility } from "@/lib/api";
import { EMAIL_RE, errorText, toAsciiDigits, validatePicks, type FieldError } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { ChoicePicker } from "./choice-picker";
import { Avatar } from "./profile-view";
import { PHOTO_MAX_BYTES, PHOTO_TYPES, checkFile, failureText, fieldErrors } from "./profile-shared";

type Flash = { tone: "success" | "danger"; text: string } | null;
type Save = (profile: Profile) => void;

/** Photo upload with preview (JPG/PNG/WebP, max 2 MB). */
export function PhotoForm({ profile, onSaved }: { profile: Profile; onSaved: Save }) {
  const t = useDictionary();
  const p = t.profile.photo;
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [flash, setFlash] = useState<Flash>(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  // Free the preview's memory when it changes or the form closes.
  useEffect(() => {
    if (!preview) return;
    return () => URL.revokeObjectURL(preview);
  }, [preview]);

  const crop = useImageCrop();

  function choose(e: React.ChangeEvent<HTMLInputElement>) {
    setFlash(null);
    crop.pick(e.target.files?.[0], "profilePhoto", accept);
    e.target.value = "";
  }

  function accept(chosen: File) {
    const problem = checkFile(chosen, PHOTO_TYPES, PHOTO_MAX_BYTES, p);
    if (problem) {
      setFile(null);
      setPreview(null);
      setFlash({ tone: "danger", text: problem });
      return;
    }
    setFile(chosen);
    setPreview(URL.createObjectURL(chosen));
  }

  async function upload() {
    if (!file) return;
    setBusy(true);
    const body = new FormData();
    body.append("photo", file);
    const res = await apiFetch<{ message: string; data: Profile }>("/profile/photo", { method: "POST", body });
    setBusy(false);
    if (res.ok) {
      onSaved(res.data.data);
      setFile(null);
      setPreview(null);
      if (inputRef.current) inputRef.current.value = "";
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setFlash({ tone: "danger", text: fieldErrors(res).photo ?? failureText(res, t) });
    }
  }

  return (
    <Card className="p-5 sm:p-6">
      <h3 className="text-lg font-semibold">{p.title}</h3>
      <div className="mt-4 flex flex-col gap-5 sm:flex-row sm:items-center">
        {preview ? (
          <span className="relative block size-28 shrink-0 overflow-hidden rounded-2xl">
            {/* Local preview of the chosen file (blob: URL), not served by the API */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={preview} alt="" className="size-full object-cover" />
          </span>
        ) : (
          <Avatar name={profile.name} url={profile.photo_url} className="size-28" />
        )}
        <div className="min-w-0 flex-1">
          <p className="text-sm text-muted">{p.hint}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <label className="inline-flex h-10 cursor-pointer items-center rounded-full border border-border bg-surface px-5 text-sm font-semibold text-secondary hover:border-primary hover:text-primary-text focus-within:outline-2 focus-within:outline-primary">
              {profile.photo_url || preview ? p.change : p.choose}
              <input ref={inputRef} type="file" accept=".jpg,.jpeg,.png,.webp" onChange={choose} className="sr-only" />
            </label>
            {file && (
              <Button onClick={() => void upload()} disabled={busy}>
                {busy ? p.uploading : p.upload}
              </Button>
            )}
          </div>
        </div>
      </div>
      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}
      {crop.dialog}
    </Card>
  );
}

type Values = {
  email: string;
  employment_status: string;
  designation: string;
  district: string;
  workplace: string;
  experience_years: string;
  /** List keys (Phase 11d-1) */
  machines: string[];
  skills: string[];
  /** "Other" text: null = not chosen */
  other_machine: string | null;
  other_skill: string | null;
  bio: string;
  phone_visibility: Visibility;
  email_visibility: Visibility;
  workplace_visibility: Visibility;
};

function valuesOf(profile: Profile): Values {
  return {
    email: profile.email ?? "",
    employment_status: profile.employment_status ?? "",
    designation: profile.designation ?? "",
    district: profile.district ?? "",
    workplace: profile.workplace ?? "",
    experience_years: profile.experience_years === null ? "" : String(profile.experience_years),
    machines: profile.machines.map((m) => m.key),
    skills: profile.skills.map((s) => s.key),
    other_machine: profile.other_machine?.trim() ? profile.other_machine : null,
    other_skill: profile.other_skill?.trim() ? profile.other_skill : null,
    bio: profile.bio ?? "",
    phone_visibility: profile.phone_visibility,
    email_visibility: profile.email_visibility,
    workplace_visibility: profile.workplace_visibility,
  };
}

/** What PUT /api/profile gets for the form values (all fields, normalised). */
function payloadOf(v: Values): Record<string, unknown> {
  return {
    email: v.email.trim() || null,
    employment_status: v.employment_status || null,
    designation: v.designation.trim() || null,
    district: v.district || null,
    workplace: v.workplace.trim(),
    experience_years: v.experience_years.trim() === "" ? null : Number(toAsciiDigits(v.experience_years.trim())),
    machines: v.machines,
    skills: v.skills,
    other_machine: v.other_machine?.trim() || null,
    other_skill: v.other_skill?.trim() || null,
    bio: v.bio.trim() || null,
    phone_visibility: v.phone_visibility,
    email_visibility: v.email_visibility,
    workplace_visibility: v.workplace_visibility,
  };
}

type Errors = Partial<Record<keyof Values, FieldError>>;

function validate(v: Values): Errors {
  const e: Errors = {};
  if (v.email.trim() && !EMAIL_RE.test(v.email.trim())) e.email = { code: "email" };
  if (v.designation.trim().length > 100) e.designation = { code: "tooLong", n: 100 };
  if (!v.workplace.trim()) e.workplace = { code: "required" };
  else if (v.workplace.trim().length > 150) e.workplace = { code: "tooLong", n: 150 };
  const years = toAsciiDigits(v.experience_years.trim());
  if (!/^\d+$/.test(years) || Number(years) > 60) e.experience_years = { code: "experience" };
  e.machines = validatePicks(v.machines, v.other_machine);
  e.skills = validatePicks(v.skills, v.other_skill);
  if (v.bio.trim().length > 1000) e.bio = { code: "tooLong", n: 1000 };
  if (!v.district) e.district = { code: "required" };
  if (!v.employment_status) e.employment_status = { code: "required" };
  if (v.experience_years.trim() === "") e.experience_years = { code: "required" };
  for (const key of Object.keys(e) as (keyof Values)[]) if (!e[key]) delete e[key];
  return e;
}

/** Basic fields + visibility (PUT /api/profile, only the changed fields are sent). */
export function BasicForm({ profile, options, onSaved }: { profile: Profile; options: ProfileOptions | null; onSaved: Save }) {
  const t = useDictionary();
  const locale = useLocale();
  const b = t.profile.basic;
  const v10n = t.profile.visibility;
  const { districts } = useDistricts();
  const [values, setValues] = useState<Values>(() => valuesOf(profile));
  const [errors, setErrors] = useState<Errors>({});
  const [flash, setFlash] = useState<Flash>(null);
  const [busy, setBusy] = useState(false);

  const set = (field: keyof Values) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
    setValues((v) => ({ ...v, [field]: e.target.value }));
    setErrors((er) => ({ ...er, [field]: undefined }));
  };
  const err = (field: keyof Values) => errorText(errors[field], t, locale);
  const pick = (field: "machines" | "skills") => (keys: string[]) => {
    setValues((v) => ({ ...v, [field]: keys }));
    setErrors((er) => ({ ...er, [field]: undefined }));
  };

  const pickOther = (field: "other_machine" | "other_skill", list: "machines" | "skills") => (text: string | null) => {
    setValues((v) => ({ ...v, [field]: text }));
    setErrors((er) => ({ ...er, [list]: undefined }));
  };

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFlash(null);
    // Only changed fields are checked and sent — an old empty field (e.g. no district yet)
    // must not block changing something else.
    const before = payloadOf(valuesOf(profile));
    const after = payloadOf(values);
    const changed = Object.fromEntries(
      Object.entries(after).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(before[key])),
    );
    const found = Object.fromEntries(
      Object.entries(validate(values)).filter(([key]) => key in changed),
    ) as Errors;
    setErrors(found);
    if (Object.keys(found).length) {
      setFlash({ tone: "danger", text: t.auth.fixErrors });
      return;
    }
    if (Object.keys(changed).length === 0) {
      setFlash({ tone: "success", text: b.nothingChanged });
      return;
    }
    setBusy(true);
    const res = await apiFetch<{ message: string; data: Profile }>("/profile", { method: "PUT", body: changed });
    setBusy(false);
    if (res.ok) {
      onSaved(res.data.data);
      setValues(valuesOf(res.data.data));
      setFlash({ tone: "success", text: res.data.message });
      return;
    }
    const server = fieldErrors(res);
    const mapped: Errors = {};
    for (const [key, text] of Object.entries(server)) {
      const field = key.split(".")[0] as keyof Values;
      if (!mapped[field]) mapped[field] = { code: "server", text };
    }
    setErrors(mapped);
    setFlash({ tone: "danger", text: Object.keys(mapped).length ? t.auth.fixErrors : failureText(res, t) });
  }

  const visibilityOptions = (["public", "members", "hidden"] as const).map((key) => (
    <option key={key} value={key}>
      {v10n[key]}
    </option>
  ));

  return (
    <form onSubmit={onSubmit} noValidate>
      <Card className="p-5 sm:p-6">
        <h3 className="text-lg font-semibold">{b.title}</h3>
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <Input label={b.name} value={profile.name} disabled readOnly />
          <Input label={b.phone} value={profile.phone ?? ""} disabled readOnly />
          <p className="-mt-2 text-sm text-muted sm:col-span-2">{b.nameNote}</p>
          <Input label={b.email} type="email" value={values.email} onChange={set("email")} error={err("email")} maxLength={255} />
          <Select label={b.employmentStatus} value={values.employment_status} onChange={set("employment_status")} error={err("employment_status")}>
            <option value="">{b.choose}</option>
            {options?.employment_statuses.map((o) => (
              <option key={o.key} value={o.key}>
                {locale === "bn" ? o.name_bn : o.name_en}
              </option>
            ))}
          </Select>
          <Input label={b.designation} value={values.designation} onChange={set("designation")} error={err("designation")} maxLength={100} placeholder={b.designationPlaceholder} />
          <Select label={b.district} value={values.district} onChange={set("district")} error={err("district")}>
            <option value="">{b.choose}</option>
            {districts?.map((d) => (
              <option key={d.key} value={d.key}>
                {locale === "bn" ? d.name_bn : d.name_en}
              </option>
            ))}
          </Select>
          <Input label={b.workplace} value={values.workplace} onChange={set("workplace")} error={err("workplace")} maxLength={150} />
          <Input
            label={b.experience}
            inputMode="numeric"
            value={values.experience_years}
            onChange={set("experience_years")}
            error={err("experience_years")}
            maxLength={2}
          />
          <div className="sm:col-span-2">
            <ChoicePicker
              label={b.machines}
              options={options?.machines ?? null}
              saved={profile.machines}
              value={values.machines}
              onChange={pick("machines")}
              other={values.other_machine}
              onOtherChange={pickOther("other_machine", "machines")}
              error={err("machines")}
            />
          </div>
          <div className="sm:col-span-2">
            <ChoicePicker
              label={b.skills}
              options={options?.skills ?? null}
              saved={profile.skills}
              value={values.skills}
              onChange={pick("skills")}
              other={values.other_skill}
              onOtherChange={pickOther("other_skill", "skills")}
              error={err("skills")}
            />
          </div>
          <div className="sm:col-span-2">
            <Textarea label={b.bio} value={values.bio} onChange={set("bio")} error={err("bio")} maxLength={1000} rows={5} />
            <p className="mt-1 text-right text-xs text-muted">
              {localizeDigits(`${values.bio.length} / 1000`, locale)}
            </p>
          </div>
        </div>

        <h3 className="mt-8 text-lg font-semibold">{v10n.title}</h3>
        <p className="mt-1 text-sm text-muted">{v10n.intro}</p>
        <div className="mt-4 grid gap-4 sm:grid-cols-3">
          <Select label={v10n.phone} value={values.phone_visibility} onChange={set("phone_visibility")} error={err("phone_visibility")}>
            {visibilityOptions}
          </Select>
          <Select label={v10n.email} value={values.email_visibility} onChange={set("email_visibility")} error={err("email_visibility")}>
            {visibilityOptions}
          </Select>
          <Select label={v10n.workplace} value={values.workplace_visibility} onChange={set("workplace_visibility")} error={err("workplace_visibility")}>
            {visibilityOptions}
          </Select>
        </div>

        {flash && (
          <Alert tone={flash.tone} className="mt-6">
            {flash.text}
          </Alert>
        )}
        <div className="mt-6">
          <Button type="submit" size="lg" disabled={busy} className="w-full sm:w-auto">
            {busy ? b.saving : b.save}
          </Button>
        </div>
      </Card>
    </form>
  );
}
