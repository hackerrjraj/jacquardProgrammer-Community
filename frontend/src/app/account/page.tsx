import { redirect } from "next/navigation";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

// Moved into the dashboard (Phase 4b).
export default function Page() {
  redirect("/dashboard/profile");
}