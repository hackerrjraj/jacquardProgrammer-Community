import { dashboardMetadata } from "@/i18n/server";
import { ProfileListsAdminScreen } from "@/components/admin/profile-lists-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("lists");

// Machine + Skill lists (Phase 11d-1) — admin / super_admin only.
export default function Page() {
  return <ProfileListsAdminScreen />;
}
