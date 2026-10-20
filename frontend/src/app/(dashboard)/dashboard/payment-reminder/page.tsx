import { dashboardMetadata } from "@/i18n/server";
import { PaymentReminderScreen } from "@/components/admin/payment-reminder-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("paymentReminder");

// Payment-due reminder editor — admin / super_admin only.
export default function Page() {
  return <PaymentReminderScreen />;
}
