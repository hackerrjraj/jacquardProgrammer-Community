"use client";

import { useDictionary } from "@/i18n/client";
import type { User } from "@/lib/api";

/** Membership status pill with a dot (approved / pending / rejected / suspended). */
export function StatusBadge({ status }: { status: User["status"] }) {
  const t = useDictionary();
  const tone = {
    approved: "bg-success-soft text-success",
    pending: "bg-warning-soft text-foreground",
    rejected: "bg-danger-soft text-danger",
    suspended: "bg-danger-soft text-danger",
  }[status];
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone}`}>
      <span aria-hidden="true" className={`size-1.5 rounded-full ${status === "pending" ? "bg-warning" : "bg-current"}`} />
      {t.statuses[status]}
    </span>
  );
}
