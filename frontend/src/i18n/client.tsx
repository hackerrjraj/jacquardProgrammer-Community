"use client";

import { createContext, useContext } from "react";
import { dictionaryFor, type Dictionary, type Locale } from "./config";

const LocaleContext = createContext<Locale>("bn");

/** Wraps the app (in the root layout) so Client Components know the language. */
export function I18nProvider({
  locale,
  children,
}: {
  locale: Locale;
  children: React.ReactNode;
}) {
  return (
    <LocaleContext.Provider value={locale}>{children}</LocaleContext.Provider>
  );
}

/** Current language (Client Components only). */
export function useLocale(): Locale {
  return useContext(LocaleContext);
}

/** Translations for the current language (Client Components only). */
export function useDictionary(): Dictionary {
  return dictionaryFor(useLocale());
}
