"use server";

import { updateTag } from "next/cache";

/** Called by the admin screens right after Site Content (text or images) is saved, so public pages show it at once. */
export async function revalidateSiteContent(): Promise<void> {
  updateTag("site-content");
}
