import { dashboardMetadata } from "@/i18n/server";
import { ContentHubScreen } from "@/components/admin/content-hub";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("content");

// Content hub (Phase 11d-2) — admin / super_admin only; one card per content page.
export default function Page() {
  return <ContentHubScreen />;
}
