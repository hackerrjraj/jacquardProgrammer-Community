"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useDictionary } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { failureText } from "@/components/profile/profile-shared";
import { Button } from "@/components/ui/button";
import { Icon } from "@/components/ui/icon";
import { apiFetch } from "@/lib/api";
import { isAdminRole } from "@/lib/auth";

/**
 * "Message" on a programmer's profile (Phase 9b): opens (or creates) the chat with them.
 * Hidden for guests, accounts that are not approved, admins / super_admin (owner, 9b), on one's own profile,
 * and when the member cannot receive messages (`accepts` = approved, non-demo — the API checks again).
 */
export function MessageButton({ userId, accepts }: { userId: number; accepts: boolean }) {
  const t = useDictionary();
  const { user } = useAuth();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!user || user.status !== "approved" || isAdminRole(user.role) || user.id === userId || !accepts) return null;

  const open = async () => {
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ data: { id: number } }>("/chat/conversations", { method: "POST", body: { user_id: userId } });
    if (res.ok) {
      router.push(`/dashboard/chat/${res.data.data.id}`);
      return; // stays "busy" while the chat opens
    }
    setBusy(false);
    setError(failureText(res, t));
  };

  return (
    <>
      <Button variant="secondary" disabled={busy} onClick={() => void open()}>
        <Icon name="chat" className="size-4" />
        {busy ? t.chat.opening : t.chat.message}
      </Button>
      {error && (
        <p role="alert" className="w-full text-sm text-danger">
          {error}
        </p>
      )}
    </>
  );
}
