<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Baeumi\OrderSplitting\Core\Rule\OrderDeliveryAddressCountRule;

/**
 * Provisions the default automation for tagging split orders:
 *
 *   tag  "Order splitted"
 *   rule "Order splitted?"  (orderSplittingDeliveryAddressCount > 1)
 *   flow "Order split tagging"  (checkout.order.placed -> if rule -> add tag)
 *
 * All entities use deterministic ids so provisioning is idempotent: existing
 * entities are left untouched, so a merchant can safely edit or rename them
 * without the plugin recreating duplicates on the next activate.
 *
 * Run from the plugin's activate() (not install()) so the custom rule condition
 * type is already registered when the rule condition is written.
 */
class DefaultFlowInstaller
{
    public const TAG_NAME = 'Order splitted (Order splitting Extension)';
    public const RULE_NAME = 'Order splitted? (Order splitting Extension)';
    public const FLOW_NAME = 'Order split tagging (Order splitting Extension)';

    public function __construct(
        private readonly EntityRepository $tagRepository,
        private readonly EntityRepository $ruleRepository,
        private readonly EntityRepository $flowRepository,
        private readonly EntityRepository $orderRepository,
        private readonly Connection $connection
    ) {
    }

    /**
     * Deterministic id of the "Order splitted" tag, shared with other services
     * (e.g. admin splitting) that need to (un)assign it.
     */
    public static function tagId(): string
    {
        return md5('swag-order-splitting::tag');
    }

    public function install(Context $context): void
    {
        $this->ensureTag($context);
        $this->ensureRule($context);
        $this->ensureFlow($context);
    }

    /**
     * One-time backfill: assign the tag to already-existing split orders (more
     * than one distinct delivery address) that are not tagged yet. New orders
     * are handled by the flow. Idempotent - already-tagged orders are excluded,
     * so re-running (e.g. on the next activate) is a cheap no-op.
     */
    public function backfillHistoricOrders(Context $context): void
    {
        $tagId = $this->id('tag');
        $live = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        // Live orders with > 1 distinct delivery address, not already tagged.
        $sql = <<<'SQL'
            SELECT LOWER(HEX(od.order_id)) AS id
            FROM order_delivery od
            WHERE od.version_id = :live
              AND od.order_version_id = :live
              AND NOT EXISTS (
                  SELECT 1 FROM order_tag ot
                  WHERE ot.order_id = od.order_id
                    AND ot.order_version_id = od.order_version_id
                    AND ot.tag_id = :tagId
              )
            GROUP BY od.order_id, od.order_version_id
            HAVING COUNT(DISTINCT od.shipping_order_address_id) > 1
        SQL;

        $orderIds = $this->connection->fetchFirstColumn($sql, [
            'live' => $live,
            'tagId' => Uuid::fromHexToBytes($tagId),
        ]);

        if ($orderIds === []) {
            return;
        }

        foreach (array_chunk($orderIds, 50) as $chunk) {
            $payload = array_map(
                static fn (string $orderId): array => [
                    'id' => $orderId,
                    'tags' => [['id' => $tagId]],
                ],
                $chunk
            );

            $this->orderRepository->update($payload, $context);
        }
    }

    public function remove(Context $context): void
    {
        // Order matters: the flow references the rule + tag.
        $this->deleteIfExists($this->flowRepository, $this->id('flow'), $context);
        $this->deleteIfExists($this->ruleRepository, $this->id('rule'), $context);
        $this->deleteIfExists($this->tagRepository, $this->id('tag'), $context);
    }

    private function ensureTag(Context $context): void
    {
        if ($this->exists($this->tagRepository, $this->id('tag'), $context)) {
            return;
        }

        $this->tagRepository->create([[
            'id' => $this->id('tag'),
            'name' => self::TAG_NAME,
        ]], $context);
    }

    private function ensureRule(Context $context): void
    {
        if ($this->exists($this->ruleRepository, $this->id('rule'), $context)) {
            return;
        }

        $this->ruleRepository->create([[
            'id' => $this->id('rule'),
            'name' => self::RULE_NAME,
            'priority' => 1,
            // Flat condition list with parentId, mirroring how the Rule Builder
            // stores the or/and container tree.
            'conditions' => [
                [
                    'id' => $this->id('rule-or'),
                    'type' => 'orContainer',
                    'position' => 0,
                ],
                [
                    'id' => $this->id('rule-and'),
                    'type' => 'andContainer',
                    'parentId' => $this->id('rule-or'),
                    'position' => 0,
                ],
                [
                    'id' => $this->id('rule-condition'),
                    'type' => OrderDeliveryAddressCountRule::RULE_NAME,
                    'parentId' => $this->id('rule-and'),
                    'position' => 0,
                    'value' => [
                        'operator' => OrderDeliveryAddressCountRule::OPERATOR_GT,
                        'count' => 1,
                    ],
                ],
            ],
        ]], $context);
    }

    private function ensureFlow(Context $context): void
    {
        if ($this->exists($this->flowRepository, $this->id('flow'), $context)) {
            return;
        }

        $this->flowRepository->create([[
            'id' => $this->id('flow'),
            'name' => self::FLOW_NAME,
            'eventName' => 'checkout.order.placed',
            'active' => true,
            'priority' => 0,
            'sequences' => [
                [
                    'id' => $this->id('flow-condition'),
                    'ruleId' => $this->id('rule'),
                    'parentId' => null,
                    'position' => 1,
                    'displayGroup' => 1,
                    'trueCase' => false,
                ],
                [
                    'id' => $this->id('flow-action'),
                    'parentId' => $this->id('flow-condition'),
                    'actionName' => 'action.add.order.tag',
                    'position' => 1,
                    'displayGroup' => 1,
                    'trueCase' => true,
                    'config' => [
                        'entity' => 'order',
                        'tagIds' => [
                            $this->id('tag') => self::TAG_NAME,
                        ],
                    ],
                ],
            ],
        ]], $context);
    }

    private function exists(EntityRepository $repository, string $id, Context $context): bool
    {
        return $repository->searchIds(new Criteria([$id]), $context)->getTotal() > 0;
    }

    private function deleteIfExists(EntityRepository $repository, string $id, Context $context): void
    {
        if ($this->exists($repository, $id, $context)) {
            $repository->delete([['id' => $id]], $context);
        }
    }

    /**
     * Deterministic, plugin-owned 32-char hex ids so provisioning is idempotent.
     */
    private function id(string $key): string
    {
        return md5('swag-order-splitting::' . $key);
    }
}
