<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Storefront\Subscriber;

use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageOrderCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enriches the order criteria used by the checkout finish page.
 *
 * The core finish page loads the order deliveries with their shipping address
 * but NOT their positions. When an order was split across multiple delivery
 * addresses we render a per-delivery item summary (mirroring the confirm page),
 * which needs each delivery's positions and their referenced order line items.
 * We add that association here so the data is present without a second query in
 * the template.
 */
class FinishPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutFinishPageOrderCriteriaEvent::class => 'onOrderCriteria',
        ];
    }

    public function onOrderCriteria(CheckoutFinishPageOrderCriteriaEvent $event): void
    {
        $event->getCriteria()->addAssociation('deliveries.positions.orderLineItem');
    }
}
