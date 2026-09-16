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

use Magento\Catalog\Api\ProductCustomOptionRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Size;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Wishlist\Model\Wishlist\BuyRequest\BuyRequestDataProviderInterface;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;
use ScandiPWA\WishlistGraphQl\Model\FileSupport\SanitizesUploadedFileNames;

class CustomizableOptionDataProvider implements BuyRequestDataProviderInterface
{
    use SanitizesUploadedFileNames;

    public const string PROVIDER_OPTION_TYPE = 'custom-option';
    public const string QUOTE_MEDIA_PATH = 'custom_options/quote/';
    public const string ORDER_MEDIA_PATH = 'custom_options/order/';

    // the media directory is group-readable so the web server can serve what the customer uploaded
    private const DIRECTORY_MODE = 0755;

    /**
     * @var string
     */
    protected $mediaPath;

    /**
     * @var WriteInterface
     */
    private WriteInterface $mediaDirectory;

    /**
     * @param Filesystem $filesystem
     * @param ProductCustomOptionRepositoryInterface $optionRepository
     * @param Size $fileSize
     * @throws FileSystemException
     */
    public function __construct(
        Filesystem $filesystem,
        private readonly ProductCustomOptionRepositoryInterface $optionRepository,
        private readonly Size $fileSize
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->mediaPath = $filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
    }

    /**
     * {@inheritdoc}
     * @throws GraphQlInputException
     * @throws LocalizedException
     */
    public function execute(WishlistItem $wishlistItem, ?int $productId): array
    {
        $customizableOptionsData = [];

        foreach ($wishlistItem->getSelectedOptions() as $optionData) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $optionData = \explode('/', base64_decode($optionData->getId()));

            if ($this->isProviderApplicable($optionData) === false) {
                continue;
            }

            $this->validateInput($optionData);

            [$optionType, $optionId, $optionValue] = $optionData;

            if ($optionType == self::PROVIDER_OPTION_TYPE) {
                $customizableOptionsData[$optionId][] = $optionValue;
            }
        }

        foreach ($wishlistItem->getEnteredOptions() as $option) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $optionData = \explode('/', base64_decode($option->getUid()));

            if ($this->isProviderApplicable($optionData) === false) {
                continue;
            }

            [$optionType, $optionId] = $optionData;

            if ($optionType !== self::PROVIDER_OPTION_TYPE) {
                continue;
            }

            $fileData = $this->getFileData(
                $option->getUid(),
                $option->getValue(),
                (string)$wishlistItem->getSku(),
                (string)$optionId
            );

            if ($fileData === false) {
                $customizableOptionsData[$optionId][] = $option->getValue();
                continue;
            }

            $fileData = $this->createFileAndFolder($option->getUid(), $fileData['raw'], $fileData);
            $customizableOptionsData[$optionId][] = $fileData;
        }

        if (empty($customizableOptionsData)) {
            return $customizableOptionsData;
        }

        $result = ['options' => $this->flattenOptionValues($customizableOptionsData)];

        if ($productId) {
            $result += ['product' => $productId];
        }

        return $result;
    }

    /**
     * the record kept for an upload, or false when the option value is not one
     * @param string $uid
     * @param string|null $optionData
     * @param string $sku
     * @param string $optionId
     * @return array|false
     * @throws GraphQlInputException
     * @throws LocalizedException
     */
    protected function getFileData($uid, $optionData, string $sku, string $optionId)
    {
        $data = json_decode((string)$optionData, true);

        if (!is_array($data)) {
            return false;
        }

        $filename = $data['file_name'] ?? '';
        $filedata = $data['file_data'] ?? '';

        if (!$filename && !$filedata) {
            return false;
        }

        // an upload with no usable name is refused rather than stored as the raw request payload
        if (!$filename || !$filedata) {
            throw new GraphQlInputException(__('The uploaded file must have a name and content.'));
        }

        // the client-supplied name is shown as the title only; the bytes go to a generated path
        $title = $this->sanitizeUploadedFileName((string)$filename, $this->optionExtensions($sku, $optionId));
        $storedName = $this->generateStoredFileName($title);
        $insidePath = $this->sanitizePathSegment((string)$uid) . '/_/' . $storedName;

        return [
            'type' => 'application/octet-stream',
            'title' => $title,
            'quote_path' => self::QUOTE_MEDIA_PATH . $insidePath,
            'order_path' => self::ORDER_MEDIA_PATH . $insidePath,
            'fullpath' => $this->mediaPath . self::QUOTE_MEDIA_PATH . $insidePath,
            'secret_key' => $this->buildSecretKey((string)$filedata),
            'raw' => $filedata,
            'stored_name' => $storedName
        ];
    }

    /**
     * writes the bytes and answers the record to store, its size and dimensions filled in from what
     * actually reached the disk and the keys the caller must not keep dropped
     * @param string $uid
     * @param string $value
     * @param array $fileData
     * @return array
     * @throws FileSystemException
     * @throws GraphQlInputException
     * @throws LocalizedException
     */
    public function createFileAndFolder($uid, $value, array $fileData): array
    {
        $bytes = $this->decodeUploadedFile((string)$value);

        if ($bytes === false) {
            throw new GraphQlInputException(__('The uploaded file could not be read.'));
        }

        $this->assertUploadedFileSize(strlen($bytes), (int)$this->fileSize->getMaxFileSize());

        $directory = sprintf(
            '%s%s/_',
            self::QUOTE_MEDIA_PATH,
            $this->sanitizePathSegment((string)$uid)
        );
        $path = $directory . '/' . $this->assertStorableFileName((string)$fileData['stored_name']);

        if (!$this->mediaDirectory->isDirectory($directory)) {
            $this->mediaDirectory->create($directory);
            $this->mediaDirectory->changePermissions($directory, self::DIRECTORY_MODE);
        }

        // writeFile() throws on a failed write, so a full disk is reported instead of a silent success
        $this->mediaDirectory->writeFile($path, $bytes);

        unset($fileData['raw'], $fileData['stored_name']);

        return array_merge($fileData, ['size' => strlen($bytes)], $this->imageDimensions($bytes));
    }

    /**
     * the key core re-derives from the stored file and compares the record against: it must be the
     * hash of the bytes, never their name, or a re-submitted option is dropped as never entered
     * @param string $value
     * @return string
     */
    protected function buildSecretKey(string $value): string
    {
        // an unreadable payload is refused by createFileAndFolder() moments later, so the empty-string
        // key this would build never reaches a stored record
        return substr(hash('sha256', (string)$this->decodeUploadedFile($value)), 0, 20);
    }

    /**
     * the bytes behind a data URI, false when the payload cannot be read; createFileAndFolder() writes
     * exactly these, so the secret key hashes what the file on disk ends up holding
     * @param string $value
     * @return string|false
     */
    protected function decodeUploadedFile(string $value): string|false
    {
        return base64_decode(substr($value, strpos($value, ',') + 1), true);
    }

    /**
     * the width and height core prints beside a file option, zero for anything that is not an image
     * @param string $bytes
     * @return array<string, int>
     */
    protected function imageDimensions(string $bytes): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $size = @getimagesizefromstring($bytes);

        return [
            'width' => (int)($size[0] ?? 0),
            'height' => (int)($size[1] ?? 0)
        ];
    }

    /**
     * the option's own file_extension list, narrowing the built-in one; empty when the option cannot be read
     * @param string $sku
     * @param string $optionId
     * @return string[]
     */
    protected function optionExtensions(string $sku, string $optionId): array
    {
        try {
            $declared = (string)$this->optionRepository->get($sku, (int)$optionId)->getFileExtension();
        } catch (NoSuchEntityException | LocalizedException) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($extension) => strtolower(trim($extension, " \t.")),
            explode(',', $declared)
        )));
    }

    /**
     * flatten option values for non-multiselect customizable options
     * @param array $customizableOptionsData
     * @return array
     */
    protected function flattenOptionValues(array $customizableOptionsData): array
    {
        foreach ($customizableOptionsData as $optionId => $optionValue) {
            if (count($optionValue) === 1) {
                $customizableOptionsData[$optionId] = $optionValue[0];
            }
        }

        return $customizableOptionsData;
    }

    /**
     * @param array $optionData
     * @return bool
     */
    protected function isProviderApplicable(array $optionData): bool
    {
        return ($optionData[0] ?? null) === self::PROVIDER_OPTION_TYPE;
    }

    /**
     * @param array $optionData
     * @return void
     * @throws LocalizedException
     */
    protected function validateInput(array $optionData): void
    {
        if (count($optionData) !== 3) {
            throw new LocalizedException(__('Wrong format of the entered option data'));
        }
    }
}
