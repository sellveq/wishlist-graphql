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
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Wishlist\Model\Item\Interceptor;
use Magento\Wishlist\Model\Wishlist;
use Psr\Log\LoggerInterface;
use ScandiPWA\WishlistGraphQl\Model\WishlistLoader;

class MoveWishlistToCart implements ResolverInterface
{
    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param CartManagementInterface $quoteManagement
     * @param GuestCartRepositoryInterface $guestCartRepository
     * @param StockStatusRepositoryInterface $stockStatusRepository
     * @param WishlistLoader $wishlistLoader
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $quoteManagement,
        private readonly GuestCartRepositoryInterface $guestCartRepository,
        private readonly StockStatusRepositoryInterface $stockStatusRepository,
        private readonly WishlistLoader $wishlistLoader,
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
        $sharingCode = $args['sharingCode'] ?? null;
        $guestCartId = $args['guestCartId'] ?? null;
        $customerId = (int)$context->getUserId();

        if (!$guestCartId && !$customerId) {
            throw new GraphQlAuthorizationException(__('User not found'));
        }

        $cart = $guestCartId
            ? $this->guestCartRepository->get((string)$guestCartId)
            : $this->cartForCustomer($customerId);

        $wishlist = $sharingCode
            ? $this->wishlistLoader->loadBySharingCode((string)$sharingCode)
            : $this->wishlistLoader->loadByCustomer($customerId);

        if (!$wishlist->getId() || $wishlist->getItemsCount() <= 0) {
            return true;
        }

        // core's ItemCarrier gates Item::addToCart()'s delete on $isOwner: a shared list is copied, never emptied
        $isOwner = $this->wishlistLoader->isOwner($wishlist, $customerId);
        $wishlistItems = $this->getWishlistItems($wishlist);

        foreach ($cart->getItems() as $item) {
            /** @var QuoteItem $item */
            $sku = $item->getProduct()->getSku();

            if (!array_key_exists($sku, $wishlistItems)) {
                continue;
            }

            $wishlistItem = $wishlistItems[$sku];
            unset($wishlistItems[$sku]);

            $item->setQty($item->getQty() + $wishlistItem['qty']);

            if ($isOwner) {
                $wishlistItem['item']->delete();
            }
        }

        $this->addItemsToCart($wishlistItems, $cart, $isOwner);

        if ($isOwner) {
            $this->saveWishlist($wishlist);
        }

        return true;
    }

    /**
     * the customer's active quote, created when there is none, so a first move is not an error
     * @param int $customerId
     * @return Quote
     * @throws GraphQlNoSuchEntityException
     */
    protected function cartForCustomer(int $customerId): Quote
    {
        try {
            /** @var Quote $cart */
            $cart = $this->quoteRepository->get(
                (int)$this->quoteManagement->createEmptyCartForCustomer($customerId)
            );
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new GraphQlNoSuchEntityException(__('Could not find a cart for the current customer'));
        }

        return $cart;
    }

    /**
     * adds new items from the wish list to the cart
     * @param array $wishlistItems
     * @param Quote $quote
     * @param bool $isOwner
     * @return void
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    protected function addItemsToCart(array $wishlistItems, Quote $quote, bool $isOwner): void
    {
        $errors = [];

        foreach ($wishlistItems as $item) {
            $product = $item['product'];

            try {
                $stockStatus = $this->stockStatusRepository->get((int)$product->getId());

                if ((int)$stockStatus->getStockStatus() === StockStatusInterface::STATUS_OUT_OF_STOCK) {
                    $errors[] = (string)__('"%1" is out of stock.', $product->getName());
                    continue;
                }

                $buyRequest = new DataObject((array)json_decode((string)$item['buy_request'], true));
                $quoteItem = $quote->addProduct($product, $buyRequest);

                if (is_string($quoteItem)) {
                    $errors[] = (string)__('%1 for "%2".', trim($quoteItem, '.'), $product->getName());
                    continue;
                }

                $quoteItem->setQty($item['qty']);

                if ($isOwner) {
                    $item['item']->delete();
                }
            } catch (Exception $e) {
                // the message can carry SQL or file paths, so the client is told only which product failed
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                $errors[] = (string)__('"%1" could not be added to the cart.', $product->getName());
            }
        }

        if ($errors) {
            throw new GraphQlInputException(__('%1', implode(' ', $errors)));
        }

        try {
            $this->quoteRepository->save($quote);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new GraphQlNoSuchEntityException(__('Failed to add items to cart'));
        }
    }

    /**
     * the wish list as an sku-keyed array, read without deleting anything: the caller decides what
     * leaves the list, because a list reached by its sharing code belongs to somebody else
     * @param Wishlist $wishlist
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getWishlistItems(Wishlist $wishlist): array
    {
        $items = [];

        /** @var Interceptor $item */
        foreach ($wishlist->getItemCollection() as $item) {
            $product = clone $item->getProduct();
            $buyRequest = $item->getOptionByCode('info_buyRequest');

            $items[$product->getSku()] = [
                'item' => $item,
                'qty' => $item->getQty(),
                'product' => $product,
                'buy_request' => $buyRequest?->getValue() ?? ''
            ];
        }

        return $items;
    }

    /**
     * @param Wishlist $wishlist
     * @return void
     * @throws GraphQlNoSuchEntityException
     */
    protected function saveWishlist(Wishlist $wishlist): void
    {
        try {
            $wishlist->save();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to save wishlist items to cart'));
        }
    }
}
