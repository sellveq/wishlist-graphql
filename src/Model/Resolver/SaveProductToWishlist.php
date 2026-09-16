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
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\WishlistFactory;
use Psr\Log\LoggerInterface;

class SaveProductToWishlist implements ResolverInterface
{
    /**
     * the one answer a caller gets for an item that is missing and for an item that belongs to
     * somebody else, so the pair cannot be told apart
     */
    private const ITEM_NOT_FOUND = 'The wish list item does not exist.';

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param WishlistFactory $wishlistFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly WishlistFactory $wishlistFactory,
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
            throw new GraphQlAuthorizationException(__('Authorization unsuccessful'));
        }

        $sku = (string)($args['wishlistItem']['sku'] ?? '');
        $itemId = (string)($args['wishlistItem']['item_id'] ?? '');

        $wishlist = $this->wishlistFactory->create();
        $wishlist->loadByCustomerId($customerId, true);

        if ($sku !== '') {
            return $this->addProductToWishlist($wishlist, $sku, $args['wishlistItem']);
        }

        if ($itemId !== '') {
            return $this->updateWishlistItem($wishlist, $itemId, $args['wishlistItem']);
        }

        throw new GraphQlInputException(__('Please specify either sku or item_id'));
    }

    /**
     * @param string $type
     * @param array $productOption
     * @return array
     * @throws GraphQlInputException
     */
    protected function getProductConfigurableData(string $type, array $productOption): array
    {
        switch ($type) {
            case ConfigurableType::TYPE_CODE:
                return [
                    'super_attribute' => $this->getOptionsArray(
                        $this->requiredOptions($productOption, 'configurable_item_options')
                    )
                ];

            case GroupedType::TYPE_CODE:
                return [
                    'super_group' => $this->getOptionsArray(
                        $this->requiredOptions($productOption, 'grouped_product_options')
                    )
                ];

            case DownloadableType::TYPE_DOWNLOADABLE:
                $configurableData = ['links' => []];

                foreach ($productOption['extension_attributes']['downloadable_product_links'] ?? [] as $link) {
                    $configurableData['links'][$link['link_id']] = $link['link_id'];
                }

                return $configurableData;

            case BundleType::TYPE_CODE:
                $configurableData = [];

                foreach ($productOption['extension_attributes']['bundle_options'] ?? [] as $bundleOption) {
                    $optionId = $bundleOption['id'];
                    $configurableData['bundle_option'][$optionId][] = $bundleOption['value'];
                    $configurableData['bundle_option_qty'][$optionId] = $bundleOption['quantity'];
                }

                return $configurableData;

            default:
                return [];
        }
    }

    /**
     * the configurable and grouped types cannot be added without their selection, so the missing
     * input is named rather than left to fail as a type error deeper in the call
     * @param array $productOption
     * @param string $key
     * @return array
     * @throws GraphQlInputException
     */
    protected function requiredOptions(array $productOption, string $key): array
    {
        $options = $productOption['extension_attributes'][$key] ?? null;

        if (!is_array($options) || !$options) {
            throw new GraphQlInputException(
                __('Please specify "product_option.extension_attributes.%1" for this product.', $key)
            );
        }

        return $options;
    }

    /**
     * @param Wishlist $wishlist
     * @param string $sku
     * @param array $parameters
     * @return array
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function addProductToWishlist(Wishlist $wishlist, string $sku, array $parameters): array
    {
        $quantity = $parameters['quantity'] ?? 1;
        $description = $parameters['description'] ?? '';
        $productOption = $parameters['product_option'] ?? [];

        /** @var Product $product */
        $product = $this->productRepository->get($sku);

        if (!$product->isVisibleInCatalog()) {
            throw new GraphQlInputException(__('Please specify valid product'));
        }

        $configurableData = $this->getProductConfigurableData($product->getTypeId(), $productOption);

        try {
            $wishlistItem = $wishlist->addNewItem($product, $configurableData);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to save wishlist'));
        }

        // addNewItem() answers the type's error text instead of an item when the product is not buyable
        if (!$wishlistItem instanceof Item) {
            throw new GraphQlInputException(__('%1', (string)$wishlistItem));
        }

        $wishlistItem->setDescription($description);
        $wishlistItem->setQty($quantity);
        $this->saveWishlist($wishlist);

        if ($wishlistItem->getProductId() === null) {
            return [];
        }

        return array_merge(
            $wishlistItem->getData(),
            // the item's id key is wishlist_item_id and its data carries no sku, so both are mapped here
            [
                'id' => $wishlistItem->getId(),
                'sku' => $product->getSku(),
                'model' => $wishlistItem
            ],
            ['product' => array_merge(
                $wishlistItem->getProduct()->getData(),
                ['model' => $product]
            )]
        );
    }

    /**
     * @param Wishlist $wishlist
     * @param string $itemId
     * @param array $parameters
     * @return array
     * @throws GraphQlInputException
     * @throws GraphQlNoSuchEntityException
     */
    protected function updateWishlistItem(Wishlist $wishlist, string $itemId, array $parameters): array
    {
        if (!array_key_exists('quantity', $parameters) && !array_key_exists('description', $parameters)) {
            throw new GraphQlInputException(__('Please specify either quantity or description to update'));
        }

        // getItem() answers false for an empty id and null for a foreign one, so both reach one refusal
        $item = $wishlist->getItem((int)$itemId);

        if (!$item instanceof Item || (int)$item->getWishlistId() !== (int)$wishlist->getId()) {
            throw new GraphQlNoSuchEntityException(__(self::ITEM_NOT_FOUND));
        }

        if (array_key_exists('quantity', $parameters)) {
            $item->setQty($parameters['quantity']);
        }

        if (array_key_exists('description', $parameters)) {
            $item->setDescription($parameters['description']);
        }

        try {
            $item->save();
        } catch (Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to update wishlist item'));
        }

        $this->saveWishlist($wishlist);

        return array_merge(
            $item->getData(),
            [
                'id' => $item->getId(),
                'sku' => $item->getProduct()->getSku(),
                'model' => $item
            ]
        );
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
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to save wishlist'));
        }
    }

    /**
     * @param array $configurableOptions
     * @return array
     */
    protected function getOptionsArray(array $configurableOptions): array
    {
        $optionsArray = [];

        foreach ($configurableOptions as ['option_id' => $id, 'option_value' => $value]) {
            $optionsArray[(string)$id] = (int)$value;
        }

        return $optionsArray;
    }
}
