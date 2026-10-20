import { LOCALE_COOKIE, toLocale, type Dictionary } from "@/i18n/config";
import { API_URL, type AidReason, type AidStatus, type User } from "@/lib/api";

// Same rules as the backend (AssistanceController::store, AssistanceRequest constants, MASTER_SPEC §29, §38).
export const REASONS: readonly AidReason[] = ["medical", "accident", "death", "job_loss", "other"];
export const OPEN_STATUSES: readonly AidStatus[] = ["submitted", "approved"];
export const AMOUNT_MAX = 10_000_000;
export const DESCRIPTION_MAX = 2000;
export const REASON_OTHER_MAX = 150;
export const FOR_NAME_MAX = 100;
export const FOR_RELATION_MAX = 50;
export const MAX_FILES = 5;
export const FILE_MAX_BYTES = 5 * 1024 * 1024;
export const FILE_TYPES = ["image/jpeg", "image/png", "image/webp", "application/pdf"];

/** Demo programmers (DemoProgrammersSeeder::EMAIL_DOMAIN) cannot apply or vote — the API refuses them too. */
export function isDemoAccount(user: User): boolean {
  return !!user.email && user.email.toLowerCase().endsWith("@demo.jpphaf.local");
}

/** "Medical treatment", or the member's own words for "other". */
export function reasonText(r: { reason_type: AidReason; reason_other: string | null }, t: Dictionary): string {
  return r.reason_type === "other" && r.reason_other ? r.reason_other : t.assistance.reasons[r.reason_type];
}

const badgeTones: Record<AidStatus, string> = {
  submitted: "bg-warning-soft text-foreground",
  approved: "bg-primary-soft text-primary-text",
  paid: "bg-success-soft text-success",
  rejected: "bg-danger-soft text-danger",
  cancelled: "bg-border/70 text-muted",
};

export function AidBadge({ status, t }: { status: AidStatus; t: Dictionary }) {
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${badgeTones[status]}`}>
      <span className="size-1.5 shrink-0 rounded-full bg-current" />
      {t.assistance.statuses[status]}
    </span>
  );
}

const EXTENSIONS: Record<string, string> = {
  "application/pdf": "pdf",
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
};

/**
 * Saves a login-only file (e.g. the payout proof, whose type is not known in advance) under `baseName`
 * + the extension of its real type. Returns the HTTP status (0 = network error).
 */
export async function downloadPrivateFile(url: string, baseName: string): Promise<number> {
  try {
    const lang = toLocale(document.cookie.match(new RegExp(`(?:^|; )${LOCALE_COOKIE}=([^;]*)`))?.[1]);
    const res = await fetch(url.startsWith("http") ? url : `${API_URL}${url}`, {
      credentials: "include",
      cache: "no-store",
      headers: { "Accept-Language": lang },
    });
    if (!res.ok) return res.status;
    const blob = await res.blob();
    const ext = EXTENSIONS[blob.type.split(";")[0]];
    const blobUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = blobUrl;
    a.download = ext ? `${baseName}.${ext}` : baseName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);
    return res.status;
  } catch {
    return 0;
  }
}
