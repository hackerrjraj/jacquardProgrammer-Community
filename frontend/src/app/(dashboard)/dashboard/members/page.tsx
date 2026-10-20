import { dashboardMetadata } from "@/i18n/server";
import { MembersAdminScreen } from "@/components/admin/members-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("members");

// Member approvals (moved from /admin/members).
export default function Page() {
  return <MembersAdminScreen />;
}
