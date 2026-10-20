import { Icon } from "@/components/ui/icon";
import type { ApiResult, FundSettings } from "@/lib/api";

/** Shared by the three sections: after a save the API sends the whole settings again. */
export type SectionProps = {
  data: FundSettings;
  onSaved: (data: FundSettings) => void;
  onAuthError: (res: ApiResult<unknown>) => void;
};

export function SectionTitle({ icon, title, subtitle }: { icon: "banknote" | "wallet" | "calendar" | "scale" | "checkCircle"; title: string; subtitle?: string }) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-icon-box text-white">
        <Icon name={icon} className="size-5" />
      </span>
      <div className="min-w-0">
        <h2 className="text-lg font-semibold">{title}</h2>
        {subtitle && <p className="mt-0.5 text-sm text-muted">{subtitle}</p>}
      </div>
    </div>
  );
}
