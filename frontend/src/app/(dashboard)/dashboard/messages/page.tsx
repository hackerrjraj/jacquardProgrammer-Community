import { dashboardMetadata } from "@/i18n/server";
import { ContactMessagesScreen } from "@/components/admin/contact-messages-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("messages");

// Contact form messages (Phase 11a) — admin + super_admin; only Super Admin deletes.
export default function Page() {
  return <ContactMessagesScreen />;
}
