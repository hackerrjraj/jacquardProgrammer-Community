import { dashboardMetadata } from "@/i18n/server";
import { AidAdminScreen } from "@/components/assistance/aid-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("aid");

// Aid Applications (Phase 8c) — admin / super_admin only.
export default function Page() {
  return <AidAdminScreen />;
}
