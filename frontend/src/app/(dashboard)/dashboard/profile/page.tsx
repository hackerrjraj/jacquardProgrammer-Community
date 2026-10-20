import { dashboardMetadata } from "@/i18n/server";
import { MyProfileScreen } from "@/components/profile/my-profile";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("profile");

// Own profile: preview (like the public profile) + edit sections.
export default function Page() {
  return <MyProfileScreen />;
}
