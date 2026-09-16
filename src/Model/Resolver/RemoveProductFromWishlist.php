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
use Magento\Wishlist\Model\ItemFactory;
use Magento\Wishlist\Model\WishlistFactory;
use Psr\Log\LoggerInterface;

class RemoveProductFromWishlist implements ResolverInterface
{
    /**
     * @param WishlistFactory $wishlistFactory
     * @param ItemFactory $wishlistItemFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly WishlistFactory $wishlistFactory,
        private readonly ItemFactory $wishlistItemFactory,
        private readonly LoggerInterface $logger
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
        $customerId = (int)$context->getUserId();

        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('There was an issue with authorization'));
        }

        $wishlist = $this->wishlistFactory->create();
        $wishlist->loadByCustomerId($customerId);

        $item = $this->wishlistItemFactory->create()->load((int)($args['itemId'] ?? 0));

        // a missing item and a foreign one answer alike, so the id space cannot be walked from outside
        if (!$item->getId() || (int)$item->getWishlistId() !== (int)$wishlist->getId()) {
            throw new GraphQlNoSuchEntityException(__('The wish list item does not exist.'));
        }

        try {
            $item->delete();
            $wishlist->save();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to delete item'));
        }

        return true;
    }
}
