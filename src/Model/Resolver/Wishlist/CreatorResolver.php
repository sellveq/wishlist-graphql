<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_WishlistGraphQl
 * @copyright   Copyright © Scandiweb, Inc. All rights reserved.
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\Resolver\Wishlist;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Wishlist\Model\Wishlist;

class CreatorResolver implements ResolverInterface
{
    /**
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository
    ) {}

    /**
     * {@inheritdoc}
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        /** @var Wishlist $wishlist */
        $wishlist = $value['model'] ?? null;

        if (!$wishlist) {
            return null;
        }

        $customerId = $wishlist->getCustomerId();

        if (!$customerId) {
            return null;
        }

        $customer = $this->customerRepository->getById($customerId);

        $firstName = $customer->getFirstname();
        $lastName = $customer->getLastname();

        return "$firstName $lastName";
    }
}
