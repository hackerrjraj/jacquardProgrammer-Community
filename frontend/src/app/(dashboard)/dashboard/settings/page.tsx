import { dashboardMetadata } from "@/i18n/server";
import { FundSettingsScreen } from "@/components/settings/fund-settings";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("settings");

// Fund Settings (Phase 7e) — Super Admin only. Quorum / limits / contact info come with their phases.
export default function Page() {
  return <FundSettingsScreen />;
}
