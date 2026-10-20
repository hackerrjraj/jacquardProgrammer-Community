import { DashboardShell } from "@/components/dashboard/dashboard-shell";

/** Dashboard (MASTER_SPEC §37a): own sidebar + top bar, no public header/footer. */
export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return <DashboardShell>{children}</DashboardShell>;
}
