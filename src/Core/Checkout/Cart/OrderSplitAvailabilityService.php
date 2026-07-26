<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Checkout\Cart;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Decides whether the order-split function is available for the current context.
 *
 * Availability is controlled by an optional Rule Builder rule chosen in the
 * plugin config (per sales channel). When a rule is configured, splitting is
 * only available if that rule currently matches. We do not re-evaluate the rule
 * ourselves: Shopware already computes the ids of all rules matching the current
 * cart/context into SalesChannelContext::getRuleIds(), so a membership check is
 * both correct and cheap. No configured rule = always available.
 */
class OrderSplitAvailabilityService
{
    public const CONFIG_KEY = 'OrderSplitting.config.availabilityRuleId';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function isAvailable(SalesChannelContext $context): bool
    {
        $ruleId = $this->systemConfigService->get(self::CONFIG_KEY, $context->getSalesChannelId());

        if (!\is_string($ruleId) || $ruleId === '') {
            return true;
        }

        return \in_array($ruleId, $context->getRuleIds(), true);
    }
}
