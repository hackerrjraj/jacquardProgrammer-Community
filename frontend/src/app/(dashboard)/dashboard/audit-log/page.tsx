import { dashboardMetadata } from "@/i18n/server";
import { AuditLogScreen } from "@/components/admin/audit-log-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("audit");

// Audit log (Phase 11a) — admin + super_admin, read-only.
export default function Page() {
  return <AuditLogScreen />;
}
