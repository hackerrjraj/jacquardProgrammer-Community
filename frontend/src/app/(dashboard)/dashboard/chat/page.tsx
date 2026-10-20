import { dashboardMetadata } from "@/i18n/server";
import { ChatListScreen } from "@/components/chat/chat-list";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("chat");

// 1-to-1 chat: my conversations (Phase 9b) — any approved account.
export default function Page() {
  return <ChatListScreen />;
}
