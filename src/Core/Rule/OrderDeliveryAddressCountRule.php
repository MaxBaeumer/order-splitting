<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Rule;

use Shopware\Core\Content\Flow\Rule\FlowRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

/**
 * Rule Builder condition: matches on the number of DISTINCT delivery addresses
 * an order ships to. Intended for Flow Builder ("order placed" -> this condition
 * -> add tag), so a split order (>= 2 delivery addresses) can be tagged and then
 * filtered in the admin.
 *
 * It only evaluates in a flow scope (FlowRuleScope), where a placed order with
 * its deliveries is available; in any other scope it does not match, because
 * order delivery addresses only exist once the order has been created.
 */
class OrderDeliveryAddressCountRule extends Rule
{
    final public const RULE_NAME = 'orderSplittingDeliveryAddressCount';

    protected int $count;

    protected string $operator;

    public function __construct(string $operator = self::OPERATOR_GTE, int $count = 2)
    {
        parent::__construct();
        $this->operator = $operator;
        $this->count = $count;
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof FlowRuleScope) {
            return false;
        }

        $deliveries = $scope->getOrder()->getDeliveries();
        if ($deliveries === null) {
            return false;
        }

        $addressIds = [];
        foreach ($deliveries as $delivery) {
            $addressId = $delivery->getShippingOrderAddressId();
            if ($addressId !== null) {
                $addressIds[$addressId] = true;
            }
        }

        return RuleComparison::numeric((float) \count($addressIds), (float) $this->count, $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'count' => RuleConstraints::int(),
            'operator' => RuleConstraints::numericOperators(false),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_NUMBER)
            ->intField('count');
    }
}
