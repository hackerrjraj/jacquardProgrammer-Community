import { redirect } from "next/navigation";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

/** The Fund Report is members-only: old public link → the dashboard page (logged out → login, then back to the report). */
export default function Page() {
  redirect("/dashboard/fund-report");
}
