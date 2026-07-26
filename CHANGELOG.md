# 1.0.0

Initial release.

## Storefront
- Ship the line items of a single order to multiple delivery addresses via a dedicated "Split this order" step between the cart and the confirm page.
- Assign product quantities per saved address; each address becomes its own delivery.
- Per-delivery shipping method selection with live cost updates (no full page reload).
- Confirm and finish pages show a per-address summary instead of a single shipping address.
- Customer account order history indicates split orders with a badge and a per-delivery summary.

## Administration
- Order detail shows the split as per-delivery cards (address, products, shipping method, status and tracking).
- Split (or reset) a placed order into multiple deliveries directly from the admin.
- Per-delivery status and tracking management.
- Settings entry under Settings → Extensions → Order Splitting.

## Availability & automation
- Optional Rule Builder rule to control when order splitting is offered.
- On activation, provisions a tag, a rule condition and a flow that tag split orders, and backfills existing split orders.

## Shipping costs
- Shipping costs are taken from the configured shipping methods (no hardcoded values).
