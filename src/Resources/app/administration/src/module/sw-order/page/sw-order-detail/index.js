const { Component } = Shopware;

/**
 * Extend the order detail criteria so the split card has the data it needs:
 * every delivery, its shipping address (+country) and its positions with the
 * referenced order line item. We append to the parent criteria via $super so
 * the core associations are preserved.
 *
 * IMPORTANT: the core criteria also loads `primaryOrderDelivery` (without
 * positions). The DAL deduplicates entities by id during hydration, so the
 * primary delivery instance - loaded first without positions - is reused inside
 * the `deliveries` collection and ends up MISSING its positions. That is why
 * only the primary delivery rendered without items. Loading positions on
 * `primaryOrderDelivery` too makes the shared instance carry them, so every
 * delivery in the collection has its positions regardless of hydration order.
 */
Component.override('sw-order-detail', {
    computed: {
        orderCriteria() {
            const criteria = this.$super('orderCriteria');

            criteria.addAssociation('deliveries.shippingOrderAddress.country');
            criteria.addAssociation('deliveries.positions.orderLineItem');
            criteria.addAssociation('primaryOrderDelivery.positions.orderLineItem');

            return criteria;
        },
    },
});
