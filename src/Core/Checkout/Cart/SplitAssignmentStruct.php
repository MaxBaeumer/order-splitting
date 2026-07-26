<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Checkout\Cart;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Transient cart state that describes how the quantities of each line item are
 * distributed across customer address ids.
 *
 * Stored on the cart as an extension under EXTENSION_KEY. Because it extends
 * Struct it is serialised together with the cart, so it survives page loads and
 * is available on every recalculation without a database table.
 *
 * Shape of $assignments:
 *   [
 *     '<lineItemId>' => [
 *        '<customerAddressId>' => <quantity:int>,
 *        ...
 *     ],
 *     ...
 *   ]
 *
 * The sum of quantities per line item must equal the line item quantity. This
 * is validated in SplitAssignmentService before the struct is written.
 */
class SplitAssignmentStruct extends Struct
{
    public const EXTENSION_KEY = 'orderSplitAssignment';

    /**
     * Declared as explicit properties WITH defaults (not constructor-promoted)
     * so that carts serialised by an older plugin version - which had no
     * `methods` - unserialise cleanly: a promoted property has no class-level
     * default and would be uninitialised, throwing on access.
     *
     * @var array<string, array<string, int>>
     */
    protected array $assignments = [];

    /**
     * @var array<string, string> addressId => shippingMethodId
     */
    protected array $methods = [];

    /**
     * @param array<string, array<string, int>> $assignments
     * @param array<string, string>              $methods  addressId => shippingMethodId
     */
    public function __construct(array $assignments = [], array $methods = [])
    {
        $this->assignments = $assignments;
        $this->methods = $methods;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getAssignments(): array
    {
        return $this->assignments;
    }

    /**
     * @param array<string, array<string, int>> $assignments
     */
    public function setAssignments(array $assignments): void
    {
        $this->assignments = $assignments;
    }

    /**
     * @return array<string, string> addressId => shippingMethodId
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * @param array<string, string> $methods
     */
    public function setMethods(array $methods): void
    {
        $this->methods = $methods;
    }

    public function getMethodForAddress(string $addressId): ?string
    {
        return $this->methods[$addressId] ?? null;
    }

    /**
     * @return string[] distinct shipping method ids chosen for the split
     */
    public function getMethodIds(): array
    {
        return array_values(array_unique(array_filter($this->methods)));
    }

    /**
     * @return string[] distinct customer address ids referenced by the split
     */
    public function getAddressIds(): array
    {
        $ids = [];
        foreach ($this->assignments as $perAddress) {
            foreach (array_keys($perAddress) as $addressId) {
                $ids[$addressId] = true;
            }
        }

        return array_keys($ids);
    }

    public function isActive(): bool
    {
        return $this->assignments !== [] && \count($this->getAddressIds()) > 1;
    }

    public function getApiAlias(): string
    {
        return 'swag_order_split_assignment';
    }
}
