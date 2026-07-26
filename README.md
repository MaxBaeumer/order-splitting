# Order Splitting (Shopware 6.7)

Ship the line items of a **single order** to **multiple delivery addresses**.
The buyer distributes each product's quantity across addresses from their
address book; every address becomes its own **native `order_delivery`**, each
priced by the selected shipping method. Payment stays single for the whole order.

Target: Shopware **6.7.x**, PHP **8.2+** (tested against the 6.7 plugin API),
classic server-side plugin, default (Bootstrap) storefront theme.

---

## How it works (architecture)

The feature is built entirely on Shopware's native cart -> order pipeline. No
custom entities, no custom tables, no migrations.

```
Cart page ── "Split this order" ──▶ /checkout/split (new step)
                                         │  buyer assigns qty -> address
                                         ▼
              SplitAssignmentService.save()  (validate + attach + recalc)
                                         │
   cart extension: SplitAssignmentStruct  (lineItemId -> {addressId: qty})
                                         │
             ┌───────────── cart recalculation ─────────────┐
             │ OrderSplitCollector  (loads CustomerAddress)  │
             │ core DeliveryProcessor (builds 1 delivery)    │
             │ OrderSplitProcessor  (rebuilds N deliveries)  │  ◀ runs after core
             └───────────────────────────────────────────────┘
                                         │
   Confirm page ──▶ per-address summary (from cart deliveries) ──▶ place order
                                         │
              core OrderConverter maps each cart Delivery to a
              native order_delivery + order_address + positions
                                         ▼
   Finish page ──▶ per-address summary (from order deliveries)
   Admin order detail ──▶ per-delivery cards (status + tracking + items)
```

### Core services / extension points

| Extension point | Class / file | Why |
|---|---|---|
| `shopware.cart.collector` (priority 4500) | `OrderSplitCollector` | Loads the referenced `CustomerAddress` entities (with country/state) into the `CartDataCollection`, **and** the shipping methods chosen per delivery (with their `prices`) under the same key core uses (`shipping-method-<id>`) so `DeliveryCalculator` can price a delivery with a non-default method (e.g. Express). Collectors are the correct place for DB access in the cart pipeline, keeping the processor side-effect free. |
| `shopware.cart.processor` (priority -5200) | `OrderSplitProcessor` | Runs **after** the core `DeliveryProcessor` (priority -5000) and `PromotionDeliveryProcessor` (-5100), i.e. last. Reads the assignment struct, then replaces the cart's single delivery with one `Delivery` per address, each with its own `ShippingLocation` and its **own shipping method** (the one chosen for that address, or the cart default). It then hands the deliveries to the core `DeliveryCalculator`, which prices each one from that method's price matrix and the delivery's own goods. The core `Processor` sums all delivery costs into the cart total, and `OrderConverter` turns each `Delivery` into a native `order_delivery`. |
| `CartService` | used by `SplitAssignmentService` | `getCart()` / `recalculate()` to persist the assignment and re-run the pipeline. After `recalculate()` the service calls `setCart()` to refresh CartService's in-request cache — `recalculate()` persists but does not update that cache, so an AJAX recalculation that re-reads the cart in the same request would otherwise see the stale cart. |
| Storefront route `frontend.checkout.split.recalculate` | `OrderSplitController::recalculate` | AJAX endpoint: saves the current split + methods, recalculates, and re-renders the split page so the JS can swap in the updated `.checkout-aside-summary` without a full reload. Invalid intermediate states keep the last valid cart. |
| `AbstractShippingMethodRoute` | `SplitPageLoader` | Loads the customer's available shipping methods (`onlyAvailable`, same filter as the confirm page) for the per-address method dropdowns. |
| Storefront `confirm` templates | `page/checkout/confirm/index.html.twig`, `confirm-address.html.twig` | `index` adds the entry point ("Split this order"). `confirm-address` replaces the single shipping-address element with a per-address summary (address + items) when the split is active, driven from `page.cart.deliveries`. |
| Storefront `finish` template | `page/checkout/finish/finish-address.html.twig` | After the order is placed, renders the same per-address summary from `page.order.deliveries` (more than one delivery = split). |
| `CheckoutFinishPageOrderCriteriaEvent` | `Storefront/Subscriber/FinishPageSubscriber` | Adds `deliveries.positions.orderLineItem` to the finish-page order criteria so the summary can list the items per delivery (core does not load it). |
| Admin `sw-order-detail` | `page/sw-order-detail` (criteria override) | Adds `deliveries.shippingOrderAddress.country`, `deliveries.positions.orderLineItem` **and** `primaryOrderDelivery.positions.orderLineItem` (see "DAL dedup" note) so the per-delivery cards have their data. |
| Admin `sw-order-detail-details` | `view/sw-order-detail-details` (override) | For split orders, replaces the single Shipping card with **one interactive card per delivery**, reusing the native `sw-order-details-state-card` for per-delivery state + tracking (see below). |
| Admin `sw-order-general-info` | `component/sw-order-general-info` (override) | On the General tab, replaces the single (primary-only) delivery-status select with a **read-only, colored status pill per delivery**, plus a link to the Details tab and the **Split order / Reset** buttons next to the delivery status. |
| Admin `sw-order-details-state-card` | `component/sw-order-details-state-card` (override) | Adds a **Split order** button to the Shipping card header (`#headerRight`) on the Details tab, for single-delivery orders. |
| Admin-API `OrderSplitApiController` | `Api/OrderSplitApiController` | `POST /api/_action/order-splitting/{orderId}/split` and `/reset` (ACL `order.editor`). Called by the admin split modal. |
| `AdminOrderSplitService` | `Core/Checkout/Cart/AdminOrderSplitService` | Splits/un-splits a **placed** order — see "Admin: splitting a placed order". |

### Admin: per-delivery status & tracking

Each native `order_delivery` already carries its own delivery **state**
(`state_id`, its own state machine) and **tracking codes** (`trackingCodes`).
Core only ever surfaces the *primary* delivery, so for split orders the
**Details** tab renders one card per delivery, each reusing the native
`sw-order-details-state-card`:

- **Status** — the delivery's own state select, with the standard state-change
  modal (send mail? generate document?) and status-history link. Transitions go
  through `orderStateMachineService.transitionOrderDeliveryState(deliveryId, …)`.
- **Tracking** — a `sw-multi-tag-select` bound to that delivery's
  `trackingCodes`, saved through the page's existing order-save flow.
- Read-only address, item list (product links + numbers) and shipping cost.

The **General** tab stays an at-a-glance overview: a read-only colored pill per
delivery status, plus a shortcut to the Details tab where they are editable.
Editing lives in exactly one place to avoid duplicated state-change wiring.

### Admin: splitting a placed order

An admin can split (or un-split) an already placed order from the order **Details**
tab ("Split order" in the Shipping card header) or the **General** tab (next to
the delivery status). It reuses the whole cart-based split pipeline by
round-tripping the order through a cart (`AdminOrderSplitService`):

```
order ─ convertToCart ─▶ attach SplitAssignmentStruct ─▶ Processor.process
        (OrderSplitCollector + OrderSplitProcessor rebuild deliveries) ─▶
        convertToOrder ─▶ replace deliveries + persist (order version, merged)
```

Notes on the differences from a normal recalculation:

- It runs in a dedicated **order version** that is merged at the end. The upsert,
  the delete and the **merge** all run in `SYSTEM` scope, because the delivery
  state-machine fields are protected in `user` scope.
- Shopware's recalc only deletes *discount* deliveries, so the service **fully
  replaces** the deliveries itself (deletes the ones the split replaced).
- The core `DeliveryProcessor` preserves existing deliveries during an order
  recalc; the service unsets `SKIP_DELIVERY_PRICE_RECALCULATION` so deliveries
  are rebuilt (and reset collapses back to one), and prices each by its method.
- Every shippable line item must be assigned (else it would be dropped from all
  deliveries); the service and the modal both enforce this.
- The **"Order splitted" tag** is (un)assigned directly here — the flow only tags
  storefront-placed orders, so admin splits/resets manage the same tag id.

Only registered-customer orders are supported (addresses come from the customer's
address book). After a split/reset the page reloads so the order re-loads from
the merged live version.

### Order placement: delivery-position identifier

`OrderSplitProcessor` builds each `DeliveryPosition` with the **cart line item's
id** as its identifier. This is required: at placement, core
`DeliveryTransformer` maps a delivery position to an order line item by looking
up `position.getIdentifier()` in a map keyed by `lineItem->getId()`
(`LineItemTransformer`). Any other identifier is silently skipped, which would
persist deliveries with **zero positions** (no item-to-delivery mapping in the
order). The same line item may legitimately appear in several deliveries (a
product split across addresses) — those live in separate position collections,
so reusing the id is safe.

### DAL entity-dedup note (admin)

Core loads both `primaryOrderDelivery` (without positions) and `deliveries`
(with positions). The DAL deduplicates entities by id during hydration, so the
primary delivery instance — loaded first without positions — is reused inside
the `deliveries` collection and ends up missing its positions. Loading
`primaryOrderDelivery.positions.orderLineItem` too makes the shared instance
carry them, so every delivery renders its items regardless of hydration order.

### Split-order tagging (Rule Builder condition + auto-provisioned Flow)

Split orders are tagged automatically so they can be filtered in the admin, via
Shopware's native Rule Builder + Flow Builder — no bespoke tagging code:

- **Rule condition** `OrderDeliveryAddressCountRule`
  (`orderSplittingDeliveryAddressCount`) — matches on the number of DISTINCT
  delivery addresses of a placed order (operator + count). It evaluates only in
  a `FlowRuleScope` (where the placed order and its deliveries exist). It is a
  plain PHP rule (`shopware.rule.definition`) with `getConfig()`, so the admin
  renders it generically (no custom condition component). Plugin PHP rules are
  not auto-listed in the admin, so it is registered with the
  `ruleConditionDataProviderService` (see `Resources/app/administration/src/rule-condition`),
  under its own condition group "Order Splitting (Extension)".
- **Auto-provisioned entities** (`Installer\DefaultFlowInstaller`, run from the
  plugin's `activate()`), all with deterministic ids so provisioning is
  idempotent and merchant edits/renames are preserved:
  - tag  **"Order splitted (Order splitting Extension)"**
  - rule **"Order splitted? (Order splitting Extension)"** (the condition above, `> 1`)
  - flow **"Order split tagging (Order splitting Extension)"** on
    `checkout.order.placed`: *if* the rule matches → action `action.add.order.tag`
    adds the tag to the order.

Provisioning runs in `activate()` (not `install()`) so the custom rule
condition type is already registered when the rule condition is written.

- **Historic backfill** (`DefaultFlowInstaller::backfillHistoricOrders`, also run
  from `activate()`): tags already-existing split orders (live orders with more
  than one distinct delivery address) that are not tagged yet, so pre-existing
  orders are filterable immediately — new orders are handled by the flow.
  Candidates are found with a single aggregate query over `order_delivery` and
  the tag is assigned in batches. It is idempotent (already-tagged orders are
  excluded), so re-activating is a cheap no-op.

### Availability (optional Rule Builder rule)

Order splitting can be gated by a **Rule Builder rule** chosen in the plugin
config (`Resources/config/config.xml`, field `availabilityRuleId`) — reachable
from **My extensions → Configure** or **Settings → Extensions → Order Splitting**,
and set **per sales channel**. When a rule is configured, the "Split this order"
entry point and the `/checkout/split*` routes are only available while that rule
currently matches; empty = always available (default).

Evaluation is a membership check: Shopware already computes the matching rule ids
for the current cart into `context.getRuleIds()`, so `OrderSplitAvailabilityService`
(and the confirm-page Twig, via `config()` + `context.ruleIds`) just checks
whether the configured rule id is present — no custom rule evaluation. A single
rule covers "sales channel is …", "customer group is …", cart value, etc., which
is why there are no separate channel/group settings.

### Why no migration

The assignment is **transient cart state**. It lives as a serialisable cart
extension (`SplitAssignmentStruct`) that travels with the cart and is converted
to permanent `order_delivery` / `order_address` / `order_delivery_position` rows
by Shopware itself at order placement. Since the persisted result uses only the
native model, there is nothing custom to migrate.

---

## Install & activate

From the Shopware root:

```bash
# 1. place the plugin
#    custom/plugins/OrderSplitting

# 2. refresh & install
bin/console plugin:refresh
bin/console plugin:install --activate OrderSplitting
bin/console cache:clear
```

Activating the plugin auto-provisions the split-order tagging tag/rule/flow (see
above). Provisioning is idempotent — re-activating never creates duplicates.

## Build assets

```bash
# storefront JS (split-step plugin) + SCSS
bin/console theme:compile
./bin/build-storefront.sh

# administration (per-delivery cards + overrides)
./bin/build-administration.sh
bin/console assets:install
```

**Note on iteration:** the storefront confirm/finish summaries are server-side
Twig + snippets — they need only a cache clear, no asset build. The admin
overrides (Details cards, General overview) require
`./bin/build-administration.sh` + `cache:clear`.

On a production build run the shop's standard
`bin/build-storefront.sh` and `bin/build-administration.sh`. In development,
`bin/watch-storefront.sh` / `bin/watch-administration.sh` pick up the sources
under `Resources/app/**/src`.

## Uninstall

```bash
bin/console plugin:uninstall OrderSplitting
```

No custom tables to clean up. Uninstalling **without** "keep user data" also
removes the auto-provisioned tag/rule/flow; with "keep user data" they are left
in place.

---

## Usage

### Storefront

1. Log in as a customer with **at least two** saved addresses and a cart with
   shippable items.
2. Go to checkout; on the **confirm page** click **"Split this order"**.
3. For each product, pick an address and quantity. Add rows to split one
   product across several addresses. Quantities per product must sum to the
   product total (validated live).
4. In the **"Shipping method per delivery address"** section, choose a method
   (e.g. Standard or Express) for each address. The **Summary** recalculates
   **live** (no full page reload) as you change quantities, addresses or methods.
5. Continue. The **confirm page** shows a per-address summary (each address with
   its items) in place of the single shipping address; each delivery is priced
   by its chosen method (see "Shipping method & costs per delivery" below).
6. Place the order. The **finish page** shows the same per-address summary.

### Admin

- **Split a placed order:** click **"Split order"** on the order Details tab
  (Shipping card header) or the General tab (next to the delivery status). In the
  modal, assign each product's quantity to the customer's addresses and pick a
  shipping method per address, then confirm. **"Reset to single delivery"**
  collapses a split order back. See "Admin: splitting a placed order".
- **Order → Details tab:** one card per delivery, each with its own **delivery
  status** (state change + mail/document modal + history) and editable
  **tracking codes**, plus the address, items (linked to the product with the
  product number) and shipping cost.
- **Order → General tab:** the "Info" card shows a read-only colored status pill
  per delivery and a link to the Details tab to change them.

Single-delivery orders are untouched in both tabs (native views render as usual).

## Shipping method & costs per delivery

Each address/delivery can use its **own shipping method**. The choice
(`addressId → shippingMethodId`) is stored on the `SplitAssignmentStruct`;
`OrderSplitCollector` loads the chosen methods (with prices) into the cart data,
and `OrderSplitProcessor` assigns each delivery its method before pricing. A
delivery with no explicit choice falls back to the cart's default method.

Each delivery is then priced by the core `DeliveryCalculator`, which applies the
method's price matrix (quantity/price/weight tiers, price rules) to that
delivery's own goods, including free-shipping line items. There is no flat rate.

The split page recalculates the cost **summary live** (via the
`frontend.checkout.split.recalculate` AJAX endpoint) as the split or a method
changes — the storefront JS only posts once the split is complete and ignores
out-of-order responses, and the summary block is swapped in place. Without JS it
still works: the split saves on submit and deliveries use the default method.

Note that each delivery is priced **independently**: a free-shipping-above-X
threshold is evaluated per delivery, so splitting a qualifying order into smaller
per-address shipments can legitimately produce shipping costs the single-delivery
order would not have had. When an admin splits a placed order, the payment
transaction's `amount` is updated to the new order total to keep the order
internally consistent — this is a data update only (no capture/refund), so
reconciling an already-paid difference remains the merchant's responsibility.

---

## Compatibility & interference

The plugin extends several shared systems. Points to be aware of when running it
alongside other extensions:

- **Cart delivery pipeline.** `OrderSplitProcessor` (priority `-5200`, runs last)
  replaces the cart's delivery collection, but **only when a split is active**
  and **only if exactly one delivery exists** when it runs. If another processor
  (or core, e.g. per-restock-time) already built multiple deliveries, it bails
  and leaves them untouched. It is therefore **not compatible with other
  delivery-splitting / multi-delivery extensions** — the split silently won't
  apply for such carts. There is no interference for non-split carts.
- **Storefront & admin overrides are cooperative.** The Twig block overrides
  (confirm/finish address) and the admin component overrides (`sw-order-detail*`,
  `sw-order-general-info`, `sw-order-details-state-card`) branch on "is this a
  split order?" and otherwise call `{{ parent() }}` / `$super`, so a
  single-delivery order renders exactly like core. Another plugin overriding the
  **same block** on the same page is the residual risk (e.g. SwagPayPal also
  overrides `checkout/confirm/index` and `sw-order-detail-details`); verify the
  combination — see the checklist below.
- **Admin split writes.** Splitting/resetting a placed order rewrites the order's
  deliveries in `SYSTEM` scope (state-machine fields are protected) and fires any
  order-write subscribers other extensions register (ERP sync, fulfilment, label
  plugins). The transaction amount is kept in sync (see above).
- **Namespacing.** Provisioned tag/rule/flow use deterministic, namespaced ids;
  the rule condition (`orderSplittingDeliveryAddressCount`), its group, routes,
  config domain (`OrderSplitting.config`), snippets and the JS plugin name are
  all prefixed to avoid collisions. Uninstalling (without "keep user data")
  removes the provisioned tag/rule/flow.

### Compatibility test checklist (run before release / on new extensions)

- [ ] **Storefront checkout** with the split feature, alongside your payment
      plugin(s) (**SwagPayPal**, **ShopwarePayments**): confirm page entry point +
      per-address summary render, order places, finish page summary renders.
- [ ] **Admin order detail** with SwagPayPal active: the "Split order" button,
      per-delivery cards and PayPal's own order extensions all render.
- [ ] **Admin split then payment**: split an order, verify order total and the
      transaction amount match, and check the payment plugin's status view.
- [ ] **Row-level permission plugins** (e.g. `SalesChannelUserPermissions`):
      confirm the admin split write is allowed and split deliveries are visible.
- [ ] **Any delivery/shipping extension**: confirm no double-delivery handling
      (the processor guard should make the split bail rather than clobber).

## Security

**Authorization**

- Storefront split routes (`/checkout/split`, `/save`, `/recalculate`, `/reset`)
  are all `_loginRequired` — a guest cannot reach them. The split targets come
  from the logged-in customer's own address book.
- The admin-API endpoints (`/api/_action/order-splitting/{orderId}/split|reset`)
  require the `order.editor` ACL privilege. This is the **global** Shopware order
  privilege (an editor may edit any order); the plugin does not add a per-order /
  per-sales-channel ownership check. If you enforce **row-level** order
  restrictions via another extension, be aware the admin split writes run in
  `SYSTEM` scope (required for the protected delivery state fields) and therefore
  bypass row-level write guards — treat "may edit orders" as the authorization
  boundary.
- The availability gate (optional Rule Builder rule) is enforced **server-side**
  in the controller, not just hidden in the UI.

**Input validation**

- **Addresses** are always checked to belong to the acting customer: the
  storefront checks the logged-in customer, the admin path checks the *order's*
  customer. You cannot ship to an arbitrary or another customer's address.
- **Quantities** must sum to each line item's total, and every shippable item
  must be assigned (nothing can be silently dropped or inflated).
- **Shipping methods** chosen per address are validated against the methods
  actually available to the customer (same set as the confirm page); any other
  id is dropped and that delivery uses the cart's default method — so a crafted
  request cannot apply an unavailable (e.g. cheaper) method's price.

**By design**

- All user input is used only as DAL ids / filter values (parameterized) — no
  raw SQL, no string-built queries. Rendered output goes through Twig
  auto-escaping (storefront) and Vue interpolation / `textContent` (admin) — no
  `innerHTML` of user data. The plugin makes no external HTTP calls and touches
  no files or credentials.
- The admin split writes orders inside a dedicated **order version** that is
  merged atomically; `SYSTEM` scope is used only where the platform requires it
  (protected state-machine fields).

## Known limitations / production hardening

These are deliberate simplifications for a focused example. Address them before
going live:

1. **Per-delivery independent pricing.** As noted under "Shipping method & costs per delivery", each
   delivery is priced on its own goods. This is usually what you want for
   multi-address shipments, but verify it against your shipping-method rules
   (especially free-shipping thresholds) before going live.
2. **Address changes after split.** If a customer deletes an address that is
   still referenced by the assignment, `OrderSplitProcessor` silently drops the
   affected delivery. Consider a validation error + re-prompt.
3. **Promotions across splits.** Line item prices are untouched, so goods
   totals and vouchers behave normally. Verify shipping-based promotions still
   behave as intended once multiple deliveries exist.
4. **Order-level mails & documents.** Delivery state changes can send mails and
   generate documents, but mail templates and documents (invoice, delivery
   note) are **order-level** — a "shipped" mail for one delivery still renders
   the whole order, not just that delivery's items. Per-delivery mails/documents
   would need custom templates and are out of scope.
5. **Order-list delivery-status column.** The order overview grid shows the
   **primary** delivery's status only; the per-delivery statuses are visible on
   the order detail (General/Details tabs), not in the list.
6. **Admin block names & criteria.** The overrides target current 6.7 block
   names (`sw_order_detail_details_shipping`,
   `sw_order_detail_base_general_info_order_states_shipping`) and the
   `orderCriteria` computed. Confirm against your exact minor and adjust if a
   block was renamed.
7. **Guest checkout.** The step requires a logged-in customer
   (`_loginRequired`), because targets come from the address book. Guests would
   need an inline-address flow.
8. **Admin split reloads the page.** After an admin split/reset the order detail
   does a full page reload (the split merges to the live order while the detail
   edits a draft version). Functional, but a smoother in-place refresh is a
   possible improvement.
9. **Not compatible with other delivery-splitting extensions.** See
   "Compatibility & interference" — the processor bails rather than clobber, so
   the split silently won't apply when another extension already built multiple
   deliveries.
10. **Not yet covered by automated tests.** See "Testing" below.

## Testing

Suggested coverage (PHPUnit, Shopware `IntegrationTestBehaviour`):

- `OrderSplitProcessor`: given a cart with a 3-qty product split 2/1 across two
  addresses, asserts two deliveries, correct positions, and summed shipping.
- `SplitAssignmentService`: rejects quantities that do not sum to the total and
  addresses not owned by the customer.
- End-to-end: place a split order and assert two `order_delivery` rows **each
  with its `order_delivery_position` rows** (guards the position-identifier
  behaviour described above) and the expected `order_address` rows.

---

## File map

```
src/
  OrderSplitting.php                       plugin base class (activate() provisions tag/rule/flow)
  Resources/config/services.xml            DI: collector, processor, services, controllers, page loader, finish subscriber, rule, availability + admin split
  Resources/config/routes.xml              storefront + admin-API route imports
  Installer/DefaultFlowInstaller.php       provisions/removes the tag + rule + flow
  Core/Rule/
    OrderDeliveryAddressCountRule.php      Rule Builder condition: number of delivery addresses
  Core/Checkout/Cart/
    SplitAssignmentStruct.php              cart extension (assignment state)
    SplitAssignmentService.php             validate + persist + recalculate (storefront)
    OrderSplitCollector.php                loads addresses + chosen methods into cart data
    OrderSplitProcessor.php                rebuilds deliveries per address, prices per method
    OrderSplitAvailabilityService.php      availability check (config rule)
    AdminOrderSplitService.php             split/reset a PLACED order (order <-> cart round-trip)
  Api/
    OrderSplitApiController.php            admin-API: /_action/order-splitting/{id}/split|reset
  Storefront/
    Controller/OrderSplitController.php     GET/POST /checkout/split (+ reset)
    Page/Split/SplitPage.php                page object
    Page/Split/SplitPageLoader.php          builds the split-step page
    Page/Split/SplitPageLoadedEvent.php     extension event
    Subscriber/FinishPageSubscriber.php     adds deliveries.positions to finish criteria
  Resources/views/storefront/page/checkout/
    split/index.html.twig, _row.html.twig   the split step
    confirm/index.html.twig                 "Split this order" entry point
    confirm/confirm-address.html.twig        per-address summary (from cart)
    finish/finish-address.html.twig          per-address summary (from order)
  Resources/app/storefront/src/...          split-step JS plugin
  Resources/config/config.xml              plugin config: availability rule (per sales channel)
  Resources/app/administration/src/
    main.js                                  imports + admin snippets
    rule-condition/index.js                  registers the rule condition + its group in the admin
    module/swag-order-splitting-settings/     Settings -> Extensions entry (system config)
    module/sw-order/page/sw-order-detail/     criteria override
    module/sw-order/view/sw-order-detail-details/   per-delivery cards (status + tracking + items)
    module/sw-order/component/sw-order-general-info/     per-delivery status overview + split/reset
    module/sw-order/component/sw-order-details-state-card/ Split button in the Shipping card header
    module/sw-order/component/swag-order-split-modal/    the admin split modal
  Resources/snippet/                         storefront snippets (en-GB, de-DE)
```
