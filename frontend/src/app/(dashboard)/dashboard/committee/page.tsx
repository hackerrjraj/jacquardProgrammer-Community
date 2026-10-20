import { dashboardMetadata } from "@/i18n/server";
import { CommitteeAdminScreen } from "@/components/admin/committee-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("committee");

// Committee panel: members, positions, order, term, active — admin / super_admin only.
export default function Page() {
  return <CommitteeAdminScreen />;
}
