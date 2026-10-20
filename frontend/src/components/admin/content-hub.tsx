"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { Icon } from "@/components/ui/icon";
import { groupItems, navItem, type DashboardNavKey } from "@/components/dashboard/dashboard-nav";
import { apiFetch, type Role } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

// Content hub: admin + super_admin only (same as every content page). Each page's API checks again.
const ROLES: readonly Role[] = ["admin", "super_admin"];

type HubKey = keyof ReturnType<typeof useDictionary>["contentHub"]["items"];
/** Live count line per card: the numbers to fill in, or false when the count could not be loaded. */
type Counts = Partial<Record<HubKey, Record<string, number> | false>>;

/** Small, cheap counts from the existing admin APIs (no new endpoints). A failed call only hides that card's count. */
const COUNTERS: Record<HubKey, () => Promise<Record<string, number> | false>> = {
  heroSlides: async () => {
    const res = await apiFetch<{ data: { is_active: boolean }[] }>("/admin/hero-slides");
    return res.ok && { n: res.data.data.filter((s) => s.is_active).length, total: res.data.data.length };
  },
  featured: async () => {
    const res = await apiFetch<{ data: unknown[] }>("/admin/featured-programmers");
    return res.ok && { n: res.data.data.length };
  },
  expertise: async () => {
    const res = await apiFetch<{ data: { is_published: boolean }[] }>("/admin/expertise-cards");
    return res.ok && { n: res.data.data.filter((c) => c.is_published).length, total: res.data.data.length };
  },
  gallery: async () => {
    const res = await apiFetch<{ meta: { total: number } }>("/admin/gallery/images?status=published");
    return res.ok && { n: res.data.meta.total };
  },
  notices: async () => {
    const res = await apiFetch<{ meta: { total: number } }>("/admin/notices?status=published");
    return res.ok && { n: res.data.meta.total };
  },
  siteSettings: async () => {
    const res = await apiFetch<{ data: { is_active: boolean }[] }>("/admin/committee");
    return res.ok && { n: res.data.data.filter((m) => m.is_active).length };
  },
  lists: async () => {
    const res = await apiFetch<{ data: { machines: unknown[]; skills: unknown[] } }>("/admin/profile-lists");
    return res.ok && { machines: res.data.data.machines.length, skills: res.data.data.skills.length };
  },
};

/** /dashboard/content (Phase 11d-2) — one card per content page, MASTER_SPEC §37 "Content". */
export function ContentHubScreen() {
  return <RequireAuth roles={ROLES}>{() => <ContentHub />}</RequireAuth>;
}

function ContentHub() {
  const t = useDictionary();
  const locale = useLocale();
  const { user } = useAuth();
  const h = t.contentHub;
  const items = user ? groupItems("content", user.role) : [];
  const [counts, setCounts] = useState<Counts>({});

  useEffect(() => {
    let cancelled = false;
    for (const key of Object.keys(COUNTERS) as HubKey[]) {
      void COUNTERS[key]().then((value) => {
        if (!cancelled) setCounts((prev) => ({ ...prev, [key]: value }));
      });
    }
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="mx-auto max-w-5xl">
      <p className="text-muted">{h.subtitle}</p>
      <ul className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {items.map((item) => {
          const key = item.key as HubKey & DashboardNavKey;
          const text = h.items[key];
          const count = counts[key];
          const countText =
            count === undefined
              ? h.countLoading
              : count === false
                ? h.countFailed
                : localizeDigits(fill(text.count, count), locale);
          return (
            <li key={item.key}>
              <Link
                href={item.href}
                className="group flex h-full flex-col rounded-card border border-border bg-surface p-5 shadow-card transition-colors hover:border-primary focus-visible:border-primary"
              >
                <span className="flex items-center gap-3">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-icon-box text-white">
                    <Icon name={navItem(item.key).icon} className="size-5" />
                  </span>
                  <span className="min-w-0 flex-1 font-semibold text-foreground">{t.dashboard.nav[item.key]}</span>
                  <Icon name="chevronRight" className="size-4 shrink-0 text-muted group-hover:text-primary-text" />
                </span>
                <span className="mt-3 flex-1 text-sm text-muted">{text.description}</span>
                <span
                  className={`mt-3 text-xs font-semibold ${count === false ? "text-muted" : "text-primary-text"}`}
                  aria-live="polite"
                >
                  {countText}
                </span>
              </Link>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
