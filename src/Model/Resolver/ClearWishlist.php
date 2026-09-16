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

namespace ScandiPWA\WishlistGraphQl\Model\Resolver;

use Exception;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Wishlist\Model\ResourceModel\Wishlist as WishlistResourceModel;
use Magento\Wishlist\Model\WishlistFactory;

class ClearWishlist implements ResolverInterface
{
    /**
     * @param WishlistFactory $wishlistFactory
     * @param WishlistResourceModel $wishlistResource
     */
    public function __construct(
        private readonly WishlistFactory $wishlistFactory,
        private readonly WishlistResourceModel $wishlistResource
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $customerId = $context->getUserId();
        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('Authorization unsuccessful'));
        }

        $wishlist = $this->wishlistFactory->create();
        $this->wishlistResource->load($wishlist, $customerId, 'customer_id');

        if (!$wishlist->getId() || $wishlist->getItemsCount() <= 0) {
            return true;
        }

        $wishlistItems = $wishlist->getItemCollection();
        foreach ($wishlistItems as $item) {
            $item->delete();
        }

        try {
            $wishlist->save();
        } catch (Exception) {
            throw new GraphQlNoSuchEntityException(__('There was an error when clearing wishlist'));
        }

        return true;
    }
}
