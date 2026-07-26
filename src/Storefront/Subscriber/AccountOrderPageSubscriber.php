<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Storefront\Subscriber;

use Shopware\Storefront\Event\RouteRequest\OrderRouteRequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enriches the order criteria used by the customer account order history.
 *
 * The core account order loader loads the deliveries with their shipping method
 * but NOT their shipping address or positions. When an order was split across
 * multiple delivery addresses we render a per-delivery summary in the order
 * history (mirroring the confirm/finish pages), which needs each delivery's
 * shipping address plus its positions and their referenced line items. We add
 * those associations here so the data is present without a second query in the
 * template.
 *
 * OrderRouteRequestEvent is dispatched by both the "list" and "single" order
 * routes behind the account order history, so a single subscriber covers the
 * overview and the expanded order detail.
 */
class AccountOrderPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            OrderRouteRequestEvent::class => 'onOrderCriteria',
        ];
    }

    public function onOrderCriteria(OrderRouteRequestEvent $event): void
    {
        $criteria = $event->getCriteria();
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.positions.orderLineItem');
    }
}
