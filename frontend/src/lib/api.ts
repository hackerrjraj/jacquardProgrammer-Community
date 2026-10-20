import { LOCALE_COOKIE, toLocale, type Locale } from "@/i18n/config";

/**
 * The one place for all calls to the Laravel API.
 *
 * - Base URL from NEXT_PUBLIC_API_URL (http://localhost:8000).
 * - Always sends Accept: application/json and Accept-Language (bn/en).
 * - Never throws: if the API is down or slow, you get { ok: false, status: 0 }.
 *
 * Server Components: pass the locale from getLocale().
 * Client Components: the locale is read from the `lang` cookie if not passed.
 */

export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

const TIMEOUT_MS = 8000;

export type ApiResult<T> =
  | { ok: true; status: number; data: T }
  // status 0 = network error / timeout (API not reachable)
  | { ok: false; status: number; data: ApiErrorBody | null };

export type ApiErrorBody = {
  message?: string;
  errors?: Record<string, string[]>;
};

const isBrowser = typeof document !== "undefined";

function readCookie(name: string): string | undefined {
  if (!isBrowser) return undefined;
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : undefined;
}

/**
 * Sanctum (stateful API) requires a CSRF token for POST/PUT/DELETE from the
 * browser: GET /sanctum/csrf-cookie sets the XSRF-TOKEN cookie, which we send
 * back in the X-XSRF-TOKEN header.
 */
async function csrfToken(refresh = false): Promise<string | undefined> {
  if (refresh || !readCookie("XSRF-TOKEN")) {
    await fetch(`${API_URL}/sanctum/csrf-cookie`, {
      credentials: "include",
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
  }
  return readCookie("XSRF-TOKEN");
}

/** Called after every successful change under /admin (approve, reject, mark read ...) - the sidebar badges reload then. */
const adminChangeListeners = new Set<() => void>();
export function onAdminChange(listener: () => void): () => void {
  adminChangeListeners.add(listener);
  return () => adminChangeListeners.delete(listener);
}

/** Lets other code (e.g. the Committee Chat marking itself read) reload the admin badges. */
export function notifyAdminChange(): void {
  adminChangeListeners.forEach((l) => l());
}

/** File uploads may be slow on mobile data. */
const UPLOAD_TIMEOUT_MS = 60000;

export async function apiFetch<T>(
  path: string,
  {
    locale,
    method = "GET",
    body,
  }: {
    locale?: Locale;
    method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
    /** JSON-encoded, or a FormData (multipart file upload) sent as-is */
    body?: unknown;
  } = {},
): Promise<ApiResult<T>> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Accept-Language": locale ?? toLocale(readCookie(LOCALE_COOKIE)),
  };
  const isForm = typeof FormData !== "undefined" && body instanceof FormData;
  // FormData: the browser sets the multipart Content-Type (with boundary) itself.
  if (body !== undefined && !isForm) headers["Content-Type"] = "application/json";
  const needsCsrf = isBrowser && method !== "GET";

  const send = async (refreshCsrf: boolean) => {
    if (needsCsrf) {
      const token = await csrfToken(refreshCsrf);
      if (token) headers["X-XSRF-TOKEN"] = token;
    }
    return fetch(`${API_URL}/api${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
      cache: "no-store",
      // Browser: send/receive the Laravel session + CSRF cookies
      credentials: isBrowser ? "include" : "omit",
      signal: AbortSignal.timeout(isForm ? UPLOAD_TIMEOUT_MS : TIMEOUT_MS),
    });
  };

  try {
    let res = await send(false);
    // 419 = CSRF token expired (old session) → get a fresh one, retry once
    if (res.status === 419 && needsCsrf) res = await send(true);
    const json = await res.json().catch(() => null);
    if (res.ok) {
      if (method !== "GET" && path.startsWith("/admin/")) adminChangeListeners.forEach((l) => l());
      return { ok: true, status: res.status, data: json as T };
    }
    return { ok: false, status: res.status, data: json as ApiErrorBody | null };
  } catch {
    return { ok: false, status: 0, data: null };
  }
}

/**
 * Multipart POST with upload progress (fetch cannot report it, so this uses XMLHttpRequest).
 * Same rules as apiFetch: CSRF token, cookies, Accept-Language, one retry on 419, never throws.
 * `onProgress` gets 0–100.
 */
export async function apiUpload<T>(
  path: string,
  body: FormData,
  onProgress?: (percent: number) => void,
): Promise<ApiResult<T>> {
  const send = (token: string | undefined) =>
    new Promise<{ status: number; json: unknown }>((resolve) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", `${API_URL}/api${path}`);
      xhr.withCredentials = true;
      xhr.timeout = UPLOAD_TIMEOUT_MS;
      xhr.setRequestHeader("Accept", "application/json");
      xhr.setRequestHeader("Accept-Language", toLocale(readCookie(LOCALE_COOKIE)));
      if (token) xhr.setRequestHeader("X-XSRF-TOKEN", token);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress?.(Math.round((e.loaded / e.total) * 100));
      };
      xhr.onload = () => {
        let json: unknown = null;
        try {
          json = JSON.parse(xhr.responseText);
        } catch {
          // not JSON (e.g. a proxy error page)
        }
        resolve({ status: xhr.status, json });
      };
      xhr.onerror = xhr.ontimeout = xhr.onabort = () => resolve({ status: 0, json: null });
      xhr.send(body);
    });

  try {
    let res = await send(await csrfToken(false));
    if (res.status === 419) res = await send(await csrfToken(true));
    if (res.status >= 200 && res.status < 300) return { ok: true, status: res.status, data: res.json as T };
    return { ok: false, status: res.status, data: res.json as ApiErrorBody | null };
  } catch {
    return { ok: false, status: 0, data: null };
  }
}

/**
 * Downloads a private file from the API (e.g. a member document's download_url)
 * with the session cookie and saves it under `filename`. Returns the HTTP status
 * (0 = network error), so the caller can show a message.
 */
export async function apiDownload(url: string, filename: string): Promise<number> {
  try {
    const res = await fetch(url, {
      credentials: "include",
      cache: "no-store",
      headers: { "Accept-Language": toLocale(readCookie(LOCALE_COOKIE)) },
      signal: AbortSignal.timeout(UPLOAD_TIMEOUT_MS),
    });
    if (!res.ok) return res.status;
    const blobUrl = URL.createObjectURL(await res.blob());
    const a = document.createElement("a");
    a.href = blobUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);
    return res.status;
  } catch {
    return 0;
  }
}

// ---- Response types ----

export type HeroSlide = {
  id: number;
  image_url: string;
  title_bn: string | null;
  title_en: string | null;
  subtitle_bn: string | null;
  subtitle_en: string | null;
  button_text_bn: string | null;
  button_text_en: string | null;
  button_link: string | null;
  sort_order: number;
};

/** GET /api/admin/hero-slides — all slides (shown + hidden), in display order (Phase 11b-1). */
export type AdminHeroSlide = HeroSlide & {
  is_active: boolean;
  created_at: string | null;
  updated_at: string | null;
};

/** A person in the featured-programmers admin screen (Phase 11b-2): GET /api/admin/featured-programmers/candidates. */
export type FeaturedCandidate = {
  id: number;
  name: string;
  phone: string | null;
  photo_url: string | null;
  designation: string | null;
  district: string | null;
};

/** GET /api/admin/featured-programmers — in Home order; shown_on_home = false when suspended / no longer listed. */
export type FeaturedProgrammer = FeaturedCandidate & {
  featured_order: number;
  shown_on_home: boolean;
  status: Status;
};

/** GET /api/public/site-info + /api/admin/site-settings (Phase 11c): every field may be null (= not shown). */
export type SiteInfo = {
  phone_1: string | null;
  phone_2: string | null;
  email: string | null;
  whatsapp: string | null;
  facebook_url: string | null;
  address_bn: string | null;
  address_en: string | null;
  office_hours_bn: string | null;
  office_hours_en: string | null;
  about_bn: string | null;
  about_en: string | null;
};

/** GET /api/public/committee — name, photo and position only (never mobile / email). */
export type CommitteeMember = { name: string; photo_url: string | null; position: string | null; profile_id: number | null };

/** GET /api/admin/committee (admin + super_admin) — members in display order + the position list. */
export type CommitteePanelMember = {
  id: number;
  user_id: number;
  name: string;
  phone: string | null;
  photo_url: string | null;
  position_id: number | null;
  position: { name_en: string; name_bn: string } | null;
  display_order: number;
  term_start: string | null;
  term_end: string | null;
  is_active: boolean;
  account_status: Status;
  account_role: Role;
};
export type CommitteePositionItem = { id: number; name_en: string; name_bn: string; in_use: number };
export type CommitteePanel = { data: CommitteePanelMember[]; positions: CommitteePositionItem[] };
export type CommitteeCandidate = { id: number; name: string; phone: string | null; photo_url: string | null; designation: string | null };

export type Role = "member" | "committee" | "admin" | "super_admin";
export type Status = "pending" | "approved" | "rejected" | "suspended";

/** UserResource from the backend (GET /api/me, admin member list). */
export type User = {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  district: string | null;
  workplace: string | null;
  experience_years: number | null;
  /** Saved list items with their current names (Phase 11d-1) — hidden items too. */
  machines: Option[];
  skills: Option[];
  /** Free "Other" text the member typed (max 100 chars), or null. */
  other_machine?: string | null;
  other_skill?: string | null;
  role: Role;
  status: Status;
  rejection_reason: string | null;
  must_change_password: boolean;
  approved_at: string | null;
  approved_by: number | null;
  created_at: string | null;
  /** Own account only: may open the private Committee Chat (decides the menu item). */
  committee_chat?: boolean;
};

export type District = { key: string; name_bn: string; name_en: string };

/** Laravel paginated resource collection. */
export type Paginated<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

// ---- Home / About (Phase 6d-1 API) ----

/** GET /api/public/stats — real counts (cached 10 min). No fund money (members-only Fund Report). */
export type PublicStats = {
  members: number;
  programmers: number;
  companies: number;
  locations: number;
  max_experience_years: number | null;
  notices: number;
  gallery_photos: number;
  updated_at: string;
};

// ---- Fund Report — members only (MASTER_SPEC §33) ----

/** GET /api/fund-report — totals + one row per month (newest first), demo left out. */
export type FundReport = {
  total_collected: number;
  total_disbursed: number;
  total_expenses: number;
  /** Net of all adjustments + reversals (Phase 12b), signed taka — shown only as this one total. */
  corrections: number;
  fund_balance: number;
  cases_paid: number;
  monthly: { month: string; collected: number; disbursed: number }[];
  updated_at: string;
};

/** GET /api/fund-report/expenses — members see only date, category, purpose and amount. */
export type FundReportExpense = {
  id: number;
  date: string;
  category: { name_bn: string; name_en: string } | null;
  purpose: string;
  amount: number;
};

// ---- Expenses (admin) ----

export type ExpenseCategory = { id: number; name_bn: string; name_en: string; is_active: boolean; expenses_count: number };

export type Expense = {
  id: number;
  expense_date: string;
  amount: number;
  category: { id: number; name_bn: string; name_en: string } | null;
  purpose: string;
  paid_to: string | null;
  has_receipt: boolean;
  receipt_url: string | null;
  created_by: string | null;
};

export type ExpenseSummary = { total_collected: number; total_disbursed: number; total_expenses: number; balance: number };

// ---- Fund ledger corrections + integrity check (Phase 12b, Super Admin) ----

export type LedgerEntryType = "contribution" | "disbursement" | "adjustment" | "reversal";

/** GET /api/admin/ledger — one entry. `effect` = signed taka effect on the balance. */
export type LedgerEntry = {
  id: number;
  type: LedgerEntryType;
  direction: "in" | "out" | null;
  amount: number;
  effect: number;
  entry_date: string;
  reason: string | null;
  member: { id: number; name: string } | null;
  case_id: string | null;
  payment_id: number | null;
  reverses: { id: number; type: LedgerEntryType } | null;
  reversed_by: number | null;
  can_reverse: boolean;
  created_by: string | null;
  created_at: string | null;
};

/** balance = whole ledger (payout check); public_balance = Home / Fund Report (demo left out). */
export type LedgerFigures = { balance: number; public_balance: number; corrections: number };

export type IntegrityIssue = {
  code: string;
  severity: "problem" | "note";
  [key: string]: string | number | null;
};

/** GET /api/admin/ledger/integrity — read-only. */
export type IntegrityResult = {
  ok: boolean;
  balance: number;
  checked_entries: number;
  issues: IntegrityIssue[];
  checked_at: string;
};

/** GET /api/fund-report/cases — a paid aid case; recipient_name is null unless the member consented. */
export type FundReportCase = {
  case_id: string;
  reason: AidReason;
  amount: number;
  date: string | null;
  recipient_name: string | null;
};

/** GET /api/public/expertise-cards — published, in display order. `title` / `description` already localized. */
export type ExpertiseCard = {
  id: number;
  icon: string | null;
  title: string;
  description: string;
  title_bn: string;
  title_en: string | null;
  description_bn: string;
  description_en: string | null;
  image_url: string | null;
  sort_order: number;
};

/** GET /api/admin/expertise-cards — all cards (published + hidden), in display order. */
export type AdminExpertiseCard = ExpertiseCard & {
  is_published: boolean;
  created_at: string | null;
  updated_at: string | null;
};

// ---- Profile (Phase 4a API) ----

export type Visibility = "public" | "members" | "hidden";
export type EmploymentStatus = "running_job" | "jobless";

export type WorkEntry = {
  company: string;
  position: string;
  start_year: number;
  /** a year, or "present" */
  end_year: number | "present";
};

export type PortfolioItem = {
  id: number;
  image_url: string;
  category: string;
  title: string;
  description: string | null;
  sort_order: number;
};

/** File fields are missing for viewers who may not download (committee: type + date only). */
export type MemberDocument = {
  id: number;
  type: string;
  original_name?: string;
  mime_type?: string;
  size?: number;
  download_url?: string;
  created_at: string | null;
};

/** ProfileResource: GET /api/profile and GET /api/admin/members/{id}/profile. */
export type Profile = User & {
  photo_url: string | null;
  bio: string | null;
  employment_status: EmploymentStatus | null;
  designation: string | null;
  phone_visibility: Visibility;
  email_visibility: Visibility;
  workplace_visibility: Visibility;
  work_history: WorkEntry[];
  portfolio: PortfolioItem[];
  documents: MemberDocument[];
};

/** One row of GET /api/admin/members (MemberListResource). */
export type MemberListItem = User & {
  photo_url: string | null;
  employment_status: EmploymentStatus | null;
  designation: string | null;
  /** Phase 7d; null for accounts that owe no dues (pending / rejected registrations) */
  payments: { total_paid: number; total_due: number; months_due: number; last_paid_month: string | null } | null;
  /** Only when the list was asked with ?month= (Phase 7f): that month's state; null = owes nothing for it */
  month_status?: "paid" | "pending" | "due" | null;
};

/** GET /api/admin/members?month=YYYY-MM — the page + that month's counts over the same filters (Phase 7f). */
export type MemberListPage = Paginated<MemberListItem> & {
  month_counts: { month: string; paid: number; due: number; pending: number } | null;
};

export type Option = { key: string; name_bn: string; name_en: string };

/** GET /api/profile-options. machines / skills = the active items of the admin lists, in order (Phase 11d-1). */
export type ProfileOptions = {
  employment_statuses: Option[];
  document_types: Option[];
  portfolio_categories: Option[];
  machines: Option[];
  skills: Option[];
};

export type ProfileListType = "machine" | "skill";

/** An item of the Machine / Skill list (GET /api/admin/profile-lists, Phase 11d-1). */
export type AdminProfileListItem = Option & {
  id: number;
  type: ProfileListType;
  is_active: boolean;
  sort_order: number;
  /** Accounts that have this item saved — only unused items can be deleted (super_admin). */
  used_by: number;
  updated_at: string | null;
};

export type AdminProfileLists = { machines: AdminProfileListItem[]; skills: AdminProfileListItem[] };

/** One typed "Other" text (GET /api/admin/profile-lists/others), grouped by text. */
export type OtherEntry = { text: string; count: number; members: string[] };
export type OtherEntries = { machines: OtherEntry[]; skills: OtherEntry[] };

// ---- Public Programmers directory (Phase 5a API) ----

/** A directory card (GET /api/public/programmers). `workplace` is missing when the member hides it. */
export type PublicProgrammer = {
  id: number;
  name: string;
  photo_url: string | null;
  designation: string | null;
  employment_status: EmploymentStatus | null;
  district: string;
  workplace?: string | null;
  experience_years: number;
  machines: Option[];
  bio_excerpt: string | null;
  portfolio_count: number;
};

/** GET /api/public/programmers/{id}. `phone` / `email` only when the member's visibility allows them. */
export type PublicProgrammerProfile = Omit<PublicProgrammer, "bio_excerpt"> & {
  phone?: string;
  email?: string;
  /** Phase 9b: approved, non-demo → the "Message" button may be shown */
  accepts_messages: boolean;
  bio: string | null;
  skills: Option[];
  work_history: WorkEntry[];
  portfolio: Omit<PortfolioItem, "sort_order">[];
};

/** GET /api/public/programmers/filters */
export type DirectoryFilters = { districts: District[]; machines: Option[] };

// ---- Gallery (Phase 6a API) ----

/** A gallery tab. `images_count` = published photos (public list) or all photos (admin list). */
export type GalleryCategory = {
  id: number;
  key: string;
  name_bn: string;
  name_en: string;
  sort_order: number;
  images_count: number;
};

/** GET /api/public/gallery (24 per page). */
export type GalleryImage = {
  id: number;
  title_bn: string | null;
  title_en: string | null;
  image_url: string;
  thumb_url: string;
  width: number;
  height: number;
  category: { key: string; name_bn: string; name_en: string };
};

/** GET /api/admin/gallery/images (30 per page, unpublished too). */
export type AdminGalleryImage = GalleryImage & {
  is_published: boolean;
  sort_order: number;
  created_at: string | null;
  updated_at: string | null;
};

// ---- Notices (Phase 6c API) ----

export type NoticeVisibility = "public" | "members";

/** `is_private` → the url is the login-only API route (members-only notice); otherwise a public file URL. */
export type NoticeAttachment = {
  url: string;
  name: string;
  mime_type: string;
  size: number;
  is_image: boolean;
  is_private: boolean;
};

/** GET /api/public/notices, GET /api/notices (members) — 12 per page, pinned first then newest. */
export type Notice = {
  id: number;
  title_bn: string;
  title_en: string | null;
  body_bn: string;
  body_en: string | null;
  visibility: NoticeVisibility;
  is_pinned: boolean;
  published_at: string | null;
  attachment: NoticeAttachment | null;
};

/** GET /api/admin/notices (20 per page, drafts and scheduled too). */
export type AdminNotice = Notice & {
  is_published: boolean;
  is_scheduled: boolean;
  created_by: number | null;
  created_at: string | null;
  updated_at: string | null;
};

// ---- Fund contributions (Phase 7a API) ----

export type PaymentMethod = "bkash" | "nagad" | "rocket" | "bank" | "cash";
export type ContributionStatus = "pending" | "approved" | "rejected";

/** GET /api/contributions (own, newest first, 20 per page). Money = whole taka. */
export type Contribution = {
  id: number;
  method: PaymentMethod;
  sender_number: string | null;
  transaction_id: string | null;
  amount: number;
  /** month = "YYYY-MM" */
  months: { month: string; amount: number }[];
  status: ContributionStatus;
  rejection_reason: string | null;
  /** login-only route (owner / admin) */
  proof_url: string | null;
  reviewed_at: string | null;
  created_at: string | null;
};

/** GET /api/admin/contributions (admin + super_admin) — a payment with its member and reviewer. */
export type AdminContribution = Contribution & {
  member: { id: number; name: string; phone: string | null; photo_url: string | null };
  reviewed_by: { id: number; name: string } | null;
};

/** One month from the approval month to the current month (GET /api/contributions/dues). */
export type DueMonth = {
  month: string;
  amount: number;
  status: "paid" | "pending" | "due";
  contribution_id: number | null;
};

export type Dues = {
  months: DueMonth[];
  total_paid: number;
  total_pending: number;
  total_due: number;
  months_due: number;
};

/** GET /api/dashboard/summary — money values stay null until Phase 7. */
export type DashboardSummary =
  | {
      scope: "all";
      active_members: number;
      /** null for committee (admin / super_admin only) */
      pending_registrations: number | null;
      pending_payments: number | null;
      total_collected: number;
      collected_this_month: number;
      outstanding_dues: number;
      members_with_dues: number;
      /** Aid payouts (Phase 8a) */
      total_disbursed: number | null;
      total_expenses: number;
      fund_balance: number;
      /** "YYYY-MM" */
      start_month: string;
    }
  | { scope: "own"; total_paid: number; total_pending: number; total_due: number; months_due: number };

/** GET /api/admin/fund/monthly (committee, admin, super_admin). */
export type FundMonth = {
  month: string;
  amount: number;
  members: number;
  paid_members: number;
  paid_amount: number;
  pending_members: number;
  pending_amount: number;
  due_members: number;
  due_amount: number;
};
export type FundMonthly = {
  start_month: string;
  members: number;
  months: FundMonth[];
  totals: { paid_amount: number; pending_amount: number; due_amount: number };
};

/** GET /api/admin/members/{id}/payments — month by month, newest first. */
export type MemberPaymentHistory = {
  member: { id: number; name: string; status: Status };
  owes_dues: boolean;
  start_month: string;
  months: {
    month: string;
    amount: number;
    status: DueMonth["status"];
    payment: {
      id: number;
      method: PaymentMethod;
      transaction_id: string | null;
      submitted_at: string | null;
      approved_at: string | null;
      /** Screenshot link — admin / super_admin only (null for committee). */
      proof_url: string | null;
    } | null;
    /** Rejected attempts for a due month, newest first (Phase 7f). */
    rejected: {
      id: number;
      method: PaymentMethod;
      transaction_id: string | null;
      submitted_at: string | null;
      rejected_at: string | null;
      /** Admin / super_admin only. */
      reason: string | null;
      proof_url: string | null;
    }[];
  }[];
  total_paid: number;
  total_pending: number;
  total_due: number;
  months_due: number;
};

export type AccountType = "personal" | "agent" | "merchant";
export type MobileMethod = "bkash" | "nagad" | "rocket";
export type BankAccount = {
  bank_name: string | null;
  account_name: string | null;
  account_number: string | null;
  branch: string | null;
  routing_number: string | null;
};

/** The fund's receiving accounts + instructions as the Super Admin edits them (all parts, shown or hidden). */
export type PaymentAccountsSettings = Record<MobileMethod, { number: string | null; type: AccountType; visible: boolean }> & {
  bank: BankAccount & { visible: boolean };
  instructions: { bn: string | null; en: string | null; visible: boolean };
};

/** GET /api/contributions/accounts — only the parts the Super Admin chose to show (Phase 7e). */
export type ReceivingAccounts = {
  mobile: { method: MobileMethod; number: string; type: AccountType }[];
  bank: BankAccount | null;
  instructions: { bn: string; en: string | null } | null;
};

/** GET /api/admin/settings (super_admin only, Phase 7e). Months are "YYYY-MM". */
export type FundSettings = {
  current_month: string;
  next_month: string;
  start_month: string;
  start_month_min: string;
  start_month_max: string;
  earliest_paid_month: string | null;
  earliest_pending_month: string | null;
  current_amount: number;
  amount_max: number;
  /** newest first */
  rates: {
    id: number;
    amount: number;
    effective_from: string;
    status: "scheduled" | "started";
    created_by: { id: number; name: string } | null;
    updated_at: string | null;
  }[];
  accounts: PaymentAccountsSettings;
};

// ---- Assistance requests (Phase 8a API) ----

export type AidReason = "medical" | "accident" | "death" | "job_loss" | "other";
/** submitted → approved → paid; submitted → rejected / cancelled. "Open" = submitted or approved. */
export type AidStatus = "submitted" | "approved" | "paid" | "rejected" | "cancelled";
export type AidVotes = { yes: number; no: number };

/** GET /api/assistance (own, newest first, 20 per page) and GET /api/assistance/{id}. Money = whole taka. */
export type AidRequest = {
  id: number;
  /** e.g. "JPPHAF-AR-2026-0001" */
  case_id: string;
  status: AidStatus;
  reason_type: AidReason;
  reason_other: string | null;
  description: string;
  amount_requested: number;
  applying_for: { name: string; relation: string } | null;
  consent_public_name: boolean;
  votes: AidVotes;
  /** login-only file routes (applicant / admin) */
  documents: { id: number; name: string; mime: string; size: number; url: string; created_at: string | null }[];
  amount_approved: number | null;
  decision_note: string | null;
  rejection_reason: string | null;
  decided_at: string | null;
  cancelled_at: string | null;
  payout: {
    method: PaymentMethod;
    transaction_id: string | null;
    /** "YYYY-MM-DD" */
    date: string | null;
    note: string | null;
    proof_url: string | null;
    paid_at: string;
  } | null;
  created_at: string | null;
};

/** A person on an admin aid answer (the applicant also has a phone number). */
export type AidPerson = { id: number; name: string; phone?: string | null; photo_url: string | null };

/** GET /api/admin/assistance (+ /{id}, which adds `voters`) — admin / super_admin (Phase 8c page). */
export type AdminAidRequest = AidRequest & {
  applicant: AidPerson;
  decided_by: AidPerson | null;
  paid_by: AidPerson | null;
  voters?: { user: AidPerson | null; vote: "yes" | "no"; voted_at: string | null }[];
};

/** GET /api/assistance/open — others see no phone number and no files. */
export type OpenAidRequest = {
  id: number;
  case_id: string;
  status: AidStatus;
  reason_type: AidReason;
  reason_other: string | null;
  description: string;
  amount_requested: number;
  applying_for: { name: string; relation: string } | null;
  applicant: { id: number; name: string; photo_url: string | null };
  votes: AidVotes;
  my_vote: "yes" | "no" | null;
  can_vote: boolean;
  created_at: string | null;
};

// ---- Chat (Phase 9a API, screens 9b) ----

/** GET /api/chat/conversations (20 per page) and GET /api/chat/conversations/{id}. */
export type ChatConversation = {
  id: number;
  /** name null = the account no longer exists → show "Former member" (9d-2) */
  partner: { id: number; name: string | null; photo_url: string | null };
  /** false when the partner is suspended, pending, removed, a demo profile or gone (9d-2) — "Unavailable" label */
  partner_available: boolean;
  /** false when the partner is unavailable or a block exists — history stays readable */
  can_send: boolean;
  /** I blocked the partner (Phase 9d-1) */
  blocked_by_me: boolean;
  /** the partner blocked me — the screen only shows a neutral "can't reply" notice */
  blocked_me: boolean;
  last_message: { id: number; body: string; mine: boolean; created_at: string | null } | null;
  unread_count: number;
  partner_last_read_id: number | null;
  last_message_at: string | null;
};

export type ChatConversationPage = Paginated<ChatConversation> & {
  meta: { unread_total: number };
};

/** GET /api/chat/conversations/{id}/messages (30 per page, oldest first). */
/** One message of the private Committee Chat (GET /api/committee-chat/messages). */
export type CommitteeChatMessage = {
  id: number;
  body: string | null;
  /** null in live events (the receiver works it out from sender.id) */
  mine: boolean | null;
  sender: { id: number; name: string | null; photo_url: string | null };
  attachment: { name: string; mime: string; size: number; is_image: boolean } | null;
  created_at: string | null;
};

export type ChatMessageItem = {
  id: number;
  body: string;
  mine: boolean;
  /** my messages only: has the other person read it? (null for theirs) */
  read: boolean | null;
  /** the partner's messages only: have I reported it? (null for mine; 9d-1) */
  reported?: boolean | null;
  created_at: string | null;
};

// ---- Chat block + report (Phase 9d-1) ----

/** GET /api/chat/blocks */
export type ChatBlockedMember = {
  user: { id: number; name: string; photo_url: string | null };
  blocked_at: string | null;
};

export type ChatReportReason = "harassment" | "spam" | "fraud_or_money_request" | "other";
export type ChatReportStatus = "open" | "reviewed" | "dismissed";

/** GET /api/admin/chat-reports (admin + super_admin) — the one reported message only. */
export type AdminChatReport = {
  id: number;
  reason: ChatReportReason;
  note: string | null;
  message_snapshot: string;
  status: ChatReportStatus;
  reporter: ChatReportPerson;
  reported_user: ChatReportPerson;
  reviewed_by: { id: number; name: string } | null;
  reviewed_at: string | null;
  created_at: string | null;
};

export type ChatReportPerson = { id: number; name: string; phone: string; status: Status; photo_url: string | null };

// ---- Contact messages + audit log (Phase 11a admin API) ----

/** GET /api/admin/contact-messages (admin + super_admin). */
export type AdminContactMessage = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  subject: string;
  message: string;
  is_read: boolean;
  created_at: string | null;
};

/** GET /api/admin/audit-log (admin + super_admin, read-only). */
export type AuditLogEntry = {
  id: number;
  action: string;
  actor: { id: number; name: string; phone: string; role: Role } | null;
  target_type: string | null;
  target_id: number | null;
  target_name: string | null;
  details: Record<string, unknown> | unknown[] | null;
  ip_address: string | null;
  created_at: string | null;
};

// ---- Notifications (Phase 10a API) ----

export type NotificationType =
  | "member_approved"
  | "payment_approved"
  | "payment_rejected"
  | "aid_approved"
  | "aid_rejected"
  | "aid_paid"
  | "aid_vote_open"
  | "notice_published"
  | "payment_due_reminder";

/** GET /api/notifications — own notifications; title / body already in the request's language. */
export type AppNotification = {
  id: number;
  type: NotificationType;
  title: string;
  body: string;
  /** Page in this website, e.g. "/dashboard/payments" */
  link: string;
  read: boolean;
  created_at: string | null;
};

/** GET /api/registration-terms (public) and GET/PUT /api/admin/registration-terms. */
export type RegistrationTerms = {
  enabled: boolean;
  /** public only: switched on AND a text exists */
  active?: boolean;
  heading_bn: string;
  heading_en: string;
  body_bn: string;
  body_en: string;
  checkbox_bn: string;
  checkbox_en: string;
  version: number;
  updated_at: string | null;
};

/** GET/PUT /api/admin/payment-reminder. */
export type PaymentReminderAdmin = {
  enabled: boolean;
  message_bn: string;
  message_en: string;
  updated_at: string | null;
  recipient_count: number;
  total_due: number;
  recipients: {
    id: number;
    name: string;
    phone: string | null;
    total_due: number;
    months_due: number;
    months_pending: number;
  }[];
};

/** GET /api/payment-reminder/status — drives the dashboard popup. */
export type PaymentReminderStatus = {
  show: boolean;
  message: string | null;
  total_due: number;
  months_due: number;
  pay_url: string;
};
