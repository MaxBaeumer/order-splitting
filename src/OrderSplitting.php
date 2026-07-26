<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Baeumi\OrderSplitting\Installer\DefaultFlowInstaller;

/**
 * Plugin base class.
 *
 * The core feature (splitting a cart into multiple deliveries) needs no
 * migrations: the per-item -> address assignment is transient cart state
 * (SplitAssignmentStruct) that Shopware's own OrderConverter turns into NATIVE
 * order_delivery / order_address rows at order placement, so there is no custom
 * entity or schema to create.
 *
 * We do provision a convenience automation on activate: a tag, a Rule Builder
 * rule and a Flow that together tag split orders (more than one delivery
 * address) so they can be filtered in the admin. This runs in activate() rather
 * than install() so the custom rule condition type is already registered when
 * the rule is written, and is removed again on uninstall unless the merchant
 * keeps user data. See DefaultFlowInstaller.
 */
class OrderSplitting extends Plugin
{
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $installer = $this->getFlowInstaller();
        $installer->install($activateContext->getContext());
        // Tag already-existing split orders so they are filterable immediately;
        // new orders are tagged by the provisioned flow.
        $installer->backfillHistoricOrders($activateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->getFlowInstaller()->remove($uninstallContext->getContext());
    }

    private function getFlowInstaller(): DefaultFlowInstaller
    {
        return new DefaultFlowInstaller(
            $this->container->get('tag.repository'),
            $this->container->get('rule.repository'),
            $this->container->get('flow.repository'),
            $this->container->get('order.repository'),
            $this->container->get(\Doctrine\DBAL\Connection::class)
        );
    }
}
