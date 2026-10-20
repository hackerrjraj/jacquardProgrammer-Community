import { NoticeNotFound } from "@/components/notices/notice-detail";

/** 404 for /notices/{id} — a logged-in member's browser still tries the member API (members-only notices). */
export default function NotFound() {
  return <NoticeNotFound />;
}
