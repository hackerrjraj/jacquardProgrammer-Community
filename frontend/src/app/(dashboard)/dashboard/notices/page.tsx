import { dashboardMetadata } from "@/i18n/server";
import { NoticesAdminScreen } from "@/components/notices/notices-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("notices");

// Notices management (Phase 6c) — admin / super_admin only.
export default function Page() {
  return <NoticesAdminScreen />;
}
