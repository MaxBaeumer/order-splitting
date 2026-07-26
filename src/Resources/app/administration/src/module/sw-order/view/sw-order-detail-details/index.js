import template from './sw-order-detail-details.html.twig';
import './sw-order-detail-details.scss';

const { Component } = Shopware;

/**
 * For split orders (more than one delivery) the core Details tab shows a single
 * shipping card bound to the primary delivery only. We replace it with one
 * interactive card per delivery, each reusing the native
 * `sw-order-details-state-card`. That gives every delivery its own delivery
 * STATE (with the standard state-change modal + mail/document handling and the
 * status-history link) and editable TRACKING codes, alongside a read-only
 * address, item list and shipping info.
 *
 * Single-delivery orders are untouched: the core shipping card renders via
 * `{% parent %}`.
 *
 * We only add helper computed/methods and override the shipping block; all state
 * transitions, saving and the status-history modal reuse the parent component's
 * existing wiring (onSaveEdits / saveAndReload / showStateHistoryModal), so we
 * do not reimplement any state-machine or persistence logic.
 */
Component.override('sw-order-detail-details', {
    template,

    computed: {
        isSplitOrder() {
            return (this.order?.deliveries?.length ?? 0) > 1;
        },

        splitDeliveries() {
            return this.order?.deliveries ?? [];
        },

        splitCurrencyCode() {
            return this.order?.currency?.isoCode ?? 'EUR';
        },
    },

    methods: {
        splitAddressLine(delivery) {
            const a = delivery.shippingOrderAddress;
            if (!a) {
                return '-';
            }
            const country = a.country ? a.country.translated?.name ?? a.country.name : '';
            return [
                `${a.firstName ?? ''} ${a.lastName ?? ''}`.trim(),
                a.street,
                `${a.zipcode ?? ''} ${a.city ?? ''}`.trim(),
                country,
            ].filter(Boolean).join(', ');
        },

        splitPositions(delivery) {
            return delivery.positions ?? [];
        },

        splitLineItemLabel(position) {
            return position.orderLineItem?.label ?? position.orderLineItemId ?? '-';
        },

        splitProductId(position) {
            return position.orderLineItem?.productId ?? null;
        },

        splitProductNumber(position) {
            return position.orderLineItem?.payload?.productNumber ?? null;
        },

        splitShippingMethodName(delivery) {
            return delivery.shippingMethod?.translated?.name ?? delivery.shippingMethod?.name ?? '';
        },

        splitFormattedShippingCost(delivery) {
            return Shopware.Utils.format.currency(delivery.shippingCosts?.totalPrice ?? 0, this.splitCurrencyCode);
        },

        validateSplitTrackingCode(delivery, searchTerm) {
            const trackingCode = searchTerm.trim();
            if (trackingCode.length <= 0) {
                return false;
            }

            return !delivery.trackingCodes?.find((code) => code === trackingCode);
        },
    },
});
