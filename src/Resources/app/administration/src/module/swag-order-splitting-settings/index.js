import './page/swag-order-splitting-settings';

/**
 * Adds a dedicated Settings entry (Settings -> Extensions) for the plugin, in
 * addition to the "My extensions -> Configure" page. The page just embeds the
 * plugin's system config (domain "OrderSplitting.config"), sales-channel
 * switchable, so the availability rule can be set here too.
 */
Shopware.Module.register('swag-order-splitting-settings', {
    type: 'plugin',
    name: 'OrderSplitting',
    title: 'swag-order-splitting-settings.title',
    description: 'swag-order-splitting-settings.description',
    color: '#37afc9',
    icon: 'regular-shopping-bag',

    routes: {
        index: {
            component: 'swag-order-splitting-settings',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system.system_config',
            },
        },
    },

    settingsItem: {
        group: 'plugins',
        to: 'swag.order.splitting.settings.index',
        icon: 'regular-shopping-bag',
        privilege: 'system.system_config',
        label: 'swag-order-splitting-settings.title',
    },
});
