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

use Magento\Bundle\Helper\Catalog\Product\Configuration as BundleOptions;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Helper\Product\Configuration as ProductOptions;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\CollectionProcessorInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Downloadable\Helper\Catalog\Product\Configuration as DownloadableOptions;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory as WishlistItemCollectionFactory;
use Magento\Wishlist\Model\Wishlist;
use ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor;
use ScandiPWA\Performance\Model\Resolver\ResolveInfoFieldsTrait;

class WishlistItemsResolver implements ResolverInterface
{
    use ResolveInfoFieldsTrait;

    /**
     * @param WishlistItemCollectionFactory $wishlistItemsFactory
     * @param StoreManagerInterface $storeManager
     * @param ProductResource $productResource
     * @param DataPostProcessor $productPostProcessor
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CollectionProcessorInterface $collectionProcessor
     * @param ProductCollectionFactory $collectionFactory
     * @param ProductOptions $productOptions
     * @param BundleOptions $bundleOptions
     * @param DownloadableOptions $downloadableOptions
     */
    public function __construct(
        private readonly WishlistItemCollectionFactory $wishlistItemsFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductResource $productResource,
        private readonly DataPostProcessor $productPostProcessor,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ProductCollectionFactory $collectionFactory,
        private readonly ProductOptions $productOptions,
        private readonly BundleOptions $bundleOptions,
        private readonly DownloadableOptions $downloadableOptions
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
        if (!isset($value['model'])) {
            return null;
        }

        /** @var Wishlist $wishlist */
        $wishlist = $value['model'];
        $wishlistItems = $this->getWishListItems($wishlist);
        $itemProductIds = [];

        foreach ($wishlistItems as $item) {
            $itemProductIds[$item->getId()] = $item->getProductId();
        }

        $wishlistProducts = $this->getWishlistProducts(
            $itemProductIds,
            $info
        );

        $variantSkus = $this->getVariantSkus($wishlistItems);

        $data = [];

        foreach ($wishlistItems as $wishlistItem) {
            $wishlistItemId = $wishlistItem->getId();
            $wishlistProductId = $itemProductIds[$wishlistItemId];

            if (!isset($wishlistProducts[$wishlistProductId])) {
                continue;
            }

            $itemProduct = $wishlistProducts[$wishlistProductId];
            $type = $itemProduct['type_id'];
            $qty = $wishlistItem->getData('qty');

            $buyRequestOption = $wishlistItem->getOptionByCode('info_buyRequest');
            $options = $this->getItemOptions($wishlistItem, $type);
            $amount = $this->getItemAmount($wishlistItem, (float)$qty);

            $data[] = [
                'id' => $wishlistItemId,
                'qty' => $qty,
                'sku' => $this->getWishListItemSku($wishlistItem, $variantSkus),
                'price' => $amount->getValue(),
                'price_without_tax' => $amount->getValue('tax'),
                'buy_request' => $this->getPublicBuyRequest($buyRequestOption?->getValue() ?? ''),
                'description' => $wishlistItem->getDescription(),
                'added_at' => $wishlistItem->getAddedAt(),
                'model' => $wishlistItem,
                'product' => $itemProduct,
                'options' => $options
            ];
        }

        return $data;
    }

    /**
     * the price a customer would pay for this line, which is the item's own quantity worth of it:
     * a tier price that starts at qty 2 is what a qty-2 wish list item costs
     * @param Item $wishlistItem
     * @param float $qty
     * @return AmountInterface
     */
    protected function getItemAmount(Item $wishlistItem, float $qty): AmountInterface
    {
        // the repository hands out shared product instances, so the quantity is set on a copy
        $product = clone $wishlistItem->getProduct();
        $product->setQty($qty > 0 ? $qty : 1);

        return $product->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getAmount();
    }

    /**
     * the buy request as a reader may see it. `fullpath` is an absolute filesystem path, describes the
     * server rather than the order and is read by nothing, so it never leaves. `secret_key` stays: the
     * theme re-submits the whole record and core compares the key against the stored file, and the web
     * server denies `quote_path`, so the key opens nothing a sharing-code reader could not already read.
     * @param string $buyRequest
     * @return string
     */
    protected function getPublicBuyRequest(string $buyRequest): string
    {
        $data = json_decode($buyRequest, true);

        if (!is_array($data) || !isset($data['options']) || !is_array($data['options'])) {
            return $buyRequest;
        }

        foreach ($data['options'] as $optionId => $optionValue) {
            if (is_array($optionValue) && isset($optionValue['quote_path'])) {
                unset($optionValue['fullpath']);
                $data['options'][$optionId] = $optionValue;
            }
        }

        return (string)json_encode($data);
    }

    /**
     * @param Item $item
     * @param string $type
     * @return array
     */
    protected function getItemOptions($item, $type)
    {
        $options = [];
        switch ($type) {
            case BundleType::TYPE_CODE:
                $options = $this->bundleOptions->getOptions($item);
                break;
            case DownloadableType::TYPE_DOWNLOADABLE:
                $options = $this->downloadableOptions->getOptions($item);
                break;
            default:
                $options = $this->productOptions->getOptions($item);

                // Magento produce HTML markup as label for files. We need plain name of the file instead.
                foreach ($options as $index => $option) {
                    if (isset($option['option_type']) && $option['option_type'] == 'file') {
                        $options[$index]['value'] = $option['print_value'];
                    }
                }

                return $options;
        }

        $output = [];
        foreach ($options as $option) {
            $value = is_array($option['value']) ?
                join(', ', $option['value']) :
                $option['value'];

            $output[] = [
                'label' => $option['label'],
                'value' => strip_tags($value)
            ];
        }
        return $output;
    }

    /**
     * collect wishlist item products
     * @param array $itemProductIds
     * @param ResolveInfo $info
     * @return array
     */
    protected function getWishlistProducts(
        array $itemProductIds,
        ResolveInfo $info
    ) {
        $collection = $this->collectionFactory->create();
        $collection->addIdFilter(array_values($itemProductIds));

        $this->collectionProcessor->process(
            $collection,
            $this->searchCriteriaBuilder->create(),
            $this->getFieldsFromProductInfo($info, 'items/product')
        );

        $items = $collection->getItems();

        return $this->productPostProcessor->process(
            $items,
            'items/product',
            $info
        );
    }

    /**
     * get wish-list items
     * @param Wishlist $wishlist
     * @return Item[]
     */
    protected function getWishListItems(
        Wishlist $wishlist
    ): array {
        $collection = $this->wishlistItemsFactory->create();
        $collection
            ->addWishlistFilter($wishlist)
            ->addStoreFilter(array_map(function (StoreInterface $store) {
                return $store->getId();
            }, $this->storeManager->getStores()))
            ->setVisibilityFilter();

        return $collection->getItems();
    }

    /**
     * batch-load the SKUs of selected configurable variants in one query
     * @param Item[] $wishlistItems
     * @return array<int, string> variant product id => sku
     * @throws LocalizedException
     */
    protected function getVariantSkus(array $wishlistItems): array
    {
        $variantIds = [];

        foreach ($wishlistItems as $wishlistItem) {
            if ($wishlistItem->getProduct()->getTypeId() !== ConfigurableType::TYPE_CODE) {
                continue;
            }

            $productOption = $wishlistItem->getOptionByCode('simple_product');

            if ($productOption && $productOption->getValue()) {
                $variantIds[] = (int)$productOption->getValue();
            }
        }

        if (!$variantIds) {
            return [];
        }

        $variantSkus = [];

        foreach ($this->productResource->getProductsSku($variantIds) as $row) {
            $variantSkus[(int)$row['entity_id']] = $row['sku'];
        }

        return $variantSkus;
    }

    /**
     * get wish-list item's sku
     * @param Item $wishlistItem
     * @param array<int, string> $variantSkus
     * @return string
     * @throws LocalizedException
     */
    protected function getWishListItemSku(
        Item $wishlistItem,
        array $variantSkus
    ): string {
        $product = $wishlistItem->getProduct();

        if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {
            $productOption = $wishlistItem->getOptionByCode('simple_product');

            if ($productOption && isset($variantSkus[(int)$productOption->getValue()])) {
                return $variantSkus[(int)$productOption->getValue()];
            }
        }

        return $product->getSku();
    }
}
