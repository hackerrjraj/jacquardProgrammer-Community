import { dashboardMetadata } from "@/i18n/server";
import { GalleryAdminScreen } from "@/components/gallery/gallery-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("gallery");

// Gallery management (Phase 6b) — admin / super_admin only.
export default function Page() {
  return <GalleryAdminScreen />;
}
