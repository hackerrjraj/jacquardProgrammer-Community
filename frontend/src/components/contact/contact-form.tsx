"use client";

import { useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch } from "@/lib/api";
import { localizeDigits } from "@/lib/format";

type Field = "name" | "phone" | "email" | "subject" | "message";
type Values = Record<Field, string>;

// Same rules as the backend (StoreContactMessageRequest).
const MAX: Record<Field, number> = {
  name: 100,
  phone: 11,
  email: 255,
  subject: 150,
  message: 2000,
};
const PHONE_RE = /^01[3-9][0-9]{8}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Client errors keep a code (so they follow a language switch);
// server errors are already translated by the API.
type FieldError =
  | { code: "required" | "phone" | "email" }
  | { code: "tooLong"; max: number }
  | { code: "server"; text: string };

type Status = "idle" | "success" | "tooMany" | "failed" | "fixErrors";

const EMPTY: Values = { name: "", phone: "", email: "", subject: "", message: "" };

function validate(values: Values): Partial<Record<Field, FieldError>> {
  const errors: Partial<Record<Field, FieldError>> = {};
  for (const field of Object.keys(MAX) as Field[]) {
    const value = values[field].trim();
    if (!value) {
      if (field !== "email") errors[field] = { code: "required" };
    } else if (field === "phone" && !PHONE_RE.test(value)) {
      errors[field] = { code: "phone" };
    } else if (field === "email" && !EMAIL_RE.test(value)) {
      errors[field] = { code: "email" };
    } else if (value.length > MAX[field]) {
      errors[field] = { code: "tooLong", max: MAX[field] };
    }
  }
  return errors;
}

export function ContactForm() {
  const t = useDictionary();
  const locale = useLocale();
  const [values, setValues] = useState<Values>(EMPTY);
  const [errors, setErrors] = useState<Partial<Record<Field, FieldError>>>({});
  const [status, setStatus] = useState<Status>("idle");
  const [sending, setSending] = useState(false);

  const c = t.contact;

  function errorText(error?: FieldError): string | undefined {
    if (!error) return undefined;
    if (error.code === "server") return error.text;
    if (error.code === "tooLong")
      return c.errors.tooLong.replace("{max}", localizeDigits(String(error.max), locale));
    return c.errors[error.code];
  }

  function update(field: Field) {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
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
      setStatus("fixErrors");
      return;
    }

    setSending(true);
    setStatus("idle");
    const body = Object.fromEntries(
      Object.entries(values).map(([k, v]) => [k, v.trim()]),
    );
    if (!body.email) delete body.email;

    const res = await apiFetch("/contact", { method: "POST", body, locale });
    setSending(false);

    if (res.ok) {
      setValues(EMPTY);
      setErrors({});
      setStatus("success");
    } else if (res.status === 422 && res.data?.errors) {
      const serverErrors: Partial<Record<Field, FieldError>> = {};
      for (const [field, messages] of Object.entries(res.data.errors)) {
        if (field in MAX && messages[0])
          serverErrors[field as Field] = { code: "server", text: messages[0] };
      }
      setErrors(serverErrors);
      setStatus("fixErrors");
    } else if (res.status === 429) {
      setStatus("tooMany");
    } else {
      setStatus("failed");
    }
  }

  const statusText = status === "idle" ? null : c[status];
  const statusClass =
    status === "success"
      ? "border-success/30 bg-success/10 text-success"
      : "border-danger/30 bg-danger/10 text-danger";

  return (
    <Card className="p-5 sm:p-8">
      <h2 className="text-xl font-semibold">{c.formTitle}</h2>

      <form onSubmit={onSubmit} noValidate className="mt-6 grid gap-4 sm:grid-cols-2">
        <Input
          label={c.name}
          name="name"
          autoComplete="name"
          value={values.name}
          onChange={update("name")}
          error={errorText(errors.name)}
          maxLength={MAX.name}
          required
        />
        <Input
          label={c.phone}
          name="phone"
          type="tel"
          inputMode="numeric"
          autoComplete="tel-national"
          placeholder={c.phonePlaceholder}
          value={values.phone}
          onChange={update("phone")}
          error={errorText(errors.phone)}
          maxLength={MAX.phone}
          required
        />
        <Input
          label={c.email}
          name="email"
          type="email"
          autoComplete="email"
          value={values.email}
          onChange={update("email")}
          error={errorText(errors.email)}
          maxLength={MAX.email}
          className="sm:col-span-2"
        />
        <Input
          label={c.subject}
          name="subject"
          value={values.subject}
          onChange={update("subject")}
          error={errorText(errors.subject)}
          maxLength={MAX.subject}
          className="sm:col-span-2"
          required
        />
        <Textarea
          label={c.message}
          name="message"
          rows={6}
          value={values.message}
          onChange={update("message")}
          error={errorText(errors.message)}
          maxLength={MAX.message}
          className="sm:col-span-2"
          required
        />

        <div className="sm:col-span-2">
          {statusText && (
            <p
              role={status === "success" ? "status" : "alert"}
              className={`mb-4 rounded-control border px-4 py-3 text-sm ${statusClass}`}
            >
              {statusText}
            </p>
          )}
          <Button type="submit" size="lg" disabled={sending} className="w-full sm:w-auto">
            {sending ? c.sending : c.send}
          </Button>
        </div>
      </form>
    </Card>
  );
}
