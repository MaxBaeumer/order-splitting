import template from './sw-order-general-info.html.twig';
import './sw-order-general-info.scss';
import '../swag-order-split-modal';

const { Component } = Shopware;

/**
 * The General tab "Info" card shows a single delivery-status select bound to the
 * primary delivery only - misleading for a split order. For split orders we
 * replace it with a read-only list of every delivery's status (native state
 * colors) plus a link to the Details tab, where the statuses are actually
 * editable (per-delivery cards). Editing stays in one place; the General tab
 * stays an honest at-a-glance overview.
 *
 * Single-delivery orders keep the core select via `{% parent %}`.
 */
Component.override('sw-order-general-info', {
    template,

    inject: {
        syncService: { from: 'syncService', default: null },
    },

    mixins: ['notification'],

    data() {
        return {
            showSplitModal: false,
            isResetting: false,
        };
    },

    computed: {
        splitInfoIsActive() {
            return (this.order?.deliveries?.length ?? 0) > 1;
        },

        splitInfoDeliveries() {
            return this.order?.deliveries ?? [];
        },
    },

    methods: {
        onSplitDone() {
            this.showSplitModal = false;
            window.location.reload();
        },

        onResetSplit() {
            if (this.isResetting) {
                return;
            }
            this.isResetting = true;
            this.syncService.httpClient
                .post(`_action/order-splitting/${this.order.id}/reset`, {}, {
                    headers: this.syncService.getBasicHeaders(),
                })
                .then(() => {
                    window.location.reload();
                })
                .catch((error) => {
                    this.isResetting = false;
                    const detail = error?.response?.data?.errors?.[0]?.detail
                        ?? this.$tc('swag-order-splitting-split-modal.savedError');
                    this.createNotificationError({ message: detail });
                });
        },

        /**
         * Build a colored pill style matching the native delivery-state select
         * (green "shipped", etc.). The state-style service returns a `variant`
         * (neutral/info/success/warning/danger); we map it to the same Meteor
         * background/text tokens the select uses. `selectBackgroundStyle` from
         * the service is a CSS class scoped to the select component, so it can't
         * be reused on a plain element - hence the explicit token mapping.
         */
        splitDeliveryPillStyle(delivery) {
            const technicalName = delivery?.stateMachineState?.technicalName;
            const variant = technicalName
                ? this.stateStyleDataProviderService.getStyle('order_delivery.state', technicalName).variant
                : 'neutral';

            const tokens = {
                neutral: ['--color-background-tertiary-default', '--color-text-primary-default'],
                info: ['--color-background-brand-default', '--color-text-brand-default'],
                success: ['--color-background-positive-default', '--color-text-positive-default'],
                warning: ['--color-background-attention-default', '--color-text-attention-default'],
                danger: ['--color-background-critical-default', '--color-text-critical-default'],
            };

            const [background, color] = tokens[variant] ?? tokens.neutral;

            return { backgroundColor: `var(${background})`, color: `var(${color})` };
        },
    },
});
