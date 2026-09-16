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

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use ScandiPWA\WishlistGraphQl\Model\WishlistLoader;

class WishlistResolver implements ResolverInterface
{
    /**
     * @param WishlistLoader $wishlistLoader
     */
    public function __construct(
        private readonly WishlistLoader $wishlistLoader
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
        $sharingCode = $args['sharing_code'] ?? null;

        $wishlist = $sharingCode
            ? $this->wishlistLoader->loadBySharingCode((string)$sharingCode)
            : $this->wishlistLoader->loadByCustomer((int)$context->getUserId());

        if (!$wishlist->getId()) {
            return [
                'model' => $wishlist,
            ];
        }

        return [
            'sharing_code' => $wishlist->getSharingCode(),
            'updated_at' => $wishlist->getUpdatedAt(),
            'items_count' => $wishlist->getItemsCount(),
            'name' => $wishlist->getName(),
            'model' => $wishlist,
        ];
    }
}
