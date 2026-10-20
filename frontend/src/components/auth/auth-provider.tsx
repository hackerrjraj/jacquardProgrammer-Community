"use client";

import { usePathname, useRouter } from "next/navigation";
import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { apiFetch, type User } from "@/lib/api";
import { unsubscribeThisDevice } from "@/components/pwa/push-client";

/**
 * Who is logged in, from GET /api/me (Sanctum cookie session).
 * This is for the UI only — the backend checks every request itself.
 */
type AuthState = {
  user: User | null;
  /** true until the first /api/me answer arrives */
  loading: boolean;
  /** true right after the user pressed Logout (guards then skip ?next=) */
  signedOut: boolean;
  setUser: (user: User | null) => void;
  refresh: () => Promise<User | null>;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

// 401 = not logged in, 403 = account no longer approved (session ended by the API)
async function fetchMe(): Promise<User | null> {
  const res = await apiFetch<{ data: User }>("/me");
  return res.ok ? res.data.data : null;
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUserState] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [signedOut, setSignedOut] = useState(false);
  const setUser = useCallback((next: User | null) => {
    setUserState(next);
    if (next) setSignedOut(false);
  }, []);
  const router = useRouter();
  const pathname = usePathname();

  const refresh = useCallback(async () => {
    const next = await fetchMe();
    setUser(next);
    setLoading(false);
    return next;
  }, [setUser]);

  const logout = useCallback(async () => {
    // Phase 10c-2: this device stops getting this member's pushes (never blocks or breaks logout).
    await unsubscribeThisDevice();
    await apiFetch("/logout", { method: "POST" });
    setSignedOut(true);
    setUser(null);
    router.push("/login");
    router.refresh();
  }, [router, setUser]);

  // Initial session check.
  useEffect(() => {
    let cancelled = false;
    void fetchMe().then((me) => {
      if (cancelled) return;
      setUserState(me);
      setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  // Forced password change: nowhere else to go until it is done.
  const mustChange = user?.must_change_password ?? false;
  useEffect(() => {
    if (mustChange && pathname !== "/change-password") router.replace("/change-password");
  }, [mustChange, pathname, router]);

  return (
    <AuthContext.Provider value={{ user, loading, signedOut, setUser, refresh, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside <AuthProvider>");
  return ctx;
}
