"use client";

import { useCallback, useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { apiFetch, type Expense, type ExpenseCategory, type ExpenseSummary, type Paginated, type User } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { formatNumber, formatTaka, optionName } from "@/lib/format";

type Flash = { tone: "success" | "danger"; text: string } | null;
type Page = Paginated<Expense> & { meta: { filtered_total: number }; summary: ExpenseSummary };

const today = () => new Date().toLocaleDateString("en-CA"); // YYYY-MM-DD

/** /dashboard/expenses — Expenses (খরচের হিসাব). Admin + super admin; only the super admin can delete. */
export function ExpensesScreen() {
  return <RequireAuth roles={navItem("expenses").roles}>{(user) => <ExpensesView user={user} />}</RequireAuth>;
}

/** Opens a private receipt in a new tab (the API needs the session cookie, so it cannot be a plain link). */
async function openReceipt(url: string) {
  try {
    const res = await fetch(url, { credentials: "include", cache: "no-store" });
    if (!res.ok) return;
    window.open(URL.createObjectURL(await res.blob()), "_blank", "noopener");
  } catch {
    /* network error — nothing to open */
  }
}

function ExpensesView({ user }: { user: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const x = t.expenses;
  const taka = (v: number) => formatTaka(v, locale);
  const isSuper = user.role === "super_admin";

  const [categories, setCategories] = useState<ExpenseCategory[]>([]);
  const [filters, setFilters] = useState({ from: "", to: "", category_id: "" });
  const [pageNo, setPageNo] = useState(1);
  const [page, setPage] = useState<Page | null>(null);
  const [failed, setFailed] = useState(false);
  const [flash, setFlash] = useState<Flash>(null);
  const [form, setForm] = useState<{ expense: Expense | null } | null>(null);
  const [deleting, setDeleting] = useState<Expense | null>(null);
  const [managing, setManaging] = useState(false);
  const [reload, setReload] = useState(0);

  const loadCategories = useCallback(async () => {
    const res = await apiFetch<{ data: ExpenseCategory[] }>("/admin/expense-categories");
    if (res.ok) setCategories(res.data.data);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadCategories();
  }, [loadCategories]);

  useEffect(() => {
    let cancelled = false;
    const q = new URLSearchParams({ page: String(pageNo) });
    if (filters.from) q.set("from", filters.from);
    if (filters.to) q.set("to", filters.to);
    if (filters.category_id) q.set("category_id", filters.category_id);
    void apiFetch<Page>(`/admin/expenses?${q}`).then((res) => {
      if (cancelled) return;
      if (res.ok) setPage(res.data);
      setFailed(!res.ok);
    });
    return () => {
      cancelled = true;
    };
  }, [filters, pageNo, reload]);

  const changed = (text: string) => {
    setFlash({ tone: "success", text });
    setReload((n) => n + 1);
  };
  const setFilter = (key: keyof typeof filters, value: string) => {
    setPageNo(1);
    setFilters((f) => ({ ...f, [key]: value }));
  };
  const hasFilter = Boolean(filters.from || filters.to || filters.category_id);
  const s = page?.summary;

  const cards: [IconName, string, string, string?][] = [
    ["banknote", x.totalCollected, s ? taka(s.total_collected) : "…"],
    ["wallet", x.totalExpenses, s ? taka(s.total_expenses) : "…"],
    ["scale", x.balance, s ? taka(s.balance) : "…", "bg-secondary"],
  ];

  return (
    <div className="space-y-6">
      <p className="max-w-3xl text-muted">{x.subtitle}</p>

      <ul className="grid gap-4 sm:grid-cols-3">
        {cards.map(([icon, label, value, tone]) => (
          <li key={label}>
            <Card className="flex h-full flex-col">
              <div className="flex items-center gap-3">
                <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl text-white ${tone ?? "bg-icon-box"}`}>
                  <Icon name={icon} className="size-5" />
                </span>
                <p className="text-sm font-medium text-muted">{label}</p>
              </div>
              <p className="mt-4 font-heading text-3xl font-bold break-words text-heading">{value}</p>
            </Card>
          </li>
        ))}
      </ul>
      <p className="-mt-3 text-sm text-muted">{x.balanceNote}</p>

      {flash && (
        <Alert tone={flash.tone}>{flash.text}</Alert>
      )}

      <Card className="sm:p-6">
        <div className="flex flex-wrap items-end gap-3">
          <Input label={x.from} type="date" value={filters.from} max={filters.to || undefined} onChange={(e) => setFilter("from", e.target.value)} />
          <Input label={x.to} type="date" value={filters.to} min={filters.from || undefined} onChange={(e) => setFilter("to", e.target.value)} />
          <Select label={x.category} value={filters.category_id} onChange={(e) => setFilter("category_id", e.target.value)}>
            <option value="">{x.allCategories}</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {optionName(c, locale)}
              </option>
            ))}
          </Select>
          {hasFilter && (
            <Button variant="ghost" size="sm" onClick={() => { setPageNo(1); setFilters({ from: "", to: "", category_id: "" }); }}>
              {x.clear}
            </Button>
          )}
          <div className="ml-auto flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setManaging(true)}>
              {x.catManage}
            </Button>
            <Button onClick={() => setForm({ expense: null })}>
              <Icon name="plus" className="size-4" />
              {x.add}
            </Button>
          </div>
        </div>

        <h2 className="mt-6 text-xl font-bold text-heading">{x.listTitle}</h2>
        {failed ? (
          <div className="mt-4 text-center">
            <p className="text-danger">{x.loadFailed}</p>
            <Button variant="outline" size="sm" className="mt-3" onClick={() => setReload((n) => n + 1)}>
              {x.retry}
            </Button>
          </div>
        ) : !page ? (
          <div className="mt-4 h-24 animate-pulse rounded-control bg-border/60" aria-busy="true" />
        ) : page.data.length === 0 ? (
          <p className="mt-4 rounded-control bg-background p-4 text-center text-muted">{x.empty}</p>
        ) : (
          <>
            <p className="mt-1 text-sm text-muted">{fill(x.count, { n: formatNumber(page.meta.total, locale), amount: taka(page.meta.filtered_total) })}</p>
            <div className="mt-3 hidden overflow-x-auto md:block">
              <table className="w-full text-left text-sm">
                <thead className="bg-background text-xs font-semibold text-muted">
                  <tr>
                    <th scope="col" className="px-3 py-2.5">{x.date}</th>
                    <th scope="col" className="px-3 py-2.5">{x.category}</th>
                    <th scope="col" className="px-3 py-2.5">{x.purpose}</th>
                    <th scope="col" className="px-3 py-2.5">{x.paidToCol}</th>
                    <th scope="col" className="px-3 py-2.5 text-right">{x.amount}</th>
                    <th scope="col" className="px-3 py-2.5" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {page.data.map((e) => (
                    <tr key={e.id}>
                      <td className="px-3 py-2.5 whitespace-nowrap">{formatDate(e.expense_date, locale)}</td>
                      <td className="px-3 py-2.5">{e.category ? optionName(e.category, locale) : "—"}</td>
                      <td className="max-w-xs px-3 py-2.5 break-words">{e.purpose}</td>
                      <td className="px-3 py-2.5">{e.paid_to ?? "—"}</td>
                      <td className="px-3 py-2.5 text-right font-semibold whitespace-nowrap">{taka(e.amount)}</td>
                      <td className="px-3 py-2.5 text-right whitespace-nowrap">
                        <RowActions e={e} isSuper={isSuper} onEdit={() => setForm({ expense: e })} onDelete={() => setDeleting(e)} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <ul className="mt-3 space-y-3 md:hidden">
              {page.data.map((e) => (
                <li key={e.id} className="rounded-control border border-border p-3">
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <p className="font-semibold text-heading">{e.category ? optionName(e.category, locale) : "—"}</p>
                    <p className="font-semibold">{taka(e.amount)}</p>
                  </div>
                  <p className="mt-1 text-sm break-words">{e.purpose}</p>
                  <p className="mt-1 text-sm text-muted">
                    {formatDate(e.expense_date, locale)}
                    {e.paid_to ? ` · ${e.paid_to}` : ""}
                  </p>
                  <div className="mt-2">
                    <RowActions e={e} isSuper={isSuper} onEdit={() => setForm({ expense: e })} onDelete={() => setDeleting(e)} />
                  </div>
                </li>
              ))}
            </ul>
            {page.meta.last_page > 1 && (
              <nav className="mt-5 flex flex-wrap items-center justify-center gap-3">
                <Button variant="outline" size="sm" disabled={pageNo <= 1} onClick={() => setPageNo(pageNo - 1)}>
                  <Icon name="chevronLeft" className="size-4" />
                  {x.prev}
                </Button>
                <span className="text-sm text-muted">
                  {fill(x.page, { page: formatNumber(page.meta.current_page, locale), last: formatNumber(page.meta.last_page, locale) })}
                </span>
                <Button variant="outline" size="sm" disabled={pageNo >= page.meta.last_page} onClick={() => setPageNo(pageNo + 1)}>
                  {x.next}
                  <Icon name="chevronRight" className="size-4" />
                </Button>
              </nav>
            )}
          </>
        )}
      </Card>

      {form && (
        <ExpenseForm
          expense={form.expense}
          categories={categories}
          onClose={() => setForm(null)}
          onSaved={(text) => {
            setForm(null);
            changed(text);
          }}
        />
      )}
      {deleting && (
        <DeleteDialog
          expense={deleting}
          onClose={() => setDeleting(null)}
          onDeleted={(text) => {
            setDeleting(null);
            changed(text);
          }}
        />
      )}
      {managing && (
        <CategoriesDialog
          categories={categories}
          isSuper={isSuper}
          onClose={() => setManaging(false)}
          onChanged={() => void loadCategories()}
        />
      )}
    </div>
  );
}

function RowActions({ e, isSuper, onEdit, onDelete }: { e: Expense; isSuper: boolean; onEdit: () => void; onDelete: () => void }) {
  const x = useDictionary().expenses;
  return (
    <span className="inline-flex flex-wrap justify-end gap-1">
      {e.receipt_url && (
        <Button variant="ghost" size="sm" onClick={() => void openReceipt(e.receipt_url!)}>
          <Icon name="image" className="size-4" />
          {x.receiptView}
        </Button>
      )}
      <Button variant="ghost" size="sm" onClick={onEdit}>
        <Icon name="pencil" className="size-4" />
        {x.edit}
      </Button>
      {isSuper && (
        <Button variant="ghost" size="sm" onClick={onDelete}>
          <Icon name="trash" className="size-4" />
          {x.delete}
        </Button>
      )}
    </span>
  );
}

function ExpenseForm({
  expense,
  categories,
  onClose,
  onSaved,
}: {
  expense: Expense | null;
  categories: ExpenseCategory[];
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const x = t.expenses;
  const [date, setDate] = useState(expense?.expense_date ?? today());
  const [amount, setAmount] = useState(expense ? String(expense.amount) : "");
  const [categoryId, setCategoryId] = useState(expense?.category ? String(expense.category.id) : "");
  const [purpose, setPurpose] = useState(expense?.purpose ?? "");
  const [paidTo, setPaidTo] = useState(expense?.paid_to ?? "");
  const [receipt, setReceipt] = useState<File | null>(null);
  const [removeReceipt, setRemoveReceipt] = useState(false);
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const crop = useImageCrop();

  // Active categories, plus the one this expense already has even if it is hidden now.
  const options = categories.filter((c) => c.is_active || String(c.id) === categoryId);

  const submit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    const next: Record<string, string> = {};
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date > today()) next.expense_date = x.errDate;
    if (!/^\d{1,9}$/.test(amount.trim()) || Number(amount) < 1) next.amount = x.errAmount;
    if (!categoryId) next.category_id = x.errCategory;
    if (!purpose.trim()) next.purpose = x.errPurpose;
    setErrors(next);
    if (Object.keys(next).length) return;

    const body = new FormData();
    body.append("expense_date", date);
    body.append("amount", amount.trim());
    body.append("category_id", categoryId);
    body.append("purpose", purpose.trim());
    body.append("paid_to", paidTo.trim());
    if (receipt) body.append("receipt", receipt);
    else if (removeReceipt) body.append("remove_receipt", "1");
    setError(null);
    setBusy(true);
    const res = await apiFetch<{ message?: string }>(expense ? `/admin/expenses/${expense.id}` : "/admin/expenses", { method: "POST", body });
    setBusy(false);
    if (res.ok) return onSaved(res.data.message ?? x.saved);
    setErrors(fieldErrors(res));
    setError(failureText(res, t));
  };

  const pickLabel = receipt ? receipt.name : expense?.has_receipt && !removeReceipt ? x.receiptReplace : x.receiptPick;

  return (
    <Modal title={expense ? x.formEdit : x.formAdd} onClose={busy ? () => {} : onClose}>
      <form onSubmit={submit} noValidate className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label={x.date} type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} error={errors.expense_date} />
          <Input
            label={x.amount}
            inputMode="numeric"
            value={amount}
            onChange={(e) => setAmount(e.target.value.replace(/[^0-9]/g, ""))}
            error={errors.amount}
          />
        </div>
        <Select label={x.category} value={categoryId} onChange={(e) => setCategoryId(e.target.value)} error={errors.category_id}>
          <option value="">{x.choose}</option>
          {options.map((c) => (
            <option key={c.id} value={c.id}>
              {optionName(c, locale)}
            </option>
          ))}
        </Select>
        <Textarea label={x.purpose} value={purpose} onChange={(e) => setPurpose(e.target.value)} rows={3} maxLength={2000} error={errors.purpose} />
        <Input label={x.paidTo} value={paidTo} onChange={(e) => setPaidTo(e.target.value)} maxLength={150} error={errors.paid_to} />

        <div>
          <p className="mb-1.5 text-sm font-medium text-foreground">{x.receipt}</p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-full border border-border bg-surface px-4 text-sm font-semibold text-secondary hover:border-primary">
              <Icon name="image" className="size-4" />
              <span className="max-w-48 truncate">{pickLabel}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                onChange={(e) => {
                  crop.pick(e.target.files, "document", (f) => {
                    setReceipt(f);
                    setRemoveReceipt(false);
                  });
                  e.target.value = "";
                }}
              />
            </label>
            {(receipt || (expense?.has_receipt && !removeReceipt)) && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  setReceipt(null);
                  setRemoveReceipt(true);
                }}
              >
                {x.receiptRemove}
              </Button>
            )}
          </div>
          {errors.receipt && <p className="mt-1 text-sm text-danger">{errors.receipt}</p>}
        </div>

        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex flex-wrap justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            {x.cancel}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? x.saving : x.save}
          </Button>
        </div>
      </form>
      {crop.dialog}
    </Modal>
  );
}

function DeleteDialog({ expense, onClose, onDeleted }: { expense: Expense; onClose: () => void; onDeleted: (m: string) => void }) {
  const t = useDictionary();
  const x = t.expenses;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const remove = async () => {
    setBusy(true);
    const res = await apiFetch<{ message?: string }>(`/admin/expenses/${expense.id}`, { method: "DELETE" });
    setBusy(false);
    if (res.ok) return onDeleted(res.data.message ?? x.saved);
    setError(failureText(res, t));
  };

  return (
    <Modal title={x.deleteTitle} onClose={busy ? () => {} : onClose}>
      <p>{x.deleteText}</p>
      <p className="mt-2 text-sm text-muted">{expense.purpose}</p>
      {error && <Alert tone="danger" className="mt-3">{error}</Alert>}
      <div className="mt-4 flex flex-wrap justify-end gap-2">
        <Button variant="outline" onClick={onClose} disabled={busy}>
          {x.cancel}
        </Button>
        <Button variant="danger" onClick={() => void remove()} disabled={busy}>
          {x.deleteYes}
        </Button>
      </div>
    </Modal>
  );
}

function CategoriesDialog({
  categories,
  isSuper,
  onClose,
  onChanged,
}: {
  categories: ExpenseCategory[];
  isSuper: boolean;
  onClose: () => void;
  onChanged: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const x = t.expenses;
  const [bn, setBn] = useState("");
  const [en, setEn] = useState("");
  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async (path: string, method: "POST" | "PATCH" | "DELETE", body?: unknown) => {
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ message?: string }>(path, { method, body });
    setBusy(false);
    if (res.ok) {
      onChanged();
      return true;
    }
    setErrors(fieldErrors(res));
    setError(failureText(res, t));
    return false;
  };

  return (
    <Modal title={x.catTitle} onClose={onClose}>
      <p className="text-sm text-muted">{x.catText}</p>
      <ul className="mt-3 divide-y divide-border">
        {categories.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <div className="min-w-0">
              <p className="font-medium text-heading">
                {optionName(c, locale)} {!c.is_active && <span className="text-xs text-muted">({x.catHidden})</span>}
              </p>
              <p className="text-xs text-muted">{fill(x.catUsed, { n: formatNumber(c.expenses_count, locale) })}</p>
            </div>
            <span className="flex gap-1">
              <Button variant="ghost" size="sm" disabled={busy} onClick={() => void run(`/admin/expense-categories/${c.id}`, "PATCH", { is_active: !c.is_active })}>
                {c.is_active ? x.catHide : x.catShow}
              </Button>
              {isSuper && c.expenses_count === 0 && (
                <Button variant="ghost" size="sm" disabled={busy} onClick={() => void run(`/admin/expense-categories/${c.id}`, "DELETE")}>
                  {x.catDelete}
                </Button>
              )}
            </span>
          </li>
        ))}
      </ul>
      <form
        className="mt-4 space-y-3 border-t border-border pt-4"
        noValidate
        onSubmit={async (ev) => {
          ev.preventDefault();
          if (await run("/admin/expense-categories", "POST", { name_bn: bn, name_en: en })) {
            setBn("");
            setEn("");
            setErrors({});
          }
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <Input label={x.catBn} value={bn} onChange={(e) => setBn(e.target.value)} maxLength={100} error={errors.name_bn} />
          <Input label={x.catEn} value={en} onChange={(e) => setEn(e.target.value)} maxLength={100} error={errors.name_en} />
        </div>
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {x.catClose}
          </Button>
          <Button type="submit" disabled={busy || !bn.trim() || !en.trim()}>
            {x.catAdd}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
