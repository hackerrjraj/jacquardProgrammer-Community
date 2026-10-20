import { dashboardMetadata } from "@/i18n/server";
import { PaymentReviewScreen } from "@/components/payments/payment-review";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("paymentReview");

// Payment verification queue (Phase 7c) — admin / super_admin only.
export default function Page() {
  return <PaymentReviewScreen />;
}
