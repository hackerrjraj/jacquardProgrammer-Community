"use client";

import { useDictionary } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { FundReportView } from "./fund-report";

/** /dashboard/fund-report — logged-in approved accounts only (logged out → /login?next=…). */
export function FundReportScreen() {
  const t = useDictionary();
  return (
    <RequireAuth>
      {() => (
        <div className="space-y-6">
          <div>
            <h1 className="text-2xl font-bold">{t.fundReport.heroTitle}</h1>
            <p className="mt-1 text-sm">{t.fundReport.heroSubtitle}</p>
          </div>
          <FundReportView />
        </div>
      )}
    </RequireAuth>
  );
}
