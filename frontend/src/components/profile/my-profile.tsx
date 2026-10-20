"use client";

import { useEffect, useState } from "react";
import { useDictionary } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { apiFetch, type Profile } from "@/lib/api";
import { DocumentsManager } from "./documents-manager";
import { BasicForm, PhotoForm } from "./profile-edit";
import { useProfileOptions } from "./profile-shared";
import { PortfolioManager } from "./portfolio-manager";
import { ProfileView } from "./profile-view";
import { WorkHistoryEditor } from "./work-history-editor";

const TABS = ["view", "edit", "work", "portfolio", "documents"] as const;
type Tab = (typeof TABS)[number];

/** /dashboard/profile — own profile: preview + edit sections (Phase 4a API). */
export function MyProfileScreen() {
  return <RequireAuth>{() => <MyProfile />}</RequireAuth>;
}

function MyProfile() {
  const t = useDictionary();
  const { refresh } = useAuth();
  const options = useProfileOptions();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [tab, setTab] = useState<Tab>("view");

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: Profile }>("/profile").then((res) => {
      if (cancelled) return;
      if (res.ok) setProfile(res.data.data);
      else {
        if (res.status === 401 || res.status === 403) void refresh();
        setFailed(true);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [attempt, refresh]);

  if (failed)
    return (
      <Card className="mx-auto max-w-lg text-center">
        <p className="text-danger">{t.profile.loadFailed}</p>
        <Button
          variant="outline"
          className="mt-4"
          onClick={() => {
            setFailed(false);
            setAttempt((n) => n + 1);
          }}
        >
          {t.profile.retry}
        </Button>
      </Card>
    );
  if (!profile)
    return (
      <p role="status" className="py-12 text-center text-muted">
        {t.auth.loading}
      </p>
    );

  const saved = (next: Profile) => {
    setProfile(next);
    // The header/user menu read /api/me (name, email) — keep it in step.
    if (next.email !== profile.email) void refresh();
  };

  return (
    <div className="space-y-6">
      <div role="tablist" aria-label={t.profile.tabsLabel} className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
        {TABS.map((key) => {
          const active = key === tab;
          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => setTab(key)}
              className={`shrink-0 rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {t.profile.tabs[key]}
            </button>
          );
        })}
      </div>

      <div role="tabpanel">
        {tab === "view" && (
          <>
            <p className="mb-4 text-sm text-muted">{t.profile.previewNote}</p>
            <ProfileView profile={profile} options={options} />
          </>
        )}
        {tab === "edit" && (
          <div className="space-y-6">
            <PhotoForm profile={profile} onSaved={saved} />
            <BasicForm key={profile.id} profile={profile} options={options} onSaved={saved} />
          </div>
        )}
        {tab === "work" && <WorkHistoryEditor profile={profile} onSaved={saved} />}
        {tab === "portfolio" && <PortfolioManager profile={profile} options={options} onSaved={saved} />}
        {tab === "documents" && <DocumentsManager profile={profile} options={options} onSaved={saved} />}
      </div>
    </div>
  );
}
