"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";
import { useDictionary } from "@/i18n/client";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import type { Role, User } from "@/lib/api";
import { isAdminRole } from "@/lib/auth";
import { useAuth } from "./auth-provider";

export function LoadingBox() {
  const t = useDictionary();
  return (
    <Container className="py-16">
      <p role="status" className="text-center text-muted">
        {t.auth.loading}
      </p>
    </Container>
  );
}

function NotAllowed() {
  const t = useDictionary();
  return (
    <Container className="py-12 sm:py-16">
      <Card className="mx-auto max-w-lg text-center">
        <h1 className="text-xl font-semibold text-danger">{t.guard.notAllowedTitle}</h1>
        <p className="mt-2 text-muted">{t.guard.notAllowedText}</p>
        <ButtonLink href="/" variant="outline" className="mt-6">
          {t.guard.backHome}
        </ButtonLink>
      </Card>
    </Container>
  );
}

/**
 * Route guard (UX only — the API is the real protection).
 * Logged out → /login?next=…; `admin` pages show "not allowed" to non-admins,
 * `roles` pages to anyone whose role is not listed.
 */
export function RequireAuth({
  admin = false,
  roles,
  children,
}: {
  admin?: boolean;
  roles?: readonly Role[];
  children: (user: User) => React.ReactNode;
}) {
  const { user, loading, signedOut } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user)
      router.replace(signedOut ? "/login" : `/login?next=${encodeURIComponent(pathname)}`);
  }, [loading, user, signedOut, pathname, router]);

  if (loading || !user) return <LoadingBox />;
  // The provider is already redirecting to /change-password.
  if (user.must_change_password && pathname !== "/change-password") return <LoadingBox />;
  if (admin && !isAdminRole(user.role)) return <NotAllowed />;
  if (roles && !roles.includes(user.role)) return <NotAllowed />;
  return <>{children(user)}</>;
}
