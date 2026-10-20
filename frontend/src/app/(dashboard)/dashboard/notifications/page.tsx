import { dashboardMetadata } from "@/i18n/server";
import { NotificationsScreen } from "@/components/notifications/notifications-page";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("notifications");

// All my notifications (Phase 10a) — opened from the bell; any approved account.
export default function Page() {
  return <NotificationsScreen />;
}
