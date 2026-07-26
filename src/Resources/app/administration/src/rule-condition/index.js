const { Application } = Shopware;

/**
 * Register the plugin's PHP rule as a Rule Builder condition.
 *
 * Plugin PHP rules (unlike app script-rules) are not auto-listed in the admin
 * condition selection; they must be registered with the
 * ruleConditionDataProviderService. We point it at the generic condition
 * component (`sw-condition-generic`), which builds its form (operator set +
 * count field) from the backend rule config served at /api/_info/rule-config -
 * so no custom condition component is needed.
 *
 * Using addServiceProviderDecorator ensures this runs when the service is first
 * resolved (i.e. before the Rule Builder reads its conditions).
 */
Application.addServiceProviderDecorator('ruleConditionDataProviderService', (ruleConditionService) => {
    // Own group so the condition is clearly attributed to this plugin instead
    // of landing under the generic "Miscellaneous" heading.
    ruleConditionService.upsertGroup('orderSplitting', {
        id: 'orderSplitting',
        name: 'swag-order-splitting.rule.group',
    });

    ruleConditionService.addCondition('orderSplittingDeliveryAddressCount', {
        component: 'sw-condition-generic',
        label: 'global.sw-condition.condition.orderSplittingDeliveryAddressCount',
        scopes: ['global'],
        group: 'orderSplitting',
    });

    return ruleConditionService;
});
