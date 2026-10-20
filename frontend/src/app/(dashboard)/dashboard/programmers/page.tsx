import { dashboardMetadata } from "@/i18n/server";
import { ProgrammersListScreen } from "@/components/programmers/programmers-list";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("programmers");

// Admin, super_admin, committee (read-only). Payment columns: Phase 7.
export default function Page() {
  return <ProgrammersListScreen />;
}