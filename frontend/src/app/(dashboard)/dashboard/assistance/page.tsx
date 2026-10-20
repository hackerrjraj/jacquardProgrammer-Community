import { dashboardMetadata } from "@/i18n/server";
import { AssistanceScreen } from "@/components/assistance/member-assistance";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("assistance");

// Own aid requests (apply, view, cancel) + advisory voting on others' open requests (Phase 8b) — any approved account.
export default function Page() {
  return <AssistanceScreen />;
}
