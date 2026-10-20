import { cacheLife, cacheTag } from "next/cache";
import { API_URL } from "@/lib/api";

/** Admin-uploaded site images (Site Content → Images): {key: url}, uploaded ones only. {} when the API cannot be reached. */
export type SiteImageMap = Record<string, string>;

/** Server Components only. A missing key means "use the built-in design". */
export async function getSiteImages(): Promise<SiteImageMap> {
  "use cache";
  cacheTag("site-content");
  cacheLife({ stale: 30, revalidate: 30, expire: 3600 });
  try {
    const res = await fetch(`${API_URL}/api/public/site-images`, {
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(3000),
    });
    if (!res.ok) {
      cacheLife({ stale: 0, revalidate: 5, expire: 10 });
      return {};
    }
    const body = (await res.json()) as { data?: SiteImageMap };
    return body.data && typeof body.data === "object" ? body.data : {};
  } catch {
    cacheLife({ stale: 0, revalidate: 5, expire: 10 });
    return {};
  }
}

export async function getSiteImage(key: string): Promise<string | null> {
  return (await getSiteImages())[key] ?? null;
}
