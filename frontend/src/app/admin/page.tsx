import { redirect } from "next/navigation";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

// The admin screens live in the dashboard (Phase 4b).
export default function Page() {
  redirect("/dashboard/members");
}