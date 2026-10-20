import { dashboardMetadata } from "@/i18n/server";
import { ChatBlockedScreen } from "@/components/chat/chat-blocked";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("chat");

// Members I blocked in chat, with Unblock (Phase 9d-1).
export default function Page() {
  return <ChatBlockedScreen />;
}
