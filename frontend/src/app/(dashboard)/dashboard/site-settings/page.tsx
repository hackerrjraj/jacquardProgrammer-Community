import { dashboardMetadata } from "@/i18n/server";
import { SiteSettingsScreen } from "@/components/admin/site-settings-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("siteSettings");

// Site contact info, About text and the About committee list (Phase 11c) — admin / super_admin only.
export default function Page() {
  return <SiteSettingsScreen />;
}
