"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { apiFetch } from "@/lib/api";
import {
  EMAIL_RE,
  PASSWORD_MAX,
  PASSWORD_MIN,
  PHONE_RE,
  errorText,
  homeFor,
  normalizePhone,
  serverErrors,
  toAsciiDigits,
  validatePicks,
  type FieldError,
} from "@/lib/auth";
import { ChoicePicker, useChoiceLists } from "@/components/profile/choice-picker";
import { useAuth } from "./auth-provider";
import { useDistricts } from "./use-districts";
import { TermsDialog, useRegistrationTerms } from "./registration-terms";

const FIELDS = [
  "name",
  "phone",
  "email",
  "password",
  "password_confirmation",
  "district",
  "workplace",
  "experience_years",
  "machines",
  "skills",
] as const;
type Field = (typeof FIELDS)[number];
/** Machines + skills are list keys (Phase 11d-1); every other field is text. */
type PickField = "machines" | "skills";
/** other_machine / other_skill: null = "Other" not chosen, text = chosen. */
type Values = Record<Exclude<Field, PickField>, string> & Record<PickField, string[]> & Record<"other_machine" | "other_skill", string | null>;
type Errors = Partial<Record<Field, FieldError>>;

const EMPTY: Values = {
  ...(Object.fromEntries(FIELDS.map((f) => [f, ""])) as Record<Exclude<Field, PickField>, string>),
  machines: [],
  skills: [],
  other_machine: null,
  other_skill: null,
};

// Same limits as the backend (RegisterRequest).
const MAX = { name: 100, email: 255, workplace: 150 } as const;

function validate(v: Values): Errors {
  const e: Errors = {};
  const required = (f: Exclude<Field, PickField>) => {
    if (!v[f].trim()) e[f] = { code: "required" };
    return !e[f];
  };

  if (required("name") && v.name.trim().length > MAX.name) e.name = { code: "tooLong", n: MAX.name };
  if (required("phone") && !PHONE_RE.test(normalizePhone(v.phone))) e.phone = { code: "phone" };

  const email = v.email.trim();
  if (email && !EMAIL_RE.test(email)) e.email = { code: "email" };
  else if (email.length > MAX.email) e.email = { code: "tooLong", n: MAX.email };

  if (!v.password) e.password = { code: "required" };
  else if (v.password.length < PASSWORD_MIN) e.password = { code: "passwordMin", n: PASSWORD_MIN };
  else if (v.password.length > PASSWORD_MAX) e.password = { code: "tooLong", n: PASSWORD_MAX };
  if (!v.password_confirmation) e.password_confirmation = { code: "required" };
  else if (v.password && v.password !== v.password_confirmation)
    e.password_confirmation = { code: "passwordMismatch" };

  required("district");
  if (required("workplace") && v.workplace.trim().length > MAX.workplace)
    e.workplace = { code: "tooLong", n: MAX.workplace };

  if (required("experience_years")) {
    const years = toAsciiDigits(v.experience_years.trim());
    if (!/^\d+$/.test(years) || Number(years) > 60) e.experience_years = { code: "experience" };
  }

  const machines = validatePicks(v.machines, v.other_machine);
  if (machines) e.machines = machines;
  const skills = validatePicks(v.skills, v.other_skill);
  if (skills) e.skills = skills;
  return e;
}

export function RegisterForm() {
  const t = useDictionary();
  const locale = useLocale();
  const router = useRouter();
  const { user } = useAuth();
  const { districts, failed: districtsFailed, retry } = useDistricts();
  const { lists, failed: listsFailed, retry: retryLists } = useChoiceLists();
  const [values, setValues] = useState<Values>(EMPTY);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const { terms } = useRegistrationTerms();
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [termsView, setTermsView] = useState(false);
  const termsActive = Boolean(terms?.active);

  const r = t.register;
  const err = (f: Field) => errorText(errors[f], t, locale);

  // Already logged in → nothing to register.
  useEffect(() => {
    if (user) router.replace(homeFor(user));
  }, [user, router]);

  function pick(field: PickField) {
    return (keys: string[]) => {
      setValues((v) => ({ ...v, [field]: keys }));
      setErrors((errs) => ({ ...errs, [field]: undefined }));
    };
  }

  function update(field: Exclude<Field, PickField>) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
      setValues((v) => ({ ...v, [field]: e.target.value }));
      setErrors((errs) => ({ ...errs, [field]: undefined }));
    };
  }

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (sending) return;

    const clientErrors = validate(values);
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length > 0) {
      setFormError(t.auth.fixErrors);
      return;
    }

    setSending(true);
    setFormError(null);
    const res = await apiFetch<{ message: string }>("/register", {
      method: "POST",
      locale,
      body: {
        name: values.name.trim(),
        phone: normalizePhone(values.phone),
        email: values.email.trim() || null,
        password: values.password,
        password_confirmation: values.password_confirmation,
        district: values.district,
        workplace: values.workplace.trim(),
        experience_years: Number(toAsciiDigits(values.experience_years.trim())),
        machines: values.machines,
        skills: values.skills,
        other_machine: values.other_machine?.trim() || null,
        other_skill: values.other_skill?.trim() || null,
        ...(termsActive ? { terms_accepted: termsAccepted } : {}),
      },
    });
    setSending(false);

    if (res.ok) {
      setDone(res.data.message);
      window.scrollTo({ top: 0 });
    } else if (res.status === 422 && res.data?.errors) {
      setErrors(serverErrors(res.data.errors, FIELDS));
      const termsError = (res.data.errors as Record<string, string[]>).terms_accepted?.[0];
      if (termsError) setTermsAccepted(false); // popup opens again
      setFormError(termsError ?? t.auth.fixErrors);
    } else if (res.status === 0) {
      setFormError(t.auth.networkError);
    } else {
      // 429 (too many registrations) and others: the API's own bn/en message
      setFormError(res.data?.message || t.auth.genericError);
    }
  }

  if (done) {
    return (
      <Card className="relative mx-auto -mt-16 shadow-panel! max-w-lg text-center">
        <div
          aria-hidden="true"
          className="mx-auto flex size-14 items-center justify-center rounded-full bg-success/10 text-2xl text-success"
        >
          ✓
        </div>
        <h2 className="mt-4 text-xl font-semibold">{r.successTitle}</h2>
        <Alert tone="success" className="mt-4 text-left">
          {done}
        </Alert>
        <p className="mt-4 text-muted">{r.successText}</p>
        <ButtonLink href="/" variant="outline" className="mt-6">
          {r.successHome}
        </ButtonLink>
      </Card>
    );
  }

  return (
    <Card className="relative mx-auto -mt-16 shadow-panel! max-w-2xl p-5 sm:p-8">
      {terms && termsActive && !termsAccepted && (
        <TermsDialog terms={terms} onAccept={() => setTermsAccepted(true)} onBack={() => router.push("/")} />
      )}
      {terms && termsActive && termsView && <TermsDialog terms={terms} onClose={() => setTermsView(false)} />}
      <form onSubmit={onSubmit} noValidate className="grid gap-4 sm:grid-cols-2">
        <h2 className="text-lg font-semibold sm:col-span-2">{r.sectionAccount}</h2>
        <Input
          label={r.name}
          name="name"
          autoComplete="name"
          value={values.name}
          onChange={update("name")}
          error={err("name")}
          maxLength={MAX.name}
          className="sm:col-span-2"
          required
        />
        <div>
          <Input
            label={r.phone}
            name="phone"
            type="tel"
            inputMode="tel"
            autoComplete="tel-national"
            placeholder={r.phonePlaceholder}
            value={values.phone}
            onChange={update("phone")}
            error={err("phone")}
            maxLength={20}
            required
          />
          {!errors.phone && <p className="mt-1 text-sm text-muted">{r.phoneHint}</p>}
        </div>
        <Input
          label={r.email}
          name="email"
          type="email"
          autoComplete="email"
          value={values.email}
          onChange={update("email")}
          error={err("email")}
          maxLength={MAX.email}
        />
        <div>
          <Input
            label={r.password}
            name="password"
            type={showPassword ? "text" : "password"}
            autoComplete="new-password"
            value={values.password}
            onChange={update("password")}
            error={err("password")}
            maxLength={PASSWORD_MAX}
            required
          />
          {!errors.password && <p className="mt-1 text-sm text-muted">{r.passwordHint}</p>}
        </div>
        <Input
          label={r.passwordConfirm}
          name="password_confirmation"
          type={showPassword ? "text" : "password"}
          autoComplete="new-password"
          value={values.password_confirmation}
          onChange={update("password_confirmation")}
          error={err("password_confirmation")}
          maxLength={PASSWORD_MAX}
          required
        />
        <label className="flex items-center gap-2 text-sm sm:col-span-2">
          <input
            type="checkbox"
            checked={showPassword}
            onChange={(e) => setShowPassword(e.target.checked)}
            className="size-4 accent-primary"
          />
          {t.auth.showPassword}
        </label>

        <h2 className="mt-4 text-lg font-semibold sm:col-span-2">{r.sectionWork}</h2>
        <div>
          <Select
            label={r.district}
            name="district"
            value={values.district}
            onChange={update("district")}
            error={err("district")}
            disabled={!districts}
            required
          >
            <option value="">{districts ? r.districtPlaceholder : t.auth.loading}</option>
            {districts?.map((d) => (
              <option key={d.key} value={d.key}>
                {locale === "bn" ? d.name_bn : d.name_en}
              </option>
            ))}
          </Select>
          {districtsFailed && (
            <p className="mt-1 text-sm text-danger">
              {r.districtsFailed}{" "}
              <button type="button" onClick={retry} className="font-semibold underline">
                {r.retry}
              </button>
            </p>
          )}
        </div>
        <Input
          label={r.experience}
          name="experience_years"
          inputMode="numeric"
          value={values.experience_years}
          onChange={update("experience_years")}
          error={err("experience_years")}
          maxLength={2}
          required
        />
        <Input
          label={r.workplace}
          name="workplace"
          autoComplete="organization"
          value={values.workplace}
          onChange={update("workplace")}
          error={err("workplace")}
          maxLength={MAX.workplace}
          className="sm:col-span-2"
          required
        />
        <div className="sm:col-span-2">
          <ChoicePicker
            label={r.machines}
            hint={r.machinesHint}
            options={lists?.machines ?? null}
            loadFailed={listsFailed}
            value={values.machines}
            onChange={pick("machines")}
            other={values.other_machine}
            onOtherChange={(text) => {
              setValues((v) => ({ ...v, other_machine: text }));
              setErrors((errs) => ({ ...errs, machines: undefined }));
            }}
            error={err("machines")}
          />
        </div>
        <div className="sm:col-span-2">
          <ChoicePicker
            label={r.skills}
            hint={r.skillsHint}
            options={lists?.skills ?? null}
            loadFailed={listsFailed}
            value={values.skills}
            onChange={pick("skills")}
            other={values.other_skill}
            onOtherChange={(text) => {
              setValues((v) => ({ ...v, other_skill: text }));
              setErrors((errs) => ({ ...errs, skills: undefined }));
            }}
            error={err("skills")}
          />
          {listsFailed && (
            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={retryLists}>
              {r.retry}
            </Button>
          )}
        </div>

        <div className="mt-2 sm:col-span-2">
          {formError && <Alert className="mb-4">{formError}</Alert>}
          <Button type="submit" size="lg" disabled={sending} className="w-full sm:w-auto">
            {sending ? r.submitting : r.submit}
          </Button>
          {termsActive && (
            <button
              type="button"
              onClick={() => setTermsView(true)}
              className="mt-3 block text-sm font-semibold text-primary-text hover:underline sm:ml-4 sm:mt-0 sm:inline"
            >
              {t.terms.view}
            </button>
          )}
          <p className="mt-4 text-sm text-muted">
            {r.haveAccount}{" "}
            <Link href="/login" className="font-semibold text-primary-text hover:underline">
              {r.loginLink}
            </Link>
          </p>
        </div>
      </form>
    </Card>
  );
}
