<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_WishlistGraphQl
 * @copyright   Copyright 2020 Adobe. All Rights Reserved.
 * @copyright   Copyright © Scandiweb, Inc. All rights reserved.
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\FileSupport\BuyRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Wishlist\Model\Wishlist\BuyRequest\BuyRequestDataProviderInterface;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;

class BundleDataProvider implements BuyRequestDataProviderInterface
{
    public const string PROVIDER_OPTION_TYPE = 'bundle';

    // bundle/<option id>/<selection id>/<quantity>
    public const int BUNDLE_OPTION_DATA_COUNT = 4;

    /**
     * {@inheritdoc}
     * @throws LocalizedException
     */
    public function execute(WishlistItem $wishlistItem, ?int $productId): array
    {
        $bundleOptionsData = [];

        foreach ($wishlistItem->getSelectedOptions() as $option) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $this->collectOption($bundleOptionsData, base64_decode($option->getId()), null);
        }

        // a chosen quantity arrives as an entered option, where the value is the quantity, not the uid's last segment
        foreach ($wishlistItem->getEnteredOptions() as $option) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $this->collectOption($bundleOptionsData, base64_decode($option->getUid()), $option->getValue());
        }

        return $bundleOptionsData;
    }

    /**
     * core's BundleDataProvider assigns one selection and one quantity per option id, and
     * Magento\Bundle\Model\Product\Type::getQty() reads the quantity as a scalar: wrapped in an array
     * it casts to 1 and the customer's chosen quantity is lost
     * @param array $bundleOptionsData
     * @param string $decodedUid
     * @param string|null $enteredQuantity
     * @return void
     * @throws LocalizedException
     */
    protected function collectOption(array &$bundleOptionsData, string $decodedUid, ?string $enteredQuantity): void
    {
        $optionData = \explode('/', $decodedUid);

        if ($this->isProviderApplicable($optionData) === false) {
            return;
        }

        $this->validateInput($optionData);

        [, $optionId, $optionValueId, $encodedQuantity] = $optionData;

        $bundleOptionsData['bundle_option'][$optionId] = $optionValueId;
        $bundleOptionsData['bundle_option_qty'][$optionId] = $enteredQuantity ?? $encodedQuantity;
    }

    /**
     * validates the provided options structure
     * @param array $optionData
     * @return void
     * @throws LocalizedException
     */
    protected function validateInput(array $optionData): void
    {
        if (count($optionData) !== self::BUNDLE_OPTION_DATA_COUNT) {
            throw new LocalizedException(__('Wrong format of the entered option data'));
        }
    }

    /**
     * checks whether this provider is applicable for the current option
     * @param array $optionData
     * @return bool
     */
    protected function isProviderApplicable(array $optionData): bool
    {
        return ($optionData[0] ?? null) === self::PROVIDER_OPTION_TYPE;
    }
}
