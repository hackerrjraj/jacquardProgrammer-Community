"use client";

import { useEffect, useState } from "react";
import { apiFetch, type District } from "@/lib/api";

/** The 64 districts from GET /api/districts. `failed` → show a retry button. */
export function useDistricts() {
  const [districts, setDistricts] = useState<District[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: District[] }>("/districts").then((res) => {
      if (cancelled) return;
      if (res.ok) setDistricts(res.data.data);
      setFailed(!res.ok);
    });
    return () => {
      cancelled = true;
    };
  }, [attempt]);

  return {
    districts,
    failed,
    retry: () => {
      setFailed(false);
      setAttempt((n) => n + 1);
    },
  };
}
