"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { apiFetch, type Status, type User } from "@/lib/api";
import {
  PASSWORD_MAX,
  errorText,
  homeFor,
  normalizePhone,
  safeNext,
  serverErrors,
  type FieldError,
} from "@/lib/auth";
import { useAuth } from "./auth-provider";

const FIELDS = ["phone", "password"] as const;
type Field = (typeof FIELDS)[number];
type Errors = Partial<Record<Field, FieldError>>;

/** After login: forced password change first, then ?next= or the user's home page. */
function destination(user: User): string {
  if (user.must_change_password) return "/change-password";
  return safeNext(new URLSearchParams(window.location.search).get("next")) ?? homeFor(user);
}

export function LoginForm() {
  const t = useDictionary();
  const locale = useLocale();
  const router = useRouter();
  const { user, setUser } = useAuth();
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  // Status answers (403) and rate limit (429) come as the API's own bn/en message.
  const [notice, setNotice] = useState<{ text: string; status?: Status } | null>(null);
  const [sending, setSending] = useState(false);

  const l = t.login;

  // Already logged in (or just logged in) → go on.
  useEffect(() => {
    if (user) router.replace(destination(user));
  }, [user, router]);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (sending) return;

    const clientErrors: Errors = {};
    if (!phone.trim()) clientErrors.phone = { code: "required" };
    if (!password) clientErrors.password = { code: "required" };
    setErrors(clientErrors);
    setNotice(null);
    if (Object.keys(clientErrors).length > 0) return;

    setSending(true);
    const res = await apiFetch<{ message: string; data: User }>("/login", {
      method: "POST",
      locale,
      body: { phone: normalizePhone(phone), password },
    });
    setSending(false);

    if (res.ok) {
      setUser(res.data.data); // the effect above redirects
      router.refresh();
      return;
    }
    setPassword("");
    if (res.status === 422 && res.data?.errors) {
      setErrors(serverErrors(res.data.errors, FIELDS));
    } else if (res.status === 0) {
      setNotice({ text: t.auth.networkError });
    } else {
      const body = res.data as { message?: string; status?: Status } | null;
      setNotice({ text: body?.message || t.auth.genericError, status: body?.status });
    }
  }

  return (
    <Card className="relative mx-auto -mt-16 shadow-panel! max-w-md p-5 sm:p-8">
      <form onSubmit={onSubmit} noValidate className="grid gap-4">
        {notice && (
          <Alert tone={notice.status === "pending" ? "warning" : "danger"}>{notice.text}</Alert>
        )}
        <Input
          label={l.phone}
          name="phone"
          type="tel"
          inputMode="tel"
          autoComplete="username"
          placeholder={t.register.phonePlaceholder}
          value={phone}
          onChange={(e) => {
            setPhone(e.target.value);
            setErrors((x) => ({ ...x, phone: undefined }));
          }}
          error={errorText(errors.phone, t, locale)}
          maxLength={20}
          required
        />
        <Input
          label={l.password}
          name="password"
          type={showPassword ? "text" : "password"}
          autoComplete="current-password"
          value={password}
          onChange={(e) => {
            setPassword(e.target.value);
            setErrors((x) => ({ ...x, password: undefined }));
          }}
          error={errorText(errors.password, t, locale)}
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
        <Button type="submit" size="lg" disabled={sending} className="w-full">
          {sending ? l.submitting : l.submit}
        </Button>
        <p className="text-sm text-muted">{l.forgot}</p>
        <p className="text-sm text-muted">
          {l.noAccount}{" "}
          <Link href="/register" className="font-semibold text-primary-text hover:underline">
            {l.registerLink}
          </Link>
        </p>
      </form>
    </Card>
  );
}
