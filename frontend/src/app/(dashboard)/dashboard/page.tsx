import { dashboardMetadata } from "@/i18n/server";
import { DashboardHome } from "@/components/dashboard/dashboard-home";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("dashboard");

export default function Page() {
  return <DashboardHome />;
}