# Purchase Barcode Search Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an inline barcode search input (with icon + reset button) next to the global search on the purchases list, allowing users to filter purchases by product barcode (exact match).

**Architecture:** Frontend adds a grouped input (barcode icon + text input + clear button) in the `table-actions` slot of `vue-good-table`. On Enter, sends `product_barcode` param to the existing `GET /api/purchases` endpoint. Backend adds a `whereHas('details.product', ...)` clause filtering on `products.code` exact match.

**Tech Stack:** Vue 2.7, BootstrapVue, Laravel 12, Eloquent

---

### Task 1: Backend — Add barcode filter to PurchasesController@index

**Files:**
- Modify: `app/Http/Controllers/PurchasesController.php:91-111`

**Step 1: Add the whereHas clause for product_barcode filtering**

In the `index()` method, after the existing `$Filtred = $helpers->filter(...)` chain (line 92) and before the `->where(function ($query) use ($request) {` global search block (line 94), add a new chained `->when()` clause:

```php
// After line 92: $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
// ADD this new clause before the existing global search block:
        ->when($request->filled('product_barcode'), function ($query) use ($request) {
            return $query->whereHas('details', function ($q) use ($request) {
                $q->whereHas('product', function ($q2) use ($request) {
                    $q2->where('code', $request->product_barcode);
                });
            });
        })
// EXISTING global search block continues:
        ->where(function ($query) use ($request) {
```

The full modified section (lines 92-111) becomes:

```php
        $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
        // Filter by product barcode
            ->when($request->filled('product_barcode'), function ($query) use ($request) {
                return $query->whereHas('details', function ($q) use ($request) {
                    $q->whereHas('product', function ($q2) use ($request) {
                        $q2->where('code', $request->product_barcode);
                    });
                });
            })
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
```

**Step 2: Verify manually**

Test the endpoint with curl or browser:
```
GET /api/purchases?product_barcode=TESTCODE123&limit=10&page=1&SortField=id&SortType=desc
```
Expected: Only purchases containing a product with `code = 'TESTCODE123'` are returned.

**Step 3: Commit**

```bash
git add app/Http/Controllers/PurchasesController.php
git commit -m "feat(purchases): add product_barcode filter to purchases index API"
```

---

### Task 2: Frontend — Add barcode input data property and pass to API

**Files:**
- Modify: `resources/src/views/app/pages/purchases/index_purchase.vue`

**Step 1: Add `Filter_barcode` data property**

In the `data()` return object (line 668 area), add after `Filter_Ref: "",`:

```javascript
Filter_barcode: "",
```

**Step 2: Pass `product_barcode` param in `Get_Purchases()` API call**

In the `Get_Purchases(page)` method (line 1142), add `&product_barcode=` to the URL. After the `&limit=` param (line 1170), add:

```javascript
            "&product_barcode=" +
            this.Filter_barcode
```

**Step 3: Clear `Filter_barcode` in `Reset_Filter()`**

In the `Reset_Filter()` method (line 932), add after `this.Filter_Ref = "";` (line 937):

```javascript
this.Filter_barcode = "";
```

**Step 4: Commit**

```bash
git add resources/src/views/app/pages/purchases/index_purchase.vue
git commit -m "feat(purchases): wire barcode filter data and API param"
```

---

### Task 3: Frontend — Add inline barcode search input group in template

**Files:**
- Modify: `resources/src/views/app/pages/purchases/index_purchase.vue`

**Step 1: Add the barcode input group in the `table-actions` slot**

In the `<div slot="table-actions">` block (line 35), insert a barcode input group **before** the existing Filter button (line 36). The input group contains:
- A prepend with the barcode icon (`i-Bar-Code`)
- A text input bound to `Filter_barcode` that triggers search on Enter key
- An append clear button (×) that clears the input and re-fetches

Insert this block between `<div slot="table-actions" class="mt-2 mb-3">` (line 35) and the Filter button (line 36):

```html
          <b-input-group size="sm" class="d-inline-flex align-items-center m-1" style="width: 250px;">
            <b-input-group-prepend>
              <b-input-group-text>
                <i class="i-Bar-Code"></i>
              </b-input-group-text>
            </b-input-group-prepend>
            <b-form-input
              v-model="Filter_barcode"
              :placeholder="$t('Scan_Barcode')"
              @keyup.enter="Get_Purchases(1)"
            ></b-form-input>
            <b-input-group-append v-if="Filter_barcode">
              <b-button variant="outline-danger" @click="Filter_barcode = ''; Get_Purchases(1)">
                <i class="i-Close"></i>
              </b-button>
            </b-input-group-append>
          </b-input-group>
```

**Step 2: Verify visually**

Run `npm run dev` and navigate to `/app/purchases`. Confirm:
- Barcode input group appears inline next to the global search
- Typing a barcode and pressing Enter filters the list
- Clear button (×) appears when input has text, clicking it clears and re-fetches
- Scanner input (which sends Enter after scan) triggers the filter automatically

**Step 3: Commit**

```bash
git add resources/src/views/app/pages/purchases/index_purchase.vue
git commit -m "feat(purchases): add inline barcode search input with icon and reset button"
```

---

## Summary of Changes

| File | Change |
|------|--------|
| `app/Http/Controllers/PurchasesController.php` | Add `when($request->filled('product_barcode'), whereHas(...))` clause |
| `resources/src/views/app/pages/purchases/index_purchase.vue` | Add `Filter_barcode` data prop, barcode input group in template, pass param in API call, clear in reset |

**Total: 2 files, ~20 lines added**
