"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { LanguageToggle } from "@/components/layout/language-toggle";
import { LogoBadge } from "@/components/layout/logo";
import { useChatUnread } from "@/components/chat/chat-unread";
import { useCommitteeChatUnread } from "@/components/chat/committee-chat-unread";
import { useAdminBadges } from "@/components/admin/admin-badges";
import { NotificationBell } from "@/components/notifications/notification-bell";
import { useNotificationUnread } from "@/components/notifications/notifications-shared";
import { InstallAppButton, InstallAppMenuItem, IosInstallHint } from "@/components/pwa/install-app";
import { Icon } from "@/components/ui/icon";
import type { User } from "@/lib/api";
import { fill, isAdminRole } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { activeNavItem, navFor, type DashboardNavItem } from "./dashboard-nav";

// ---- Collapsed sidebar (desktop), remembered in this browser ----
const COLLAPSE_KEY = "jpphaf.sidebarCollapsed";
const COLLAPSE_EVENT = "jpphaf-sidebar";

function readCollapsed(): boolean {
  try {
    return window.localStorage.getItem(COLLAPSE_KEY) === "1";
  } catch {
    return false;
  }
}

function writeCollapsed(value: boolean) {
  try {
    window.localStorage.setItem(COLLAPSE_KEY, value ? "1" : "0");
  } catch {
    // Storage blocked (private mode) — the choice just isn't remembered.
  }
  window.dispatchEvent(new Event(COLLAPSE_EVENT));
}

function subscribeCollapsed(onChange: () => void) {
  window.addEventListener(COLLAPSE_EVENT, onChange);
  window.addEventListener("storage", onChange);
  return () => {
    window.removeEventListener(COLLAPSE_EVENT, onChange);
    window.removeEventListener("storage", onChange);
  };
}

/** Browser tab title "(5) Dashboard": keeps a count prefix on whatever title the page sets. */
function useTabTitleCount(total: number) {
  useEffect(() => {
    const prefix = /^\(\d+\+?\)\s/;
    const apply = () => {
      const base = document.title.replace(prefix, "");
      const next = total > 0 ? `(${total > 99 ? "99+" : total}) ${base}` : base;
      if (next !== document.title) document.title = next;
    };
    apply();
    const el = document.querySelector("title");
    const observer = new MutationObserver(apply);
    if (el) observer.observe(el, { childList: true, characterData: true, subtree: true });
    return () => {
      observer.disconnect();
      document.title = document.title.replace(prefix, "");
    };
  }, [total]);
}

/**
 * Dashboard layout (MASTER_SPEC §37a): left sidebar + top bar.
 * Every dashboard page guards itself with <RequireAuth> (logged out → /login),
 * so the page is always rendered here; the menu appears once /api/me answers.
 */
export function DashboardShell({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  return <Shell user={user}>{children}</Shell>;
}

function Shell({ user, children }: { user: User | null; children: React.ReactNode }) {
  const t = useDictionary();
  const d = t.dashboard;
  const pathname = usePathname();
  const collapsed = useSyncExternalStore(subscribeCollapsed, readCollapsed, () => false);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const current = activeNavItem(pathname);
  const title = d.nav[current?.key ?? "dashboard"];
  // Sidebar badge: reloaded on every page change (the review page also refreshes it after each decision).
  const admin = useAdminBadges(isAdminRole(user?.role), `${user?.id}|${pathname}`);
  const chatUnread = useChatUnread(user?.status === "approved", `${user?.id}|${pathname}`, user?.id);
  // Committee Chat badge: admins get it with the admin counts, committee members from their own endpoint.
  const memberChat = useCommitteeChatUnread(user?.committee_chat === true && !isAdminRole(user.role), `${user?.id}|${pathname}`);
  const parts = {
    pendingMembers: admin?.pending_members ?? null,
    pendingPayments: admin?.pending_payments ?? null,
    pendingAid: admin?.pending_aid ?? null,
    chatUnread,
    openChatReports: admin?.open_chat_reports ?? null,
    unreadContactMessages: admin?.unread_contact_messages ?? null,
    committeeChatUnread: admin ? admin.committee_chat_unread : memberChat,
  };
  const total = Object.values(parts).reduce<number>((sum, n) => sum + (n ?? 0), 0);
  const badges = { ...parts, total };
  useTabTitleCount(total);
  // Bell (Phase 10a): approved accounts; updates live over the chat connection kept open above.
  const canNotify = user?.status === "approved" && !user.must_change_password;
  const notificationUnread = useNotificationUnread(canNotify, `${user?.id}|${pathname}`);

  // Esc closes the mobile drawer.
  useEffect(() => {
    if (!drawerOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setDrawerOpen(false);
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [drawerOpen]);

  return (
    <div className="min-h-screen bg-background">
      {/* Desktop sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-30 hidden flex-col bg-linear-to-b from-navy to-navy-card-2 text-white transition-[width] duration-200 lg:flex ${
          collapsed ? "w-20" : "w-64"
        }`}
      >
        <SidebarContent user={user} collapsed={collapsed} pathname={pathname} badges={badges} />
        <button
          type="button"
          onClick={() => writeCollapsed(!collapsed)}
          aria-label={collapsed ? d.expand : d.collapse}
          title={collapsed ? d.expand : d.collapse}
          className="flex h-12 shrink-0 items-center justify-center gap-2 border-t border-white/10 text-sm text-white/70 hover:bg-white/5 hover:text-white"
        >
          <Icon name={collapsed ? "chevronRight" : "chevronLeft"} className="size-4" />
          {!collapsed && <span>{d.collapse}</span>}
        </button>
      </aside>

      {/* Mobile drawer */}
      <div
        className={`fixed inset-0 z-50 lg:hidden ${drawerOpen ? "" : "pointer-events-none"}`}
        aria-hidden={!drawerOpen}
        inert={!drawerOpen}
      >
        <div
          className={`absolute inset-0 bg-navy/60 transition-opacity ${drawerOpen ? "opacity-100" : "opacity-0"}`}
          onClick={() => setDrawerOpen(false)}
        />
        <aside
          id="dashboard-drawer"
          aria-label={d.menuLabel}
          className={`absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-linear-to-b from-navy to-navy-card-2 text-white shadow-panel transition-transform duration-200 ${
            drawerOpen ? "translate-x-0" : "-translate-x-full"
          }`}
        >
          <button
            type="button"
            onClick={() => setDrawerOpen(false)}
            aria-label={d.closeMenu}
            className="absolute top-4 right-3 flex size-9 items-center justify-center rounded-full text-white/80 hover:bg-white/10"
          >
            <Icon name="close" />
          </button>
          <SidebarContent
            user={user}
            collapsed={false}
            pathname={pathname}
            badges={badges}
            onNavigate={() => setDrawerOpen(false)}
          />
        </aside>
      </div>

      <div className={`flex min-h-screen flex-col transition-[padding] duration-200 ${collapsed ? "lg:pl-20" : "lg:pl-64"}`}>
        <header className="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-border bg-surface/95 px-3 backdrop-blur sm:gap-3 sm:px-6">
          <button
            type="button"
            onClick={() => setDrawerOpen(true)}
            aria-label={d.openMenu}
            aria-expanded={drawerOpen}
            aria-controls="dashboard-drawer"
            className="relative flex size-10 shrink-0 items-center justify-center rounded-full border border-border text-foreground hover:border-primary hover:text-primary-text lg:hidden"
          >
            <Icon name="menu" />
            {total > 0 && <span aria-hidden="true" className="absolute top-0 right-0 size-3 rounded-full bg-danger ring-2 ring-surface" />}
          </button>
          <h1 className="min-w-0 flex-1 truncate text-lg font-semibold sm:text-xl">{title}</h1>
          {/* Phones: no globe icon, so the page title keeps room next to the bell (10a). */}
          {/* Install app (10b): tablets and up here; phones get it in the account menu. */}
          <InstallAppButton />
          <LanguageToggle className="px-2.5 sm:px-3 [&>svg]:hidden sm:[&>svg]:block" />
          {canNotify && <NotificationBell count={notificationUnread} />}
          {user && <UserMenu user={user} />}
        </header>
        <main className="flex-1 p-4 sm:p-6 lg:p-8">
          <IosInstallHint />
          {children}
        </main>
      </div>
    </div>
  );
}

function SidebarContent({
  user,
  collapsed,
  pathname,
  badges,
  onNavigate,
}: {
  user: User | null;
  collapsed: boolean;
  pathname: string;
  badges: Record<NonNullable<DashboardNavItem["badge"]>, number | null>;
  onNavigate?: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.dashboard;
  const current = activeNavItem(pathname);
  // A page inside a hub (e.g. Gallery) highlights the hub's menu item (e.g. Content).
  const activeKey = current?.group ?? current?.key;

  return (
    <>
      <Link
        href="/dashboard"
        onClick={onNavigate}
        aria-label={t.site.name}
        className={`flex h-18 shrink-0 items-center gap-2.5 border-b border-white/10 ${collapsed ? "justify-center px-2" : "px-5"}`}
      >
        <LogoBadge />
        {!collapsed && (
          <span className="min-w-0 font-heading leading-tight">
            <span className="block text-base font-bold">{t.site.name}</span>
            <span className="block truncate text-xs text-white/60">{d.panelName}</span>
          </span>
        )}
      </Link>

      <nav aria-label={d.menuLabel} className="flex-1 overflow-y-auto py-4">
        <ul className={`space-y-1 ${collapsed ? "px-2" : "px-3"}`}>
          {(user ? navFor(user.role, user) : []).map((item) => {
            const active = activeKey === item.key;
            const label = d.nav[item.key];
            const count = item.badge ? badges[item.badge] : null;
            const countText = count ? localizeDigits(count > 99 ? "99+" : String(count), locale) : null;
            const countLabel = countText ? fill(item.badge === "chatUnread" || item.badge === "committeeChatUnread" || item.badge === "unreadContactMessages" || item.badge === "total" ? d.unreadCount : item.badge === "openChatReports" ? d.openCount : d.waitingCount, { n: countText }) : null;
            return (
              <li key={item.key}>
                <Link
                  href={item.href}
                  onClick={onNavigate}
                  aria-current={active ? "page" : undefined}
                  title={collapsed ? (countLabel ? `${label} (${countLabel})` : label) : undefined}
                  className={`relative flex items-center gap-3 rounded-control py-2.5 text-sm font-medium transition-colors ${
                    collapsed ? "justify-center px-2" : "px-3"
                  } ${active ? "bg-white/10 text-white" : "text-white/70 hover:bg-white/5 hover:text-white"}`}
                >
                  {active && (
                    <span aria-hidden="true" className="absolute inset-y-2 left-0 w-1 rounded-full bg-primary" />
                  )}
                  <Icon name={item.icon} className={`size-5 shrink-0 ${active ? "text-primary" : ""}`} />
                  {collapsed ? (
                    <>
                      <span className="sr-only">
                        {label}
                        {countLabel && ` (${countLabel})`}
                      </span>
                      {countText && (
                        <span aria-hidden="true" className="absolute top-1 right-1 min-w-4 rounded-full bg-danger px-1 text-center text-[0.6rem] leading-4 font-bold text-white">
                          {countText}
                        </span>
                      )}
                    </>
                  ) : (
                    <>
                      <span className="min-w-0 flex-1 truncate">{label}</span>
                      {countText && (
                        <span className="shrink-0 rounded-full bg-danger px-2 py-0.5 text-xs font-bold text-white">
                          <span aria-hidden="true">{countText}</span>
                          <span className="sr-only">{countLabel}</span>
                        </span>
                      )}
                    </>
                  )}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      <div className={`shrink-0 border-t border-white/10 py-3 ${collapsed ? "px-2" : "px-3"}`}>
        <Link
          href="/"
          onClick={onNavigate}
          title={collapsed ? d.backToSite : undefined}
          className={`flex items-center gap-3 rounded-control py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white ${
            collapsed ? "justify-center px-2" : "px-3"
          }`}
        >
          <Icon name="globe" className="size-5 shrink-0" />
          <span className={collapsed ? "sr-only" : ""}>{d.backToSite}</span>
        </Link>
      </div>
    </>
  );
}

function UserMenu({ user }: { user: User }) {
  const t = useDictionary();
  const d = t.dashboard;
  const { logout } = useAuth();
  const [open, setOpen] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  // Close on outside click or Esc.
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const itemBase = "flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left text-sm hover:bg-background";
  const itemClass = `${itemBase} text-foreground hover:text-primary-text`;

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={d.userMenu}
        className="flex items-center gap-2 rounded-full border border-border p-0.5 sm:py-1 sm:pr-2 sm:pl-1 hover:border-primary"
      >
        <span
          aria-hidden="true"
          className="flex size-8 items-center justify-center rounded-full bg-brand text-sm font-bold text-white"
        >
          {user.name.trim().charAt(0).toUpperCase()}
        </span>
        <span className="hidden max-w-36 truncate text-sm font-medium text-foreground md:block">{user.name}</span>
        <Icon name="chevronDown" className="hidden size-4 text-muted sm:block" />
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 z-30 mt-2 w-60 rounded-card border border-border bg-surface p-2 shadow-panel"
        >
          <div className="border-b border-border px-3 pt-1 pb-2.5">
            <p className="truncate font-semibold text-foreground">{user.name}</p>
            <p className="text-xs text-muted">{t.roles[user.role]}</p>
          </div>
          <div className="pt-2">
            <Link role="menuitem" href="/dashboard/profile" onClick={() => setOpen(false)} className={itemClass}>
              <Icon name="user" className="size-4" />
              {d.nav.profile}
            </Link>
            <Link role="menuitem" href="/change-password" onClick={() => setOpen(false)} className={itemClass}>
              <Icon name="key" className="size-4" />
              {d.changePassword}
            </Link>
            <InstallAppMenuItem className={itemClass} onDone={() => setOpen(false)} />
            <button
              role="menuitem"
              type="button"
              disabled={leaving}
              onClick={() => {
                setLeaving(true);
                void logout();
              }}
              className={`${itemBase} text-danger disabled:opacity-60`}
            >
              <Icon name="logout" className="size-4" />
              {leaving ? t.account.loggingOut : t.nav.logout}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
