import type { NextConfig } from "next";

// Slide images (and later profile/gallery images) come from the Laravel API,
// e.g. http://localhost:8000/storage/slides/…
const apiUrl = new URL(process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000");
const apiIsLocal = ["localhost", "127.0.0.1", "[::1]"].includes(apiUrl.hostname);

// Security headers (Phase 12c, MASTER_SPEC §38). The CSP only allows this site, the Laravel API and the Reverb
// chat server. Next.js needs inline scripts (and eval while developing), so those stay allowed.
const isDev = process.env.NODE_ENV !== "production";
const reverbScheme = process.env.NEXT_PUBLIC_REVERB_SCHEME === "https" ? "wss" : "ws";
const reverbOrigin = `${reverbScheme}://${process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost"}:${process.env.NEXT_PUBLIC_REVERB_PORT ?? "8080"}`;
// Pusher Channels (cPanel hosting): the browser talks to wss://ws-<cluster>.pusher.com. Only added when a key is set.
const pusherCluster = process.env.NEXT_PUBLIC_PUSHER_APP_CLUSTER ?? "mt1";
const pusherOrigins = process.env.NEXT_PUBLIC_PUSHER_APP_KEY
  ? ` wss://ws-${pusherCluster}.pusher.com https://sockjs-${pusherCluster}.pusher.com`
  : "";
const csp = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline'${isDev ? " 'unsafe-eval'" : ""}`,
  "style-src 'self' 'unsafe-inline'",
  `img-src 'self' data: blob: ${apiUrl.origin}`,
  "font-src 'self' data:",
  `connect-src 'self' ${apiUrl.origin} ${reverbOrigin}${pusherOrigins}`,
  "worker-src 'self'",
  "manifest-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join("; ");
const securityHeaders = [
  { key: "Content-Security-Policy", value: csp },
  { key: "X-Frame-Options", value: "DENY" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
];

const nextConfig: NextConfig = {
  /* config options here */
  poweredByHeader: false,
  // Phase 14a: a self-contained build (.next/standalone) for cPanel / Passenger; see deploy-package.bat
  output: "standalone",
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
  cacheComponents: true,
  partialPrefetching: true,
  images: {
    remotePatterns: [
      {
        protocol: apiUrl.protocol.replace(":", "") as "http" | "https",
        hostname: apiUrl.hostname,
        port: apiUrl.port,
        pathname: "/storage/**",
      },
    ],
    // Next 16 refuses to optimize images from local addresses by default.
    // Allowed only while the API itself runs on this computer.
    dangerouslyAllowLocalIP: apiIsLocal,
  },
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
