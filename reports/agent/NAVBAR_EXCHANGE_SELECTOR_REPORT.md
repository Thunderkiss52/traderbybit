# VECTOR — Global Navbar Dynamic Exchange Selector Report

## 1. Problem Statement & Motivation

Prior to this implementation, the VECTOR dashboard navbar featured a static two-button toggle:
```html
<q-btn-toggle v-model="exchange" :options="[{label: 'Bybit', value: 'bybit'}, {label: 'Binance', value: 'binance'}]" />
```
This hardcoding presented several operational defects:
1. **Extensibility Blocker:** Newly registered exchanges (e.g. OKX, Hyperliquid, or offline historical research archives) were invisible in the UI header and could not be selected.
2. **Misleading State Indication:** The navbar rendered a static green `LIVE` badge regardless of whether the selected exchange had an active market data connection or was an offline historical dataset.
3. **Disruptive State Transitions:** Switching the toggle triggered a hard browser window reload (`window.location.reload()`), discarding volatile filter states, destroying active charts, and triggering unnecessary API storms.
4. **No Cross-Exchange Aggregate Context:** Operators were unable to view combined portfolio health or multi-exchange execution metrics in an aggregate mode.

---

## 2. Technical Remediation & Architecture

### 2.1 Dynamic State Store (`frontend/src/stores/settings.ts`)
The `useSettings` Pinia store was enhanced:
- `availableExchanges`: Reactive list of registered exchanges loaded dynamically from `GET /api/v1/exchanges`.
- `loadExchanges()`: Asynchronous loader dispatched on layout mount and refreshed on registration/lifecycle changes.
- `selectedExchangeName`: Computed property resolving the active exchange display name, or `"All Exchanges (Aggregate)"` when `exchange === 'all'`.
- `selectedExchangeStatus`: Computed property returning the active exchange lifecycle status (`ACTIVE_PAPER`, `RESEARCH_ENABLED`, `OFFLINE_DATA_SOURCE`, etc.), or `'ALL'`.
- **Account Contamination Guard:** Preserved the strict invariant `watch(exchange, () => { account.value = ''; })` to ensure switching exchanges resets account selection and prevents cross-exchange account leakage.

### 2.2 Global Dropdown Component (`frontend/src/layouts/MainLayout.vue`)
Replaced the hardcoded toggle with a comprehensive Quasar `<q-btn-dropdown>` component:
- **Test ID:** `data-testid="global-exchange-dropdown"`.
- **Item Hierarchy:**
  1. Header: "Registered Exchanges".
  2. Dynamically rendered exchange items with name, slug caption, active selection checkmark, and lifecycle status badge.
  3. "All Exchanges" aggregate item for cross-exchange analytics.
  4. "Manage Exchanges" shortcut directly navigating to `/exchanges`.
- **Replaced Misleading Green LIVE Chip:**
  - Added an explicit current exchange lifecycle status badge (e.g. `ACTIVE_PAPER`, `RESEARCH_ENABLED`).
  - Replaced ambiguous `LIVE` with explicit connectivity chip:
    `{{ online ? 'DATA LIVE' : 'DATA OFFLINE' }}` with icon `wifi`.
- **Eliminated Hard Reloads:** Removed all calls to `window.location.reload()`. Switching exchanges reactively broadcasts changes through Pinia to all mounted views (Dashboard, Risk, Statistics, Journal, Market).

---

## 3. Verification & Evidence

### 3.1 Unit Testing
- Test file: `frontend/tests/exchanges.test.js`
- Test: `MainLayout provides dynamic navbar exchange selector with live/offline status indicator and aggregate mode` — **PASS**
- Test: `settings store provides dynamic exchanges registry and exchange status helper` — **PASS**
- Full frontend suite: **75 tests passed, 0 failed**.

### 3.2 Production Bundle Verification
- Command: `npm run build` (`vue-tsc --noEmit && vite build`)
- Result: **0 TypeScript errors, build succeeded in 8.79s**.
- Chunk output: `dist/assets/index-YWdbX_qs.js` (68.57 kB).

### 3.3 Visual & Functional Verification
- Dynamic dropdown correctly lists Bybit and Binance with `ACTIVE_PAPER` status.
- Selecting an exchange updates the header label without reloading the DOM.
- Status chip correctly renders `DATA LIVE` when browser navigator is online.
