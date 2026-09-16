<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_WishlistGraphQl
 * @copyright   Copyright © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model;

use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Wishlist\Model\ResourceModel\Wishlist as WishlistResourceModel;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\Wishlist\Config as WishlistConfig;
use Magento\Wishlist\Model\WishlistFactory;

class WishlistLoader
{
    /**
     * @param WishlistFactory $wishlistFactory
     * @param WishlistResourceModel $wishlistResource
     * @param WishlistConfig $wishlistConfig
     */
    public function __construct(
        private readonly WishlistFactory $wishlistFactory,
        private readonly WishlistResourceModel $wishlistResource,
        private readonly WishlistConfig $wishlistConfig
    ) {}

    /**
     * the caller's own list, behind the two guards core's WishlistResolver applies
     * @param int $customerId 0 for a guest
     * @param bool $create give a customer who never saved an item a row and a sharing code
     * @return Wishlist
     * @throws GraphQlAuthorizationException
     * @throws GraphQlInputException
     */
    public function loadByCustomer(int $customerId, bool $create = false): Wishlist
    {
        if (!$this->wishlistConfig->isEnabled()) {
            throw new GraphQlInputException(__('The wishlist configuration is currently disabled.'));
        }

        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('The current user cannot perform operations on wishlist'));
        }

        $wishlist = $this->wishlistFactory->create();

        if ($create) {
            return $wishlist->loadByCustomerId($customerId, true);
        }

        $this->wishlistResource->load($wishlist, $customerId, 'customer_id');

        return $wishlist;
    }

    /**
     * a shared list by its code, refusing an unshared code with the message an unknown one gets, so
     * the pair cannot be told apart
     * @param string $sharingCode
     * @return Wishlist
     * @throws GraphQlNoSuchEntityException
     */
    public function loadBySharingCode(string $sharingCode): Wishlist
    {
        $wishlist = $this->wishlistFactory->create();
        $this->wishlistResource->load($wishlist, $sharingCode, 'sharing_code');

        if (!$wishlist->getShared()) {
            throw new GraphQlNoSuchEntityException(__('Shared wishlist with provided sharing code does not exist'));
        }

        return $wishlist;
    }

    /**
     * the caller owns the list only when the ids match; a guest carries id 0 and never owns one
     * @param Wishlist $wishlist
     * @param int $customerId
     * @return bool
     */
    public function isOwner(Wishlist $wishlist, int $customerId): bool
    {
        return $customerId > 0 && $wishlist->isOwner($customerId);
    }
}
