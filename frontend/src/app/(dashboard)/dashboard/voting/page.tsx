import { redirect } from "next/navigation";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

// Voting on aid requests is part of the Assistance page since Phase 8b (open to every approved account).
export default function Page() {
  redirect("/dashboard/assistance");
}
