"use client";

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { isDemoAccount } from "@/components/assistance/assistance-shared";
import { RequireAuth } from "@/components/auth/require-auth";
import { MessageButton } from "@/components/chat/message-button";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { failureText, fieldErrors, useProfileOptions } from "@/components/profile/profile-shared";
import { ProfileView } from "@/components/profile/profile-view";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { apiFetch, type Profile, type User } from "@/lib/api";
import { EMAIL_RE, errorText, isAdminRole, type FieldError } from "@/lib/auth";
import { MemberPaymentHistorySection } from "./payment-history";

/** Same rule as User::canManage on the backend — hide what the API would refuse. */
function canManage(me: User, target: User): boolean {
  if (me.id === target.id) return false;
  return me.role === "super_admin" || !isAdminRole(target.role);
}

/** /dashboard/programmers/[id] — reads the id from the URL. */
export function ProgrammerDetailPage() {
  const { id } = useParams<{ id: string }>();
  return <ProgrammerDetailScreen id={Number(id)} />;
}

/** Member profile, read-only (incl. documents). */
export function ProgrammerDetailScreen({ id }: { id: number }) {
  return <RequireAuth roles={navItem("programmers").roles}>{(me) => <ProgrammerDetail id={id} me={me} />}</RequireAuth>;
}

function ProgrammerDetail({ id, me }: { id: number; me: User }) {
  const t = useDictionary();
  const l = t.programmersList;
  const options = useProfileOptions();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [error, setError] = useState<{ notFound: boolean; text: string } | null>(null);
  const [editing, setEditing] = useState(false);
  const [flash, setFlash] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: Profile }>(`/admin/members/${id}/profile`).then((res) => {
      if (cancelled) return;
      if (res.ok) setProfile(res.data.data);
      else setError({ notFound: res.status === 404, text: failureText(res, t) });
    });
    return () => {
      cancelled = true;
    };
  }, [id, t]);

  const back = (
    <ButtonLink href="/dashboard/programmers" variant="outline">
      <Icon name="arrowLeft" className="size-4" />
      {l.back}
    </ButtonLink>
  );

  if (error)
    return (
      <Card className="mx-auto max-w-lg text-center">
        <p className="text-danger">{error.notFound ? l.notFound : error.text}</p>
        <div className="mt-5">{back}</div>
      </Card>
    );
  if (!profile)
    return (
      <p role="status" className="py-12 text-center text-muted">
        {t.auth.loading}
      </p>
    );

  const mayEdit = isAdminRole(me.role) && canManage(me, profile);

  return (
    <div className="space-y-4">
      {me.role === "committee" && (
        <p className="flex items-center gap-2 text-sm text-muted">
          <Icon name="eye" className="size-4" />
          {l.readOnly}
        </p>
      )}
      {flash && <Alert tone="success">{flash}</Alert>}
      <ProfileView
        profile={profile}
        options={options}
        showDocuments
        belowHeader={<MemberPaymentHistorySection memberId={profile.id} />}
        actions={
          <>
            {back}
            {/* Committee viewers only in practice — the button hides itself for admins (owner, 9b). */}
            <MessageButton userId={profile.id} accepts={profile.status === "approved" && !isDemoAccount(profile)} />
            {mayEdit && (
              <Button
                onClick={() => {
                  setFlash(null);
                  setEditing(true);
                }}
              >
                <Icon name="pencil" className="size-4" />
                {l.editDetails}
              </Button>
            )}
          </>
        }
      />
      {editing && (
        <EditDetails
          profile={profile}
          onClose={() => setEditing(false)}
          onSaved={(next, message) => {
            setProfile(next);
            setEditing(false);
            setFlash(message);
          }}
        />
      )}
    </div>
  );
}

/** Admin: change a member's name and email (PATCH /api/admin/members/{id}). */
function EditDetails({
  profile,
  onClose,
  onSaved,
}: {
  profile: Profile;
  onClose: () => void;
  onSaved: (p: Profile, message: string) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const l = t.programmersList;
  const [name, setName] = useState(profile.name);
  const [email, setEmail] = useState(profile.email ?? "");
  const [errors, setErrors] = useState<{ name?: FieldError; email?: FieldError }>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    const found: typeof errors = {};
    if (!name.trim()) found.name = { code: "required" };
    else if (name.trim().length > 100) found.name = { code: "tooLong", n: 100 };
    if (email.trim() && !EMAIL_RE.test(email.trim())) found.email = { code: "email" };
    setErrors(found);
    if (found.name || found.email) return;

    const body: Record<string, string | null> = {};
    if (name.trim() !== profile.name) body.name = name.trim();
    if ((email.trim() || null) !== profile.email) body.email = email.trim() || null;
    if (Object.keys(body).length === 0) return onClose();

    setBusy(true);
    setFailure(null);
    const res = await apiFetch<{ message: string; data: Profile }>(`/admin/members/${profile.id}`, { method: "PATCH", body });
    setBusy(false);
    if (res.ok) return onSaved(res.data.data, res.data.message);
    const server = fieldErrors(res);
    setErrors({
      name: server.name ? { code: "server", text: server.name } : undefined,
      email: server.email ? { code: "server", text: server.email } : undefined,
    });
    if (!server.name && !server.email) setFailure(failureText(res, t));
  }

  return (
    <Modal title={l.editTitle} onClose={() => !busy && onClose()}>
      <form onSubmit={submit} noValidate className="space-y-4">
        <p className="text-sm text-muted">{l.editText}</p>
        <Input label={l.nameLabel} value={name} onChange={(e) => setName(e.target.value)} error={errorText(errors.name, t, locale)} maxLength={100} />
        <Input
          label={l.emailLabel}
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          error={errorText(errors.email, t, locale)}
          maxLength={255}
        />
        {failure && <Alert tone="danger">{failure}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="ghost" disabled={busy} onClick={onClose}>
            {t.profile.cancel}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? t.profile.saving : t.profile.save}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
