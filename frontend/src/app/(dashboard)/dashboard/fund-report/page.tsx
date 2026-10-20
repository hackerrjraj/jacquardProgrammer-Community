import { Suspense } from "react";
import { dashboardMetadata } from "@/i18n/server";
import { FundReportScreen } from "@/components/fund-report/fund-report-screen";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("fundReport");

// Fund Report — members only (MASTER_SPEC §33): every approved account. The API checks again (401 / 403).
export default function Page() {
  return (
    <Suspense>
      <FundReportScreen />
    </Suspense>
  );
}
