import { dashboardMetadata } from "@/i18n/server";
import { MemberPaymentsScreen } from "@/components/payments/member-payments";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("payments");

// Own dues + payments (Phase 7b) — any approved account. The admin verification queue comes in 7c.
export default function Page() {
  return <MemberPaymentsScreen />;
}
