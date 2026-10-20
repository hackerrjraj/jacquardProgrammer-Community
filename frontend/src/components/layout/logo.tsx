"use client";

import Image from "next/image";
import Link from "next/link";
import { useSiteImage } from "./site-images-provider";

/**
 * Round teal badge "JPPHAF" — or the logo the admin uploaded (Site Content → Images), which replaces the badge.
 */
export function LogoBadge({ className }: { className?: string }) {
  const logo = useSiteImage("logo");
  if (logo) {
    return (
      <span aria-hidden="true" className={`relative block size-11 shrink-0 ${className ?? ""}`}>
        <Image src={logo} alt="" fill sizes="96px" className="object-contain" />
      </span>
    );
  }
  return (
    <span
      aria-hidden="true"
      className={`flex size-11 shrink-0 items-center justify-center rounded-full bg-brand font-heading text-[0.6rem] font-bold tracking-tight text-white shadow-sm ${className ?? ""}`}
    >
      JPPHAF
    </span>
  );
}

/**
 * Badge + 2-line name, for dark backgrounds (header, footer, sidebar).
 * The name lines are hidden on very small screens unless `alwaysShowName`.
 */
export function Logo({
  lines,
  label,
  href = "/",
  alwaysShowName = false,
}: {
  lines: [string, string];
  /** Accessible name of the link, e.g. "JPPHAF" */
  label: string;
  href?: string;
  alwaysShowName?: boolean;
}) {
  return (
    <Link href={href} aria-label={label} className="flex min-w-0 items-center gap-2.5">
      <LogoBadge />
      <span
        className={`min-w-0 font-heading text-[0.8rem] leading-tight font-semibold text-white ${alwaysShowName ? "block" : "hidden sm:block"}`}
      >
        <span className="block truncate">{lines[0]}</span>
        <span className="block truncate">{lines[1]}</span>
      </span>
    </Link>
  );
}
