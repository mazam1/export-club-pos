# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **Stocky v5.5** (exportClubPos) — a POS and inventory management system built with Laravel 12 (PHP 8.2+) backend and Vue 2.7 SPA frontend. It runs on Laragon (Windows/localhost). The app includes sales, purchases, quotations, transfers, adjustments, expenses, HRM, warehouse management, project/task management, Stripe payments, SMS (Twilio/Infobip), reporting, online store/eCommerce, WooCommerce integration, QuickBooks integration, double-entry accounting, asset management, damage tracking, service/checklist management, bookings with Google Calendar, cash registers, custom fields, and cloud backup.

## Common Commands

```bash
# Frontend development (Laravel Mix / Webpack)
npm run dev          # One-time build
npm run watch        # Watch for changes with auto-rebuild
npm run prod         # Production build (minified)

# Backend
php artisan serve    # Run dev server (or use Laragon vhosts)
php artisan migrate  # Run migrations
php artisan tinker   # Interactive REPL

# Tests (PHPUnit, uses SQLite in-memory)
php artisan test                          # Run all tests
./vendor/bin/phpunit                      # Alternative
./vendor/bin/phpunit tests/Unit           # Unit tests only
./vendor/bin/phpunit tests/Feature        # Feature tests only
./vendor/bin/phpunit --filter=TestName    # Single test

# Cache management
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan optimize          # Cache config, events, routes, views
```

## Architecture

### Backend (Laravel 12)

- **Authentication**: Laravel Passport 12 (OAuth2 API tokens). The `Auth` class on the frontend sets Bearer tokens via axios defaults. Middleware `Is_Active`, `IsActiveToken`, `request.safety`, and `token.timeout` gate access.
- **API routes** (`routes/api.php`): All business logic endpoints under `auth:api` + `Is_Active` + `request.safety` + `token.timeout` middleware. Controllers live in `app/Http/Controllers/` — no sub-namespacing except `Auth/`, `hrm/`, `AccountingV2/`, `Store/`, `WooCommerce/`, and `QuickBooks/`.
- **Modular architecture**: Uses `nwidart/laravel-modules` with `Modules/` directory and `modules_statuses.json`. Module routes/controllers are self-contained.
- **Policies**: Authorization via `app/Policies/` — one policy per domain entity (Brand, Category, Warehouse, Employee, etc.).
- **Helpers**: `app/utils/helpers.php` — shared utility class for filtering (`filter()`), permission-based record scoping (`Show_Records()`), and currency formatting. Also `app/Support/store_settings.php` for online store helpers.
- **BaseController**: `app/Http/Controllers/BaseController.php` — standardized `sendResponse()`/`sendError()` JSON response format used across API controllers.
- **Key integrations**: Stripe (`StripeController`), Twilio/Infobip SMS, DomPDF v3 for PDF generation, Maatwebsite/Excel for imports/exports, AWS SDK, Google API Client (Calendar), WooCommerce sync, QuickBooks invoicing.
- **Queue**: Default connection is `database` (requires `jobs` and `failed_jobs` tables, created by migrations).
- **Accounting V2**: Full double-entry accounting module with Chart of Accounts, Journal Entries, Trial Balance, Profit & Loss, Balance Sheet, Tax Reports. Config in `config/accounting_v2.php`.

### Frontend (Vue 2.7 SPA)

- **Entry points**: `resources/src/main.js` (authenticated app), `resources/src/login.js` (login page), and `resources/src/customer-display.js` (customer-facing display). All compiled by Laravel Mix (`webpack.mix.js`) to `public/js/`.
- **Build**: Laravel Mix 6 with Webpack. Output goes to `public/js/[name].min.js` with code-split chunks in `public/js/bundle/`. Moment.js locale stripping and `CleanWebpackPlugin` are configured.
- **Routing**: `resources/src/router.js` — Vue Router with lazy-loaded route components. All authenticated routes nest under `/app/`. The catch-all in `routes/web.php` serves the SPA blade template for non-API paths.
- **State management**: Vuex store at `resources/src/store/` with modules: `auth` (user/permissions/language), `language`, `largeSidebar`, `compactSidebar`, `config`.
- **Auth flow**: `resources/src/auth/index.js` manages token storage; Vuex `auth` module tracks `isAuthenticated`, `Permissions`, `user`, and `availableLanguages`. Permissions are string-based (e.g., `'Sales_view'`, `'pos_settings'`).
- **UI framework**: Bootstrap 4 + BootstrapVue, custom SCSS themes in `resources/src/assets/styles/sass/`, VeeValidate 3 for form validation, ApexCharts + ECharts 5 for charting, Quill 2 for rich text editing, SheetJS (xlsx) for Excel exports.
- **Views structure**: `resources/src/views/app/pages/` — each domain (sales, purchases, products, reports, hrm, settings, store, accounting, service, bookings, assets, damages, etc.) has its own directory with CRUD Vue components.

### Online Store

- Public-facing storefront at `/online_store/` with its own `store` auth guard.
- Routes in `routes/web.php` under `online_store` prefix with `store.enabled` middleware.
- Controllers in `app/Http/Controllers/Store/` (StoreFrontController, CheckoutController, etc.).
- Admin settings at `/app/store/` in the SPA.

### Database

- Migrations in `database/migrations/` — includes OAuth tables (Passport), all domain tables, and financial precision conversions (float→decimal).
- All financial columns use `decimal(15,2)` for precision.
- Tests use SQLite in-memory (`phpunit.xml`).

### Web vs API routing

- `routes/web.php`: Login/logout, setup wizard (if not installed), SPA catch-all serving `layouts.master` blade, online store routes, QuickBooks/Google Calendar OAuth callbacks, customer display.
- `routes/api.php`: All REST API endpoints. Public: password reset, access token, translations, languages, customer display broadcast. Protected: everything else under `auth:api`.
- Installation state is checked via `Storage::disk('public')->exists('installed')`.
