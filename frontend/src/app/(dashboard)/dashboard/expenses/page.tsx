import { dashboardMetadata } from "@/i18n/server";
import { ExpensesScreen } from "@/components/admin/expenses-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("expenses");

// Expenses (খরচের হিসাব) — admin / super_admin only. The API checks again.
export default function Page() {
  return <ExpensesScreen />;
}
