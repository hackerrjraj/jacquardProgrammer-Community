"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { apiFetch, type User } from "@/lib/api";
import {
  PASSWORD_MAX,
  PASSWORD_MIN,
  errorText,
  homeFor,
  serverErrors,
  type FieldError,
} from "@/lib/auth";
import { useAuth } from "./auth-provider";
import { RequireAuth } from "./require-auth";

const FIELDS = ["current_password", "password", "password_confirmation"] as const;
type Field = (typeof FIELDS)[number];
type Values = Record<Field, string>;
type Errors = Partial<Record<Field, FieldError>>;

const EMPTY: Values = { current_password: "", password: "", password_confirmation: "" };

function validate(v: Values): Errors {
  const e: Errors = {};
  if (!v.current_password) e.current_password = { code: "required" };
  if (!v.password) e.password = { code: "required" };
  else if (v.password.length < PASSWORD_MIN) e.password = { code: "passwordMin", n: PASSWORD_MIN };
  else if (v.password.length > PASSWORD_MAX) e.password = { code: "tooLong", n: PASSWORD_MAX };
  else if (v.password === v.current_password) e.password = { code: "passwordSame" };
  if (!v.password_confirmation) e.password_confirmation = { code: "required" };
  else if (v.password && v.password !== v.password_confirmation)
    e.password_confirmation = { code: "passwordMismatch" };
  return e;
}

/** /change-password — logged-in users only. */
export function ChangePasswordScreen() {
  return <RequireAuth>{(user) => <ChangePasswordForm user={user} />}</RequireAuth>;
}

/**
 * Forced mode (after an admin reset, `must_change_password`): no way out except
 * changing the password or logging out. Normal mode: link back to the account.
 */
function ChangePasswordForm({ user }: { user: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const router = useRouter();
  const { setUser, logout } = useAuth();
  const [values, setValues] = useState<Values>(EMPTY);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [showPassword, setShowPassword] = useState(false);

  const c = t.changePassword;
  const forced = user.must_change_password;
  const err = (f: Field) => errorText(errors[f], t, locale);

  function update(field: Field) {
    return (e: React.ChangeEvent<HTMLInputElement>) => {
      setValues((v) => ({ ...v, [field]: e.target.value }));
      setErrors((x) => ({ ...x, [field]: undefined }));
    };
  }

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (sending) return;

    const clientErrors = validate(values);
    setErrors(clientErrors);
    setSuccess(null);
    if (Object.keys(clientErrors).length > 0) {
      setFormError(t.auth.fixErrors);
      return;
    }

    setSending(true);
    setFormError(null);
    const res = await apiFetch<{ message: string; data: User }>("/change-password", {
      method: "POST",
      locale,
      body: values,
    });
    setSending(false);

    if (res.ok) {
      setValues(EMPTY);
      setUser(res.data.data);
      if (forced) router.replace(homeFor(res.data.data));
      else setSuccess(res.data.message);
    } else if (res.status === 422 && res.data?.errors) {
      setErrors(serverErrors(res.data.errors, FIELDS));
      setFormError(t.auth.fixErrors);
    } else if (res.status === 401) {
      setUser(null); // session ended → the guard sends them to /login
    } else if (res.status === 0) {
      setFormError(t.auth.networkError);
    } else {
      setFormError(res.data?.message || t.auth.genericError);
    }
  }

  const type = showPassword ? "text" : "password";

  return (
    <Card className="relative mx-auto -mt-16 shadow-panel! max-w-md p-5 sm:p-8">
      {forced && (
        <Alert tone="warning" className="mb-5">
          <p className="font-semibold">{c.forcedTitle}</p>
          <p className="mt-1">{c.forcedText}</p>
        </Alert>
      )}
      <form onSubmit={onSubmit} noValidate className="grid gap-4">
        {/* Helps password managers save the new password for the right account */}
        <input type="hidden" name="username" autoComplete="username" value={user.phone ?? ""} />
        <Input
          label={c.current}
          name="current_password"
          type={type}
          autoComplete="current-password"
          value={values.current_password}
          onChange={update("current_password")}
          error={err("current_password")}
          maxLength={PASSWORD_MAX}
          required
        />
        <Input
          label={c.new}
          name="password"
          type={type}
          autoComplete="new-password"
          value={values.password}
          onChange={update("password")}
          error={err("password")}
          maxLength={PASSWORD_MAX}
          required
        />
        <Input
          label={c.confirm}
          name="password_confirmation"
          type={type}
          autoComplete="new-password"
          value={values.password_confirmation}
          onChange={update("password_confirmation")}
          error={err("password_confirmation")}
          maxLength={PASSWORD_MAX}
          required
        />
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={showPassword}
            onChange={(e) => setShowPassword(e.target.checked)}
            className="size-4 accent-primary"
          />
          {t.auth.showPassword}
        </label>
        {formError && <Alert>{formError}</Alert>}
        {success && <Alert tone="success">{success}</Alert>}
        <Button type="submit" size="lg" disabled={sending} className="w-full">
          {sending ? c.submitting : c.submit}
        </Button>
        {forced ? (
          <Button variant="ghost" onClick={() => void logout()}>
            {t.nav.logout}
          </Button>
        ) : (
          <Link href="/dashboard" className="text-center text-sm font-semibold text-primary-text hover:underline">
            {c.backToAccount}
          </Link>
        )}
      </form>
    </Card>
  );
}
