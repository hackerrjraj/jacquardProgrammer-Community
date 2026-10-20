"use client";

import { useEffect, useState } from "react";
import { useDictionary } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { failureText } from "@/components/profile/profile-shared";
import { apiFetch } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { useLocale } from "@/i18n/client";
import { revalidateSiteContent } from "@/lib/revalidate-site-content";

type Setting = { overlay: number; default: number; max: number };

/** Site Content → Images: "Overlay darkness" (0–80%, default 40%) for the Home hero slider. */
export function HeroOverlaySetting() {
  const t = useDictionary();
  const locale = useLocale();
  const im = t.siteContent.images;
  const [setting, setSetting] = useState<Setting | null>(null);
  const [value, setValue] = useState(40);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState<{ tone: "success" | "danger"; text: string } | null>(null);

  useEffect(() => {
    void apiFetch<{ data: Setting }>("/admin/hero-overlay").then((res) => {
      if (res.ok) {
        setSetting(res.data.data);
        setValue(res.data.data.overlay);
      } else {
        setFailed(true);
      }
    });
  }, []);

  async function save() {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: { overlay: number } }>("/admin/hero-overlay", {
      method: "PUT",
      body: { overlay: value },
    });
    if (res.ok) {
      void revalidateSiteContent();
      setSetting((prev) => (prev ? { ...prev, overlay: res.data.data.overlay } : prev));
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setFlash({ tone: "danger", text: failureText(res, t) });
    }
    setBusy(false);
  }

  const digits = (n: number) => localizeDigits(String(n), locale);

  return (
    <Card className="space-y-3 p-4 sm:p-5">
      <h2 className="text-lg font-semibold">{im.overlayTitle}</h2>
      <p className="max-w-3xl text-sm text-muted">{im.overlayHelp}</p>
      {failed && <p className="text-sm text-danger">{im.overlayLoadFailed}</p>}
      {setting && (
        <>
          <label htmlFor="hero-overlay" className="block text-sm font-medium">
            {fill(im.overlayLabel, { value: digits(value) })}
          </label>
          <input
            id="hero-overlay"
            type="range"
            min={0}
            max={setting.max}
            step={1}
            value={value}
            onChange={(e) => setValue(Number(e.target.value))}
            className="w-full max-w-md accent-primary"
          />
          <p className="text-xs text-muted">{fill(im.overlayDefault, { value: digits(setting.default) })}</p>
          {flash && <Alert tone={flash.tone}>{flash.text}</Alert>}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" disabled={busy || value === setting.overlay} onClick={() => void save()}>
              {im.overlaySave}
            </Button>
            <Button size="sm" variant="outline" disabled={busy || value === setting.default} onClick={() => setValue(setting.default)}>
              {im.overlayReset}
            </Button>
          </div>
        </>
      )}
    </Card>
  );
}
