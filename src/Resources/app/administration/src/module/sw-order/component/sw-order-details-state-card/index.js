import template from './sw-order-details-state-card.html.twig';
import '../swag-order-split-modal';

/**
 * Adds a "Split order" button to the header (right of the title) of the
 * SHIPPING state card on the order Details tab. Only for the single-delivery
 * case (position "delivery" with <= 1 delivery); split orders manage
 * splitting/reset from the General tab instead, so their per-delivery cards
 * don't each get a button.
 */
Shopware.Component.override('sw-order-details-state-card', {
    template,

    data() {
        return {
            showSplitModal: false,
        };
    },

    computed: {
        showSplitAction() {
            return this.position === 'delivery'
                && (this.order?.deliveries?.length ?? 0) <= 1
                && this.acl.can('order.editor');
        },
    },

    methods: {
        onSplitDone() {
            this.showSplitModal = false;
            window.location.reload();
        },
    },
});
