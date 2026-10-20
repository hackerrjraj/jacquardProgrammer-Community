import { dashboardMetadata } from "@/i18n/server";
import { ChatReportsScreen } from "@/components/chat/chat-reports-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("chatReports");

// Chat reports (Phase 9d-1) — admin + super_admin; only the reported message, never the chat.
export default function Page() {
  return <ChatReportsScreen />;
}
