import { dashboardMetadata } from "@/i18n/server";
import { ProgrammerDetailPage } from "@/components/programmers/programmer-detail";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("programmers");

// Member profile (read-only for committee); the id is read in the browser.
export default function Page() {
  return <ProgrammerDetailPage />;
}