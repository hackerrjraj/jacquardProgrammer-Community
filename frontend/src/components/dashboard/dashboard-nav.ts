import type { Dictionary } from "@/i18n/config";
import type { IconName } from "@/components/ui/icon";
import type { Role, User } from "@/lib/api";

export type DashboardNavKey = keyof Dictionary["dashboard"]["nav"];

export type DashboardNavItem = {
  key: DashboardNavKey;
  href: string;
  icon: IconName;
  /** Roles that see this item (MASTER_SPEC §10, §37a). The API checks again. */
  roles: readonly Role[];
  /** Count bubble next to the label (loaded by the dashboard shell). */
  badge?: "pendingMembers" | "pendingPayments" | "pendingAid" | "chatUnread" | "openChatReports" | "unreadContactMessages" | "committeeChatUnread" | "total";
  /** Shown only when the own account has this flag (GET /api/me; the API checks again). */
  flag?: "committee_chat";
  /** Not in the sidebar (opened from elsewhere, e.g. the bell) — still gives the page its title. */
  hidden?: boolean;
  /** Opened from a hub page instead of the sidebar (11d-2); the hub's menu item stays highlighted. */
  group?: "content";
};

const ALL: readonly Role[] = ["member", "committee", "admin", "super_admin"];
const ADMINS: readonly Role[] = ["admin", "super_admin"];

/** Sidebar menu, in the MASTER_SPEC §37a order (+ member approvals for admins). */
export const dashboardNav: DashboardNavItem[] = [
  // badge = total of every badge below (also shown in the browser tab title).
  { key: "dashboard", href: "/dashboard", icon: "dashboard", roles: ALL, badge: "total" },
  // Committee: read-only (Phase 4b-2)
  { key: "programmers", href: "/dashboard/programmers", icon: "users", roles: ["committee", ...ADMINS] },
  { key: "members", href: "/dashboard/members", icon: "userCheck", roles: ADMINS, badge: "pendingMembers" },
  // Committee panel (members, positions, order, term) — admin / super_admin only; committee has no manage rights.
  { key: "committee", href: "/dashboard/committee", icon: "scale", roles: ADMINS },
  // Own dues + payments (Phase 7b) — members and committee only; Admin / Super Admin are management-only (owner, 2026-10-20).
  { key: "payments", href: "/dashboard/payments", icon: "wallet", roles: ["member", "committee"] },
  // Fund Report (members only, MASTER_SPEC §33) — any approved account; the API checks again.
  { key: "fundReport", href: "/dashboard/fund-report", icon: "scale", roles: ALL },
  // Payment verification queue (Phase 7c) — admin / super_admin only (MASTER_SPEC §27), badge = waiting payments.
  { key: "paymentReview", href: "/dashboard/payment-review", icon: "banknote", roles: ADMINS, badge: "pendingPayments" },
  // Own aid requests + advisory voting on others' open requests (Phase 8b) — any approved account.
  // (The old "Committee Voting" item is part of this page now; /dashboard/voting redirects here.)
  { key: "assistance", href: "/dashboard/assistance", icon: "heart", roles: ALL },
  // Private Committee Chat — active committee panel members + admin / super_admin (flag from /api/me); badge = unread.
  { key: "committeeChat", href: "/dashboard/committee-chat", icon: "network", roles: ALL, flag: "committee_chat", badge: "committeeChatUnread" },
  // 1-to-1 chat (Phase 9b) — any approved account; badge = unread messages.
  { key: "chat", href: "/dashboard/chat", icon: "chat", roles: ALL, badge: "chatUnread" },
  // Chat reports (Phase 9d-1) — admin / super_admin only; badge = open reports. Shows the reported message only.
  { key: "chatReports", href: "/dashboard/chat-reports", icon: "flag", roles: ADMINS, badge: "openChatReports" },
  // Expenses (খরচের হিসাব) — admin / super_admin only; delete = super_admin (API checks).
  { key: "expenses", href: "/dashboard/expenses", icon: "wallet", roles: ADMINS },
  // Admin decision + payout screen — Phase 8c.
  // Aid Applications: decide + pay out (Phase 8c) — admin / super_admin only.
  { key: "aid", href: "/dashboard/aid", icon: "vote", roles: ADMINS, badge: "pendingAid" },
  // Content hub (Phase 11d-2) — one card per content page below; those pages are not in the sidebar themselves.
  { key: "content", href: "/dashboard/content", icon: "fileText", roles: ADMINS },
  // Site Content (Phase 14a) — editable public-page text, grouped by page; admin / super_admin only.
  { key: "siteContent", href: "/dashboard/site-content", icon: "pencil", roles: ADMINS },
  // Registration terms & conditions editor — own sidebar item, right after Site Content.
  { key: "registrationTerms", href: "/dashboard/registration-terms", icon: "book", roles: ADMINS },
  // Payment-due reminder: message, on/off, who gets it — admin / super_admin only.
  { key: "paymentReminder", href: "/dashboard/payment-reminder", icon: "bell", roles: ADMINS },
  // Content pages (MASTER_SPEC §37 "Content" order) — admin / super_admin only (committee has no content powers).
  // Home hero slides (Phase 11b-1).
  { key: "heroSlides", href: "/dashboard/hero-slides", icon: "slides", roles: ADMINS, group: "content" },
  // Featured programmers, shown first in the Home rows (Phase 11b-2).
  { key: "featured", href: "/dashboard/featured-programmers", icon: "star", roles: ADMINS, group: "content" },
  // Home page Expertise cards (Phase 6d-3).
  { key: "expertise", href: "/dashboard/expertise-cards", icon: "chip", roles: ADMINS, group: "content" },
  // Gallery management (Phase 6b).
  { key: "gallery", href: "/dashboard/gallery", icon: "image", roles: ADMINS, group: "content" },
  // Notices management (Phase 6c).
  { key: "notices", href: "/dashboard/notices", icon: "megaphone", roles: ADMINS, group: "content" },
  // Site contact info + About text (Phase 11c).
  { key: "siteSettings", href: "/dashboard/site-settings", icon: "globe", roles: ADMINS, group: "content" },
  // Machine + Skill lists used in profiles, registration and the directory filter (Phase 11d-1).
  { key: "lists", href: "/dashboard/lists", icon: "list", roles: ADMINS, group: "content" },
  // Contact form messages (Phase 11a) — admin / super_admin only; badge = unread messages.
  { key: "messages", href: "/dashboard/messages", icon: "inbox", roles: ADMINS, badge: "unreadContactMessages" },
  // Audit log (Phase 11a) — admin / super_admin only, read-only.
  { key: "audit", href: "/dashboard/audit-log", icon: "history", roles: ADMINS },
  // Fund Settings (Phase 7e) — Super Admin only.
  { key: "settings", href: "/dashboard/settings", icon: "settings", roles: ["super_admin"] },
  { key: "profile", href: "/dashboard/profile", icon: "user", roles: ALL },
  // All notifications (Phase 10a) — opened from the bell in the top bar.
  { key: "notifications", href: "/dashboard/notifications", icon: "bell", roles: ALL, hidden: true },
];

export function navItem(key: DashboardNavKey): DashboardNavItem {
  return dashboardNav.find((item) => item.key === key)!;
}

export function navFor(role: Role, user?: User | null): DashboardNavItem[] {
  return dashboardNav.filter(
    (item) => item.roles.includes(role) && !item.hidden && !item.group && (!item.flag || user?.[item.flag] === true),
  );
}

/** The pages inside a hub (e.g. the Content hub cards) that this role may open. */
export function groupItems(group: NonNullable<DashboardNavItem["group"]>, role: Role): DashboardNavItem[] {
  return dashboardNav.filter((item) => item.group === group && item.roles.includes(role));
}

/** The menu item a path belongs to (longest matching href). */
export function activeNavItem(pathname: string): DashboardNavItem | undefined {
  return dashboardNav
    .filter((item) =>
      item.href === "/dashboard"
        ? pathname === "/dashboard"
        : pathname === item.href || pathname.startsWith(`${item.href}/`),
    )
    .sort((a, b) => b.href.length - a.href.length)[0];
}
