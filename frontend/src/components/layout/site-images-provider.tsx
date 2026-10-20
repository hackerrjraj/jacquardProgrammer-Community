"use client";

import { createContext, useContext } from "react";
import type { SiteImageMap } from "@/lib/site-images";

const SiteImagesContext = createContext<SiteImageMap>({});

/** Gives client components (logo, default avatar) the admin-uploaded images. Filled once in the root layout. */
export function SiteImagesProvider({ images, children }: { images: SiteImageMap; children: React.ReactNode }) {
  return <SiteImagesContext.Provider value={images}>{children}</SiteImagesContext.Provider>;
}

export function useSiteImage(key: string): string | null {
  return useContext(SiteImagesContext)[key] ?? null;
}
