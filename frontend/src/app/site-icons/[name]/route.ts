import { getSiteImages } from "@/lib/site-images";

/**
 * App / browser icons from the same address as the site (Site Content → Images → Favicon). The uploaded sizes are
 * passed through; with no upload this redirects to the built-in icon, so the manifest can always point here.
 */
const ICONS: Record<string, { key: string; fallback: string }> = {
  "icon-192.png": { key: "favicon_icon-192", fallback: "/icons/icon-192.png" },
  "icon-512.png": { key: "favicon_icon-512", fallback: "/icons/icon-512.png" },
  "icon-maskable-512.png": { key: "favicon_icon-maskable-512", fallback: "/icons/icon-maskable-512.png" },
  "apple-touch-icon.png": { key: "favicon_apple-touch-icon", fallback: "/icons/apple-touch-icon.png" },
  "favicon-32.png": { key: "favicon_favicon-32", fallback: "/favicon.ico" },
};

export async function GET(request: Request, { params }: { params: Promise<{ name: string }> }) {
  const icon = ICONS[(await params).name];
  if (!icon) return new Response("Not found", { status: 404 });

  const url = (await getSiteImages())[icon.key];
  if (url) {
    try {
      const res = await fetch(url, { cache: "no-store", signal: AbortSignal.timeout(5000) });
      if (res.ok) {
        return new Response(await res.arrayBuffer(), {
          headers: { "Content-Type": "image/png", "Cache-Control": "public, max-age=300" },
        });
      }
    } catch {
      // fall through to the built-in icon
    }
  }
  return Response.redirect(new URL(icon.fallback, request.url), 307);
}
