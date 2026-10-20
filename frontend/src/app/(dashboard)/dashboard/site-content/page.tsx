import { dashboardMetadata } from "@/i18n/server";
import { SiteContentScreen } from "@/components/admin/site-content-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("siteContent");

// Editable public-page text, grouped by page (Phase 14a) — admin / super_admin only.
export default function Page() {
  return <SiteContentScreen />;
}
