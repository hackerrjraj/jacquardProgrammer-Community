"use client";

import { useEffect, useState } from "react";
import { useDictionary } from "@/i18n/client";
import { Card } from "@/components/ui/card";

type Health = { status: string; database: string };

type State =
  | { kind: "loading" }
  | { kind: "ok"; health: Health }
  | { kind: "unreachable" };

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export default function ServerStatus() {
  const t = useDictionary();
  const [state, setState] = useState<State>({ kind: "loading" });

  useEffect(() => {
    fetch(`${API_URL}/api/health`, { headers: { Accept: "application/json" } })
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json() as Promise<Health>;
      })
      .then((health) => setState({ kind: "ok", health }))
      .catch(() => setState({ kind: "unreachable" }));
  }, []);

  return (
    <Card className="w-full max-w-sm">
      <h2 className="mb-2 text-lg font-semibold">{t.status.title}</h2>
      {state.kind === "loading" && <p className="text-muted">{t.status.checking}</p>}
      {state.kind === "unreachable" && (
        <p className="text-danger">❌ {t.status.unreachable}</p>
      )}
      {state.kind === "ok" && (
        <ul className="space-y-1">
          <li className="text-success">✅ {t.status.serverOk}</li>
          {state.health.database === "connected" ? (
            <li className="text-success">✅ {t.status.dbOk}</li>
          ) : (
            <li className="text-danger">❌ {t.status.dbError}</li>
          )}
        </ul>
      )}
    </Card>
  );
}
