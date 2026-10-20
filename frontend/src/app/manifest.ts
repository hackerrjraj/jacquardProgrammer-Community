import type { MetadataRoute } from "next";

// Installable app (Phase 10b, MASTER_SPEC §36). Opens straight into the member dashboard.
export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/",
    name: "JPPHAF",
    short_name: "JPPHAF",
    description:
      "Jacquard Programmer Panel Humanitarian Assistance Fund — জ্যাকার্ড ও কম্পিউটারাইজড ফ্ল্যাট নিটিং প্রোগ্রামারদের মানবিক সহায়তা তহবিল",
    lang: "bn",
    dir: "ltr",
    start_url: "/dashboard",
    scope: "/",
    display: "standalone",
    // Navy of the header / sidebar (MASTER_SPEC §8a)
    background_color: "#021d30",
    theme_color: "#021d30",
    icons: [
      { src: "/site-icons/icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/site-icons/icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/site-icons/icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
