import template from './swag-order-splitting-settings.html.twig';

Shopware.Component.register('swag-order-splitting-settings', {
    template,

    mixins: ['notification'],

    data() {
        return {
            isLoading: false,
        };
    },

    methods: {
        onSave() {
            this.isLoading = true;
            this.$refs.systemConfig
                .saveAll()
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('swag-order-splitting-settings.saved'),
                    });
                })
                .catch(() => {
                    this.createNotificationError({
                        message: this.$tc('global.notification.notificationSaveErrorMessage'),
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
    },
});
