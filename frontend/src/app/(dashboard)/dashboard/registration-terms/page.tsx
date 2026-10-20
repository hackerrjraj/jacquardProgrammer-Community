import { dashboardMetadata } from "@/i18n/server";
import { RegistrationTermsScreen } from "@/components/admin/registration-terms-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("registrationTerms");

// Registration terms & conditions editor — admin / super_admin only.
export default function Page() {
  return <RegistrationTermsScreen />;
}
