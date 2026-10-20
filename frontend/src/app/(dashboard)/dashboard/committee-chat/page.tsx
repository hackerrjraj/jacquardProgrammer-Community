import { dashboardMetadata } from "@/i18n/server";
import { CommitteeChatScreen } from "@/components/chat/committee-chat";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("committeeChat");

// Private Committee Chat — active committee panel members + admin / super_admin (the API checks access).
export default function Page() {
  return <CommitteeChatScreen />;
}
