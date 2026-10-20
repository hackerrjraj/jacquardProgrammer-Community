import { dashboardMetadata } from "@/i18n/server";
import { ChatThreadPage } from "@/components/chat/chat-thread";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("chat");

// One conversation (Phase 9b); the id is read in the browser.
export default function Page() {
  return <ChatThreadPage />;
}
