# Investor-Grade P&L Report Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all calculation bugs and redesign the Profit & Loss report into a standard, investor-grade financial statement with correct formulas, clear waterfall layout, and verifiable numbers.

**Architecture:** Three files change: (1) `CalculatesCogsAndAverageCost.php` trait — subtract sale-return and purchase-return quantities from COGS; (2) `ReportController.php` — fix profit formula, add status filters to payment queries, expose new fields (COGS, gross profit, margins); (3) `profit_and_loss.vue` — redesign from flat tiles to a structured Income Statement waterfall with a separate Cash Flow section. Translation seeder gets new keys.

**Tech Stack:** Laravel 12 / PHP 8.2, Vue 2.7 SPA, Eloquent ORM, existing `CalculatesCogsAndAverageCost` trait

---

## Files Overview

| File | Action |
|------|--------|
| `app/Traits/CalculatesCogsAndAverageCost.php` | Modify: subtract sale return & purchase return quantities |
| `app/Http/Controllers/ReportController.php` (lines 1800-1944) | Modify: fix profit formula, add status filters, new response fields |
| `resources/src/views/app/pages/reports/profit_and_loss.vue` | Rewrite: investor-grade waterfall layout |
| `database/seeders/translations/en.php` | Modify: add new translation keys |

---

### Task 1: Fix COGS — Subtract Sale Return Quantities

The COGS trait calculates cost on ALL sold quantities but never subtracts quantities that were returned by customers. This overstates COGS.

**Files:**
- Modify: `app/Traits/CalculatesCogsAndAverageCost.php:57-71` (salesQty query) and `:73-87` (salesBefore query)

**Step 1: Add sale-return quantity queries to `calcCogsAndAvgCostFast()`**

After the existing `$salesQty` query (line 71), add a query for return quantities in the same period:

```php
// Sale-return qty in period per key (to subtract from sold qty)
$returnQty = \App\Models\SaleReturnDetails::join('sale_returns as sr', 'sr.id', '=', 'sale_return_details.sale_return_id')
    ->where('sr.statut', 'received')
    ->when($warehouseId, fn ($q) => $q->where('sr.warehouse_id', $warehouseId),
        fn ($q) => $q->whereIn('sr.warehouse_id', $warehouseIds))
    ->whereBetween('sr.date', [$start, $end])
    ->whereIn('sale_return_details.product_id', $productIds)
    ->select('sale_return_details.product_id', 'sale_return_details.product_variant_id', DB::raw('SUM(sale_return_details.quantity) as qty'))
    ->groupBy('sale_return_details.product_id', 'sale_return_details.product_variant_id')
    ->get()
    ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
```

After the existing `$salesBefore` query (line 87), add returns before period:

```php
// Sale-return qty before start (to un-burn FIFO layers)
$returnsBefore = \App\Models\SaleReturnDetails::join('sale_returns as sr', 'sr.id', '=', 'sale_return_details.sale_return_id')
    ->where('sr.statut', 'received')
    ->when($warehouseId, fn ($q) => $q->where('sr.warehouse_id', $warehouseId),
        fn ($q) => $q->whereIn('sr.warehouse_id', $warehouseIds))
    ->where('sr.date', '<', $start)
    ->whereIn('sale_return_details.product_id', $productIds)
    ->select('sale_return_details.product_id', 'sale_return_details.product_variant_id', DB::raw('SUM(sale_return_details.quantity) as qty'))
    ->groupBy('sale_return_details.product_id', 'sale_return_details.product_variant_id')
    ->get()
    ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
```

**Step 2: Adjust the net quantities in the per-key loop**

In the loop at line 113-185, change:

```php
// BEFORE (line 115):
$qtySold = (float) ($salesQty[$kstr]->qty ?? 0);

// AFTER:
$grossSold = (float) ($salesQty[$kstr]->qty ?? 0);
$returned   = (float) ($returnQty[$kstr]->qty ?? 0);
$qtySold    = max(0, $grossSold - $returned);
```

And for the FIFO burn (line 143):

```php
// BEFORE:
$burn = (float) ($salesBefore[$kstr]->qty ?? 0);

// AFTER:
$burnGross = (float) ($salesBefore[$kstr]->qty ?? 0);
$burnReturns = (float) ($returnsBefore[$kstr]->qty ?? 0);
$burn = max(0, $burnGross - $burnReturns);
```

**Step 3: Verify the file compiles**

Run: `php -l app/Traits/CalculatesCogsAndAverageCost.php`
Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
git add app/Traits/CalculatesCogsAndAverageCost.php
git commit -m "fix: subtract sale return quantities from COGS calculation

COGS was calculated on gross sold quantities without backing out items
returned by customers, overstating cost of goods sold."
```

---

### Task 2: Fix COGS — Subtract Purchase Return Quantities from FIFO Layers

Purchase returns reduce the available inventory from a purchase batch. The FIFO layers currently ignore this.

**Files:**
- Modify: `app/Traits/CalculatesCogsAndAverageCost.php:89-104` (purchases query area)

**Step 1: Add purchase-return quantity query**

After the `$purchases` query (line 104), add:

```php
// Purchase-return qty per key (to reduce FIFO layers)
$purchReturns = \App\Models\PurchaseReturnDetails::join('purchase_returns as pr', 'pr.id', '=', 'purchase_return_details.purchase_return_id')
    ->where('pr.statut', 'completed')
    ->when($warehouseId, fn ($q) => $q->where('pr.warehouse_id', $warehouseId),
        fn ($q) => $q->whereIn('pr.warehouse_id', $warehouseIds))
    ->where('pr.date', '<=', $end)
    ->whereIn('purchase_return_details.product_id', $productIds)
    ->select('purchase_return_details.product_id', 'purchase_return_details.product_variant_id', DB::raw('SUM(purchase_return_details.quantity) as qty'))
    ->groupBy('purchase_return_details.product_id', 'purchase_return_details.product_variant_id')
    ->get()
    ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
```

**Step 2: Burn purchase-return qty from FIFO layers before any sales burn**

In the per-key loop, right after `$layers = ($purchases[$kstr] ?? collect())->values();` (line 134), before the "burn layers for sales before start" block, add:

```php
// Burn purchase-return qty from layers (oldest first, matching FIFO)
$prBurn = (float) ($purchReturns[$kstr]->qty ?? 0);
$pri = 0;
while ($prBurn > 0 && $pri < $layers->count()) {
    $pq = (float) $layers[$pri]->quantity;
    if ($pq <= 0) { $pri++; continue; }
    $prConsume = min($pq, $prBurn);
    $layers[$pri]->quantity = $pq - $prConsume;
    $prBurn -= $prConsume;
    if ($layers[$pri]->quantity <= 0) { $pri++; }
}
```

**Step 3: Also subtract purchase returns from averageCostBulk()**

In `averageCostBulk()`, the purchase returns should reduce both qty and cost. After the adjustments query block (around line 230), add:

```php
// Purchase returns up to end (reduce purchase cost base)
$pRet = \App\Models\PurchaseReturnDetails::join('purchase_returns as pr', 'pr.id', '=', 'purchase_return_details.purchase_return_id')
    ->where('pr.statut', 'completed')
    ->when($warehouseId, fn ($q) => $q->where('pr.warehouse_id', $warehouseId),
        fn ($q) => $q->whereIn('pr.warehouse_id', $warehouseIds))
    ->whereIn('purchase_return_details.product_id', $productIds)
    ->where('pr.date', '<=', $end)
    ->select(
        'purchase_return_details.product_id',
        'purchase_return_details.product_variant_id',
        DB::raw('SUM(purchase_return_details.quantity) as qty'),
        DB::raw('SUM(purchase_return_details.quantity * purchase_return_details.cost) as cost')
    )
    ->groupBy('purchase_return_details.product_id', 'purchase_return_details.product_variant_id')
    ->get()
    ->keyBy(fn ($r) => $key($r->product_id, $r->product_variant_id));
```

Then update the key unification and per-key avg calculation to include `$pRet`:

```php
$keys = collect(array_unique(array_merge($pIn->keys()->all(), $adj->keys()->all(), $pRet->keys()->all())));
foreach ($keys as $kstr) {
    $pq = (float) ($pIn[$kstr]->qty ?? 0);
    $pc = (float) ($pIn[$kstr]->cost ?? 0);
    $aq = (float) ($adj[$kstr]->qty ?? 0);
    $ac = (float) ($adj[$kstr]->cost ?? 0);
    $rq = (float) ($pRet[$kstr]->qty ?? 0);
    $rc = (float) ($pRet[$kstr]->cost ?? 0);

    $qty  = $pq + $aq - $rq;
    $cost = $pc + $ac - $rc;
    $avg[$kstr] = $qty > 0 ? ($cost / $qty) : 0.0;
}
```

**Step 4: Verify**

Run: `php -l app/Traits/CalculatesCogsAndAverageCost.php`
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add app/Traits/CalculatesCogsAndAverageCost.php
git commit -m "fix: subtract purchase return quantities from FIFO layers and avg cost

Purchase returns were not reducing FIFO inventory layers or the average
cost denominator, leading to incorrect cost allocation."
```

---

### Task 3: Fix Profit Formula & Payment Status Filters in ReportController

**Files:**
- Modify: `app/Http/Controllers/ReportController.php:1857-1938`

**Step 1: Add parent status filters to all four payment queries**

For each payment query, add the parent status check:

```php
// Payment sales — add: ->where('s.statut', 'completed')
$paySales = PaymentSale::join('sales as s', 's.id', '=', 'payment_sales.sale_id')
    ->whereNull('payment_sales.deleted_at')
    ->where('s.statut', 'completed')                    // <-- ADD THIS
    ->whereBetween('payment_sales.date', [$start, $end])
    // ... rest unchanged

// Payment sale returns — add: ->where('sr.statut', 'received')
$paySaleRet = PaymentSaleReturns::join('sale_returns as sr', ...)
    ->whereNull('payment_sale_returns.deleted_at')
    ->where('sr.statut', 'received')                    // <-- ADD THIS
    ->whereBetween(...)
    // ... rest unchanged

// Payment purchase returns — add: ->where('pr.statut', 'completed')
$payPurchRet = PaymentPurchaseReturns::join('purchase_returns as pr', ...)
    ->whereNull('payment_purchase_returns.deleted_at')
    ->where('pr.statut', 'completed')                   // <-- ADD THIS
    ->whereBetween(...)
    // ... rest unchanged

// Payment purchases — add: ->where('p.statut', 'received')
$payPurch = PaymentPurchase::join('purchases as p', ...)
    ->whereNull('payment_purchases.deleted_at')
    ->where('p.statut', 'received')                     // <-- ADD THIS
    ->whereBetween(...)
    // ... rest unchanged
```

**Step 2: Fix the profit formula and add new response fields**

Replace the `$data` array (lines 1911-1938) with:

```php
$revenue = $salesSum - $saleRetSum;

$grossProfitFifo = $revenue - $cogsFIFO;
$grossProfitAvg  = $revenue - $avgCostTotal;

$netProfitFifo = $grossProfitFifo - $expenses;
$netProfitAvg  = $grossProfitAvg  - $expenses;

$grossMarginFifo = $revenue > 0 ? round(($grossProfitFifo / $revenue) * 100, 1) : 0;
$grossMarginAvg  = $revenue > 0 ? round(($grossProfitAvg  / $revenue) * 100, 1) : 0;
$netMarginFifo   = $revenue > 0 ? round(($netProfitFifo   / $revenue) * 100, 1) : 0;
$netMarginAvg    = $revenue > 0 ? round(($netProfitAvg    / $revenue) * 100, 1) : 0;

$data = [
    // Income section
    'sales_sum' => $salesSum,
    'sales_count' => (int) $salesAgg->nmbr,
    'returns_sales_sum' => (float) $saleRetSum,
    'returns_sales_count' => (int) $saleRetAgg->nmbr,
    'total_revenue' => (float) $revenue,

    // COGS section
    'product_cost_fifo' => (float) $cogsFIFO,
    'averagecost' => (float) $avgCostTotal,

    // Gross profit
    'gross_profit_fifo' => (float) $grossProfitFifo,
    'gross_profit_avg' => (float) $grossProfitAvg,
    'gross_margin_fifo' => $grossMarginFifo,
    'gross_margin_avg' => $grossMarginAvg,

    // Expenses
    'expenses_sum' => (float) $expenses,

    // Net profit (THE investor number)
    'profit_fifo' => (float) $netProfitFifo,
    'profit_average_cost' => (float) $netProfitAvg,
    'net_margin_fifo' => $netMarginFifo,
    'net_margin_avg' => $netMarginAvg,

    // Purchases (supplementary)
    'purchases_sum' => (float) $purchSum,
    'purchases_count' => (int) $purchAgg->nmbr,
    'returns_purchases_sum' => (float) $purchRetSum,
    'returns_purchases_count' => (int) $purchRetAgg->nmbr,

    // Cash flow section
    'paiement_sales' => (float) $paySales,
    'PaymentSaleReturns' => (float) $paySaleRet,
    'PaymentPurchaseReturns' => (float) $payPurchRet,
    'paiement_purchases' => (float) $payPurch,
    'payment_received' => (float) ($paySales + $payPurchRet),
    'payment_sent' => (float) ($payPurch + $paySaleRet + $expenses),
    'paiement_net' => (float) (($paySales + $payPurchRet) - ($payPurch + $paySaleRet + $expenses)),
];
```

**Step 3: Verify**

Run: `php -l app/Http/Controllers/ReportController.php`
Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
git add app/Http/Controllers/ReportController.php
git commit -m "fix: correct profit formula to use net revenue, add status filters to payments

Profit now uses (Sales - Returns) instead of gross Sales. Payment queries
now filter by parent transaction status. Added gross profit, margins."
```

---

### Task 4: Add Translation Keys

**Files:**
- Modify: `database/seeders/translations/en.php`

**Step 1: Add new keys near the existing profit-related keys**

Find the area around line 848 (`Gross_Profit`) and the section around line 1753-1757, and add these keys at the end of the translations array (before the closing `];`):

```php
// P&L report — investor layout
'Gross_Sales' => 'Gross Sales',
'Less_Returns' => 'Less: Sales Returns',
'Net_Revenue' => 'Net Revenue',
'Cost_of_Goods_Sold' => 'Cost of Goods Sold',
'Gross_Profit' => 'Gross Profit',       // already exists at 848 — verify, don't duplicate
'Gross_Margin' => 'Gross Margin',
'Operating_Expenses' => 'Operating Expenses',
'Net_Margin' => 'Net Margin',
'Income_Statement' => 'Income Statement',
'Supplementary_Info' => 'Supplementary Information',
'Cash_Flow_Summary' => 'Cash Flow Summary',
'Cash_From_Customers' => 'Cash Received (from customers)',
'Refunds_From_Suppliers' => 'Refunds from Suppliers',
'Total_Cash_In' => 'Total Cash In',
'Cash_To_Suppliers' => 'Cash Paid (to suppliers)',
'Refunds_To_Customers' => 'Refunds to Customers',
'Expenses_Paid' => 'Expenses Paid',
'Total_Cash_Out' => 'Total Cash Out',
'Net_Purchases' => 'Net Purchases',
'FIFO_Method' => 'FIFO Method',
'Average_Cost_Method' => 'Average Cost Method',
'Costing_Method' => 'Costing Method',
'What_is_FIFO' => 'First-In First-Out: oldest inventory costs are used first',
'What_is_AvgCost' => 'Weighted average of all purchase costs',
```

**Step 2: Verify no duplicate keys**

Run: `php -l database/seeders/translations/en.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add database/seeders/translations/en.php
git commit -m "feat: add translation keys for investor-grade P&L report layout"
```

---

### Task 5: Redesign Vue Component — Investor-Grade Waterfall Layout

This is the largest task. The entire `profit_and_loss.vue` template and script sections need rewriting. Keep the `<style>` section but extend it.

**Files:**
- Rewrite: `resources/src/views/app/pages/reports/profit_and_loss.vue`

**Step 1: Rewrite the template section**

Replace the entire `<template>` block (lines 1-141) with a structured layout that has these sections:

1. **Toolbar** (keep as-is: date picker, quick ranges, warehouse, print, refresh)
2. **Costing Method Toggle** — radio buttons: FIFO / Average Cost (controls which numbers display)
3. **Income Statement waterfall** (THE main section):
   - Gross Sales
   - Less: Sales Returns (shown as negative)
   - **= Net Revenue** (bold, highlighted)
   - Cost of Goods Sold (shown as negative)
   - **= Gross Profit** (bold) + Gross Margin %
   - Operating Expenses (shown as negative)
   - **= Net Profit** (big, bold, green/red) + Net Margin %
4. **Supplementary Information** (collapsible):
   - Purchases total, Purchase Returns, Net Purchases
5. **Cash Flow Summary** (collapsible):
   - Cash from customers, Refunds from suppliers, Total Cash In
   - Cash to suppliers, Refunds to customers, Expenses paid, Total Cash Out
   - **Net Cash Flow**

The template should use a `<table>` or structured div rows — NOT stat tiles — because this is a financial statement.

**Step 2: Rewrite the script section**

- Add `costingMethod: 'avg'` to `data()` (default to Average Cost — simpler for most users)
- Add computed properties:
  - `cogs` — returns `infos.averagecost` or `infos.product_cost_fifo` based on toggle
  - `grossProfit` — returns the appropriate gross profit
  - `grossMargin` — returns the appropriate margin %
  - `netProfit` — returns the appropriate net profit
  - `netMargin` — returns the appropriate margin %
- Keep the existing `fetchPnl()`, `applyQuick()`, `money()`, `fmtDate()` methods
- Rewrite `printTableOnly()` to match the new waterfall layout

**Step 3: Full component code**

Here is the complete replacement for `profit_and_loss.vue`:

```vue
<template>
  <div class="main-content p-2 p-md-4">
    <breadcumb :page="$t('ProfitandLoss')" :folder="$t('Reports')" />

    <!-- Toolbar -->
    <b-card class="toolbar-card shadow-soft mb-3 border-0">
      <div class="d-flex flex-wrap align-items-center">
        <!-- Date Range -->
        <div class="mr-3 mb-2">
          <label class="mb-1 d-block text-muted">{{$t('DateRange')}}</label>
          <date-range-picker
            v-model="dateRange"
            :startDate="dateRange.startDate"
            :endDate="dateRange.endDate"
            :locale-data="locale"
            :autoApply="true"
            :showDropdowns="true"
            :opens="picker.opens"
            :drops="picker.drops"
            :parentEl="'body'"
            @update="onDateChange"
          >
            <template v-slot:input="pickerSlot">
              <b-button variant="light" class="btn-pill">
                <i class="i-Calendar-4 mr-1"></i>
                {{ fmtDate(pickerSlot.startDate) }} — {{ fmtDate(pickerSlot.endDate) }}
              </b-button>
            </template>
          </date-range-picker>
        </div>

        <!-- Quick ranges -->
        <div class="mr-3 mb-2">
          <label class="mb-1 d-block text-muted">{{$t('QuickRanges')}}</label>
          <div class="btn-group quick-ranges">
            <b-button size="sm" variant="outline-primary" @click="applyQuick('today')">{{ $t('Today') || 'Today' }}</b-button>
            <b-button size="sm" variant="outline-primary" @click="applyQuick('yesterday')">{{ $t('Yesterday') || 'Yesterday' }}</b-button>
            <b-button size="sm" variant="outline-primary" @click="applyQuick('7d')">7D</b-button>
            <b-button size="sm" variant="outline-primary" @click="applyQuick('30d')">30D</b-button>
            <b-button size="sm" variant="outline-primary" @click="applyQuick('90d')">90D</b-button>
            <b-button size="sm" variant="outline-primary" @click="applyQuick('mtd')">{{$t('MTD')}}</b-button>
            <b-button size="sm" variant="outline-primary" @click="applyQuick('ytd')">{{$t('YTD')}}</b-button>
          </div>
        </div>

        <!-- Warehouse -->
        <div class="mr-3 mb-2">
          <label class="mb-1 d-block text-muted">{{$t('warehouse')}}</label>
          <v-select
            class="w-280"
            @input="onWarehouseChange"
            v-model="warehouse_id"
            :reduce="opt => opt.value"
            :placeholder="$t('Choose_Warehouse')"
            :options="warehouses.map(w => ({label: w.name, value: w.id}))"
            :clearable="true"
          />
        </div>

        <div class="ml-auto mb-2">
          <b-button @click="printReport()" variant="outline-secondary" class="btn-pill mr-2">
            <i class="i-Printer mr-1"></i> {{ $t("print") }}
          </b-button>
          <b-button variant="primary" class="btn-pill" @click="fetchPnl">
            <i class="i-Reload mr-1"></i>{{$t('Refresh')}}
          </b-button>
        </div>
      </div>
    </b-card>

    <!-- Loading -->
    <div v-if="isLoading" class="mb-4">
      <b-row>
        <b-col md="8" class="mx-auto">
          <b-skeleton-img class="rounded-xl shadow-soft" height="400px" />
        </b-col>
      </b-row>
    </div>

    <!-- Report Content -->
    <b-row v-else>
      <b-col lg="10" xl="8" class="mx-auto">

        <!-- Report Header -->
        <div class="report-header text-center mb-4">
          <h4 class="mb-1 font-weight-bold">{{ $t('Income_Statement') || 'Income Statement' }}</h4>
          <p class="text-muted mb-1">
            {{ fmtDate(dateRange.startDate) }} — {{ fmtDate(dateRange.endDate) }}
            <span v-if="warehouseLabel" class="ml-2 badge badge-light">{{ warehouseLabel }}</span>
          </p>

          <!-- Costing Method Toggle -->
          <div class="d-inline-flex align-items-center mt-2 costing-toggle">
            <label class="mb-0 mr-2 text-muted small">{{ $t('Costing_Method') || 'Costing Method' }}:</label>
            <b-form-radio-group
              v-model="costingMethod"
              :options="[
                { text: $t('FIFO_Method') || 'FIFO', value: 'fifo' },
                { text: $t('Average_Cost_Method') || 'Average Cost', value: 'avg' }
              ]"
              button-variant="outline-primary"
              size="sm"
              buttons
            />
          </div>
        </div>

        <!-- INCOME STATEMENT TABLE -->
        <div class="pnl-table shadow-soft rounded-xl overflow-hidden mb-4">
          <table class="table table-borderless mb-0">
            <tbody>
              <!-- === INCOME SECTION === -->
              <tr class="section-header">
                <td colspan="2" class="font-weight-bold text-uppercase small text-primary">
                  {{ $t('Income') || 'Income' }}
                </td>
              </tr>

              <tr>
                <td class="pl-4">{{ $t('Gross_Sales') || 'Gross Sales' }}
                  <span class="text-muted small ml-1">({{ num(infos.sales_count) }})</span>
                </td>
                <td class="text-right">{{ money(infos.sales_sum) }}</td>
              </tr>

              <tr class="text-danger">
                <td class="pl-4">{{ $t('Less_Returns') || 'Less: Sales Returns' }}
                  <span class="text-muted small ml-1">({{ num(infos.returns_sales_count) }})</span>
                </td>
                <td class="text-right">({{ money(infos.returns_sales_sum) }})</td>
              </tr>

              <tr class="subtotal-row">
                <td class="font-weight-bold">{{ $t('Net_Revenue') || 'Net Revenue' }}</td>
                <td class="text-right font-weight-bold">{{ money(infos.total_revenue) }}</td>
              </tr>

              <!-- === COGS SECTION === -->
              <tr class="section-header">
                <td colspan="2" class="font-weight-bold text-uppercase small text-primary">
                  {{ $t('Cost_of_Goods_Sold') || 'Cost of Goods Sold' }}
                </td>
              </tr>

              <tr class="text-danger">
                <td class="pl-4">{{ $t('Product_Cost') }} ({{ costingLabel }})</td>
                <td class="text-right">({{ money(cogs) }})</td>
              </tr>

              <tr class="subtotal-row highlight-green">
                <td class="font-weight-bold">
                  {{ $t('Gross_Profit') }}
                  <span class="badge badge-pill ml-2" :class="grossProfit >= 0 ? 'badge-success' : 'badge-danger'">
                    {{ grossMargin }}%
                  </span>
                </td>
                <td class="text-right font-weight-bold" :class="grossProfit >= 0 ? 'text-success' : 'text-danger'">
                  {{ money(grossProfit) }}
                </td>
              </tr>

              <!-- === EXPENSES SECTION === -->
              <tr class="section-header">
                <td colspan="2" class="font-weight-bold text-uppercase small text-primary">
                  {{ $t('Operating_Expenses') || 'Operating Expenses' }}
                </td>
              </tr>

              <tr class="text-danger">
                <td class="pl-4">{{ $t('Expenses') }}</td>
                <td class="text-right">({{ money(infos.expenses_sum) }})</td>
              </tr>

              <!-- === NET PROFIT === -->
              <tr class="net-profit-row">
                <td class="font-weight-bold">
                  {{ $t('Net_Profit') || 'Net Profit' }}
                  <span class="badge badge-pill ml-2" :class="netProfit >= 0 ? 'badge-success' : 'badge-danger'">
                    {{ netMargin }}%
                  </span>
                </td>
                <td class="text-right font-weight-bold" :class="netProfit >= 0 ? 'text-success' : 'text-danger'">
                  {{ money(netProfit) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- SUPPLEMENTARY: Purchases -->
        <div class="mb-3">
          <b-card no-body class="shadow-soft border-0 rounded-xl">
            <b-card-header header-tag="header" class="p-0 border-0 bg-white" role="tab">
              <b-button block v-b-toggle.collapse-purchases variant="link" class="text-left text-dark font-weight-bold px-3 py-2">
                <i class="i-Arrow-Down-2 mr-1 toggle-icon"></i>
                {{ $t('Supplementary_Info') || 'Supplementary Information' }}
              </b-button>
            </b-card-header>
            <b-collapse id="collapse-purchases" visible>
              <b-card-body class="pt-0">
                <table class="table table-sm table-borderless mb-0">
                  <tr>
                    <td>{{ $t('Purchases') }} <span class="text-muted small">({{ num(infos.purchases_count) }})</span></td>
                    <td class="text-right">{{ money(infos.purchases_sum) }}</td>
                  </tr>
                  <tr>
                    <td>{{ $t('PurchasesReturn') }} <span class="text-muted small">({{ num(infos.returns_purchases_count) }})</span></td>
                    <td class="text-right">({{ money(infos.returns_purchases_sum) }})</td>
                  </tr>
                  <tr class="border-top">
                    <td class="font-weight-bold">{{ $t('Net_Purchases') || 'Net Purchases' }}</td>
                    <td class="text-right font-weight-bold">{{ money(num(infos.purchases_sum) - num(infos.returns_purchases_sum)) }}</td>
                  </tr>
                </table>
              </b-card-body>
            </b-collapse>
          </b-card>
        </div>

        <!-- CASH FLOW SUMMARY -->
        <div class="mb-4">
          <b-card no-body class="shadow-soft border-0 rounded-xl">
            <b-card-header header-tag="header" class="p-0 border-0 bg-white" role="tab">
              <b-button block v-b-toggle.collapse-cashflow variant="link" class="text-left text-dark font-weight-bold px-3 py-2">
                <i class="i-Arrow-Down-2 mr-1 toggle-icon"></i>
                {{ $t('Cash_Flow_Summary') || 'Cash Flow Summary' }}
              </b-button>
            </b-card-header>
            <b-collapse id="collapse-cashflow" visible>
              <b-card-body class="pt-0">
                <table class="table table-sm table-borderless mb-0">
                  <!-- Cash In -->
                  <tr class="text-muted small">
                    <td colspan="2" class="font-weight-bold text-uppercase pb-0">{{ $t('Total_Cash_In') || 'Total Cash In' }}</td>
                  </tr>
                  <tr>
                    <td class="pl-3">{{ $t('Cash_From_Customers') || 'Cash from Customers' }}</td>
                    <td class="text-right">{{ money(infos.paiement_sales) }}</td>
                  </tr>
                  <tr>
                    <td class="pl-3">{{ $t('Refunds_From_Suppliers') || 'Refunds from Suppliers' }}</td>
                    <td class="text-right">{{ money(infos.PaymentPurchaseReturns) }}</td>
                  </tr>
                  <tr class="border-top">
                    <td class="font-weight-bold">{{ $t('Total_Cash_In') || 'Total Cash In' }}</td>
                    <td class="text-right font-weight-bold text-success">{{ money(infos.payment_received) }}</td>
                  </tr>

                  <tr><td colspan="2" class="py-1"></td></tr>

                  <!-- Cash Out -->
                  <tr class="text-muted small">
                    <td colspan="2" class="font-weight-bold text-uppercase pb-0">{{ $t('Total_Cash_Out') || 'Total Cash Out' }}</td>
                  </tr>
                  <tr>
                    <td class="pl-3">{{ $t('Cash_To_Suppliers') || 'Cash to Suppliers' }}</td>
                    <td class="text-right">({{ money(infos.paiement_purchases) }})</td>
                  </tr>
                  <tr>
                    <td class="pl-3">{{ $t('Refunds_To_Customers') || 'Refunds to Customers' }}</td>
                    <td class="text-right">({{ money(infos.PaymentSaleReturns) }})</td>
                  </tr>
                  <tr>
                    <td class="pl-3">{{ $t('Expenses_Paid') || 'Expenses Paid' }}</td>
                    <td class="text-right">({{ money(infos.expenses_sum) }})</td>
                  </tr>
                  <tr class="border-top">
                    <td class="font-weight-bold">{{ $t('Total_Cash_Out') || 'Total Cash Out' }}</td>
                    <td class="text-right font-weight-bold text-danger">({{ money(infos.payment_sent) }})</td>
                  </tr>

                  <tr><td colspan="2" class="py-1"></td></tr>

                  <!-- Net Cash Flow -->
                  <tr class="net-profit-row">
                    <td class="font-weight-bold">{{ $t('NetCashFlow') || 'Net Cash Flow' }}</td>
                    <td class="text-right font-weight-bold" :class="num(infos.paiement_net) >= 0 ? 'text-success' : 'text-danger'">
                      {{ money(infos.paiement_net) }}
                    </td>
                  </tr>
                </table>
              </b-card-body>
            </b-collapse>
          </b-card>
        </div>

      </b-col>
    </b-row>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import moment from "moment";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";

export default {
  metaInfo: { title: "Profit & Loss" },
  components: {
    "date-range-picker": DateRangePicker,
  },
  data() {
    const start = moment().startOf('day').toDate();
    const end   = moment().endOf('day').toDate();
    return {
      warehouses: [],
      warehouse_id: null,
      isLoading: true,
      infos: {},
      costingMethod: 'avg',
      dateRange: { startDate: start, endDate: end },
      picker: { opens: 'right', drops: 'auto' },
      locale: {
        Label: this.$t("Apply") || "Apply",
        cancelLabel: this.$t("Cancel") || "Cancel",
        weekLabel: "W",
        customRangeLabel: this.$t("CustomRange") || "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    currency(){ return (this.currentUser && this.currentUser.currency) || "USD"; },
    warehouseLabel() {
      const w = this.warehouses.find(w => w.id === this.warehouse_id);
      return w ? w.name : null;
    },
    costingLabel() {
      return this.costingMethod === 'fifo'
        ? (this.$t('FIFO_Method') || 'FIFO')
        : (this.$t('Average_Cost_Method') || 'Average Cost');
    },
    cogs() {
      return this.costingMethod === 'fifo'
        ? this.num(this.infos.product_cost_fifo)
        : this.num(this.infos.averagecost);
    },
    grossProfit() {
      return this.costingMethod === 'fifo'
        ? this.num(this.infos.gross_profit_fifo)
        : this.num(this.infos.gross_profit_avg);
    },
    grossMargin() {
      return this.costingMethod === 'fifo'
        ? this.num(this.infos.gross_margin_fifo)
        : this.num(this.infos.gross_margin_avg);
    },
    netProfit() {
      return this.costingMethod === 'fifo'
        ? this.num(this.infos.profit_fifo)
        : this.num(this.infos.profit_average_cost);
    },
    netMargin() {
      return this.costingMethod === 'fifo'
        ? this.num(this.infos.net_margin_fifo)
        : this.num(this.infos.net_margin_avg);
    },
  },

  mounted() {
    this.updatePickerPlacement();
    window.addEventListener('resize', this.updatePickerPlacement);
  },
  beforeDestroy() {
    window.removeEventListener('resize', this.updatePickerPlacement);
  },
  methods: {
    updatePickerPlacement() {
      const isXs = window.matchMedia('(max-width: 576px)').matches;
      this.picker.opens = isXs ? 'center' : 'right';
      this.picker.drops = 'auto';
    },

    fmtDate(d){ return moment(d).format('YYYY-MM-DD'); },
    num(v){ const n = parseFloat(v || 0); return isNaN(n)?0:n; },
    money(v){
      try {
        const n = this.num(v);
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) { this.price_format_key = key; }
        const formatted = formatPriceDisplayHelper(n, 2, key || null);
        return `${this.currency} ${formatted}`;
      } catch(e){
        try {
          return new Intl.NumberFormat(undefined,{style:'currency',currency:this.currency}).format(this.num(v));
        } catch(e2) {
          return `${this.currency} ${this.num(v).toLocaleString()}`;
        }
      }
    },

    onDateChange(){ this.fetchPnl(); },
    onWarehouseChange(){ this.fetchPnl(); },

    applyQuick(kind){
      const now = moment();
      let start, end;
      if (kind === 'today')     { start = now.clone().startOf('day'); end = now.clone().endOf('day'); }
      if (kind === 'yesterday') { start = now.clone().subtract(1,'day').startOf('day'); end = now.clone().subtract(1,'day').endOf('day'); }
      if (kind === '7d')        { start = now.clone().subtract(6,'days').startOf('day'); end = now.clone().endOf('day'); }
      if (kind === '30d')       { start = now.clone().subtract(29,'days').startOf('day'); end = now.clone().endOf('day'); }
      if (kind === '90d')       { start = now.clone().subtract(89,'days').startOf('day'); end = now.clone().endOf('day'); }
      if (kind === 'mtd')       { start = now.clone().startOf('month'); end = now.clone().endOf('day'); }
      if (kind === 'ytd')       { start = now.clone().startOf('year'); end = now.clone().endOf('day'); }
      this.dateRange = { startDate: start.toDate(), endDate: end.toDate() };
      this.fetchPnl();
    },

    //------ Print
    printReport() {
      const title = `${this.$t("Reports")} / ${this.$t("ProfitandLoss")}`;
      const period = `${this.fmtDate(this.dateRange.startDate)} — ${this.fmtDate(this.dateRange.endDate)}`;
      const wh = this.warehouseLabel ? ` | ${this.warehouseLabel}` : '';
      const method = this.costingLabel;

      const row = (label, value, cls='') => `<tr class="${cls}"><td style="padding:6px 10px;">${label}</td><td style="padding:6px 10px;text-align:right;">${value}</td></tr>`;
      const hdr = (label) => `<tr><td colspan="2" style="padding:8px 10px;font-weight:700;text-transform:uppercase;font-size:10px;color:#666;border-bottom:1px solid #eee;">${label}</td></tr>`;
      const sep = () => `<tr><td colspan="2" style="border-bottom:2px solid #333;padding:0;"></td></tr>`;
      const subRow = (label, value) => `<tr style="background:#f8f9fa;"><td style="padding:8px 10px;font-weight:700;">${label}</td><td style="padding:8px 10px;text-align:right;font-weight:700;">${value}</td></tr>`;
      const bigRow = (label, value, color) => `<tr style="background:#f0f0f0;"><td style="padding:10px;font-weight:700;font-size:14px;">${label}</td><td style="padding:10px;text-align:right;font-weight:700;font-size:14px;color:${color};">${value}</td></tr>`;

      let t = `<table style="width:100%;border-collapse:collapse;font-size:12px;font-family:Arial,sans-serif;">`;
      // Header
      t += `<tr><td colspan="2" style="text-align:center;padding:16px;font-size:16px;font-weight:700;border-bottom:2px solid #333;">${this.$t('Income_Statement') || 'Income Statement'}<br><span style="font-size:11px;font-weight:400;color:#666;">${period}${wh} | ${method}</span></td></tr>`;

      // Income
      t += hdr(this.$t('Income') || 'Income');
      t += row(`${this.$t('Gross_Sales') || 'Gross Sales'} (${this.num(this.infos.sales_count)})`, this.money(this.infos.sales_sum));
      t += row(`${this.$t('Less_Returns') || 'Less: Sales Returns'} (${this.num(this.infos.returns_sales_count)})`, `(${this.money(this.infos.returns_sales_sum)})`, 'color:red;');
      t += subRow(this.$t('Net_Revenue') || 'Net Revenue', this.money(this.infos.total_revenue));

      // COGS
      t += hdr(this.$t('Cost_of_Goods_Sold') || 'Cost of Goods Sold');
      t += row(`${this.$t('Product_Cost')} (${method})`, `(${this.money(this.cogs)})`);
      t += subRow(`${this.$t('Gross_Profit')} (${this.grossMargin}%)`, this.money(this.grossProfit));

      // Expenses
      t += hdr(this.$t('Operating_Expenses') || 'Operating Expenses');
      t += row(this.$t('Expenses'), `(${this.money(this.infos.expenses_sum)})`);
      t += sep();

      // Net Profit
      const npColor = this.netProfit >= 0 ? '#2e7d32' : '#c62828';
      t += bigRow(`${this.$t('Net_Profit') || 'Net Profit'} (${this.netMargin}%)`, this.money(this.netProfit), npColor);

      // Supplementary
      t += `<tr><td colspan="2" style="padding:12px 0 4px 0;"></td></tr>`;
      t += hdr(this.$t('Supplementary_Info') || 'Supplementary Information');
      t += row(`${this.$t('Purchases')} (${this.num(this.infos.purchases_count)})`, this.money(this.infos.purchases_sum));
      t += row(this.$t('PurchasesReturn'), `(${this.money(this.infos.returns_purchases_sum)})`);
      t += subRow(this.$t('Net_Purchases') || 'Net Purchases', this.money(this.num(this.infos.purchases_sum) - this.num(this.infos.returns_purchases_sum)));

      // Cash Flow
      t += `<tr><td colspan="2" style="padding:12px 0 4px 0;"></td></tr>`;
      t += hdr(this.$t('Cash_Flow_Summary') || 'Cash Flow Summary');
      t += row(this.$t('Cash_From_Customers') || 'Cash from Customers', this.money(this.infos.paiement_sales));
      t += row(this.$t('Refunds_From_Suppliers') || 'Refunds from Suppliers', this.money(this.infos.PaymentPurchaseReturns));
      t += subRow(this.$t('Total_Cash_In') || 'Total Cash In', this.money(this.infos.payment_received));
      t += row(this.$t('Cash_To_Suppliers') || 'Cash to Suppliers', `(${this.money(this.infos.paiement_purchases)})`);
      t += row(this.$t('Refunds_To_Customers') || 'Refunds to Customers', `(${this.money(this.infos.PaymentSaleReturns)})`);
      t += row(this.$t('Expenses_Paid') || 'Expenses Paid', `(${this.money(this.infos.expenses_sum)})`);
      t += subRow(this.$t('Total_Cash_Out') || 'Total Cash Out', `(${this.money(this.infos.payment_sent)})`);
      t += sep();
      const ncfColor = this.num(this.infos.paiement_net) >= 0 ? '#2e7d32' : '#c62828';
      t += bigRow(this.$t('NetCashFlow') || 'Net Cash Flow', this.money(this.infos.paiement_net), ncfColor);

      t += `</table>`;

      const w = window.open("", "_blank");
      if (!w) { alert("Please allow popups to print"); return; }
      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html><html><head><meta charset="utf-8"/><title>${title}</title>
        <style>@media print { @page { size: A4; margin: 1.5cm; } } body { margin: 1cm; font-family: Arial, sans-serif; }</style>
        </head><body>${t}</body></html>`);
      doc.close();
      w.focus();
      setTimeout(() => { w.print(); w.close(); }, 400);
    },

    fetchPnl(){
      NProgress.start(); NProgress.set(0.1);
      this.isLoading = true;
      const from = this.fmtDate(this.dateRange.startDate);
      const to   = this.fmtDate(this.dateRange.endDate);
      const wh   = this.warehouse_id || '';

      axios.get(`report/profit_and_loss?from=${from}&to=${to}&warehouse_id=${wh}`)
        .then(({data})=>{
          this.infos = data.data || {};
          this.warehouses = data.warehouses || [];
          this.isLoading = false; NProgress.done();
        })
        .catch(()=>{ this.isLoading = false; NProgress.done(); });
    }
  },
  created(){ this.fetchPnl(); }
};
</script>

<style scoped>
.rounded-xl { border-radius: 1rem; }
.shadow-soft { box-shadow: 0 12px 24px rgba(0,0,0,0.06), 0 2px 6px rgba(0,0,0,0.05); }
.toolbar-card { background: #fff; }
.btn-pill { border-radius: 999px; }
.w-280 { width: 280px; }

.report-header h4 { color: #1a1a2e; }
.costing-toggle .btn { font-size: 0.8rem; }

.pnl-table { background: #fff; }
.pnl-table table { margin: 0; }
.pnl-table td { padding: 10px 16px; vertical-align: middle; }

.section-header td {
  padding-top: 16px !important;
  padding-bottom: 6px !important;
  border-bottom: 1px solid #e8e8e8;
  letter-spacing: 0.05em;
}

.subtotal-row {
  background: #f8f9fa;
  border-top: 1px solid #dee2e6;
}
.subtotal-row td { padding: 12px 16px !important; }

.highlight-green { background: #f0fdf4 !important; }

.net-profit-row {
  background: linear-gradient(135deg, #f5f5f5, #ebebeb);
  border-top: 2px solid #333;
}
.net-profit-row td {
  padding: 14px 16px !important;
  font-size: 1.1rem;
}

.toggle-icon { transition: transform 0.2s; }
.collapsed .toggle-icon { transform: rotate(-90deg); }

.daterangepicker { z-index: 2055 !important; }

@media (max-width: 576px) {
  .daterangepicker {
    left: 8px !important; right: 8px !important;
    width: auto !important; max-width: calc(100vw - 16px) !important;
  }
  .daterangepicker .drp-calendar, .daterangepicker .ranges {
    float: none !important; width: 100% !important;
  }
  .quick-ranges {
    display: flex !important; flex-wrap: wrap; width: 100%;
  }
  .quick-ranges .btn {
    flex: 1 1 calc(50% - 6px); margin-bottom: 6px;
  }
}
</style>
```

**Step 4: Verify build compiles**

Run: `npm run dev`
Expected: Compiles without errors

**Step 5: Commit**

```bash
git add resources/src/views/app/pages/reports/profit_and_loss.vue
git commit -m "feat: redesign P&L as investor-grade income statement with waterfall layout

Replaced flat stat tiles with structured financial statement: Income section
(Gross Sales, Returns, Net Revenue), COGS section with method toggle, Gross
Profit with margin %, Expenses, Net Profit. Supplementary purchases and
Cash Flow sections in collapsible panels."
```

---

### Task 6: Final Verification & Manual Testing

**Step 1: Build frontend**

Run: `npm run dev`
Expected: No errors, output files generated in `public/js/`

**Step 2: Verify PHP syntax for all changed files**

Run:
```bash
php -l app/Traits/CalculatesCogsAndAverageCost.php
php -l app/Http/Controllers/ReportController.php
php -l database/seeders/translations/en.php
```
Expected: All report `No syntax errors detected`

**Step 3: Clear Laravel caches**

Run:
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

**Step 4: Manual verification checklist**

Open `https://exportclubpos.local/app/reports/profit_and_loss` and verify:

- [ ] Page loads without errors
- [ ] Date range picker works (set 1 Feb 2026 to 23 Mar 2026)
- [ ] Income Statement section shows: Gross Sales, Less Returns, Net Revenue
- [ ] Net Revenue = Gross Sales - Sales Returns
- [ ] COGS shown with costing method label
- [ ] Gross Profit = Net Revenue - COGS
- [ ] Gross Margin % displayed correctly
- [ ] Net Profit = Gross Profit - Expenses
- [ ] Net Margin % displayed correctly
- [ ] Costing method toggle switches between FIFO and Average Cost
- [ ] Supplementary section shows Purchases and Purchase Returns
- [ ] Cash Flow section shows all cash in/out items
- [ ] Net Cash Flow = Total Cash In - Total Cash Out
- [ ] Print produces clean A4 layout
- [ ] Warehouse filter works
- [ ] Quick range buttons work

**Step 5: Final commit if any adjustments needed**

---

## Summary of What Changes

| Before | After |
|--------|-------|
| Profit = Sales - COGS - Expenses | Profit = (Sales - Returns) - COGS - Expenses |
| COGS on gross sold qty | COGS on net sold qty (minus returns) |
| FIFO layers ignore purchase returns | FIFO layers reduced by purchase returns |
| Payments include pending transactions | Payments only from completed transactions |
| Flat stat tiles, no hierarchy | Waterfall Income Statement format |
| Two profit numbers with no explanation | Single profit with costing method toggle |
| No COGS or Gross Profit visible | Full breakdown: Revenue → COGS → Gross Profit → Expenses → Net Profit |
| Cash mixed with accrual | Separate Cash Flow section |
| No margin percentages | Gross Margin % and Net Margin % |
