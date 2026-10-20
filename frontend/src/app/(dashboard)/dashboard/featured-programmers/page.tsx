import { dashboardMetadata } from "@/i18n/server";
import { FeaturedProgrammersAdminScreen } from "@/components/admin/featured-programmers-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("featured");

// Featured programmers, shown first in the Home rows (Phase 11b-2) — admin / super_admin only.
export default function Page() {
  return <FeaturedProgrammersAdminScreen />;
}
