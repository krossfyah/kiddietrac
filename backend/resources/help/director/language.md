---
title: Language preferences (FR / ES)
category: Settings
order: 95
---
# Language

KiddieTrac is available in **English**, **French**, and **Spanish**. The interface, navigation, and key labels translate. Help articles + agency-authored content stay in the language they were written in.

## Switching

1. Sidebar → **Language**.
2. Click the language you want.
3. The page reloads with the new locale applied.

Your preference is saved on your user record — you don't have to switch each visit.

## Agency default

Agency admins can set a **Default locale** in **Administration → Billing settings**. New users at that agency start in that language. They can override per-user.

## Coverage

Common UI: 100%. Navigation labels: 100%. Forms and screens: ~80% (in-progress). Email templates: English only for now.

## Agency-specific overrides

If you want to override a specific string for your agency (e.g. call "Educators" "Teachers"), drop a row into `agency_locale_overrides` (`key_path`, `value`) and the LocaleController will merge it on top of the default bundle.
