<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_WishlistGraphQl
 * @copyright   Copyright © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\FileSupport;

use Magento\Framework\Exception\LocalizedException;

trait SanitizesUploadedFileNames
{
    // a base64 upload never reaches core's $_FILES validator, so this is the only barrier before the filesystem
    protected const ALLOWED_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif', 'heic', 'tif', 'tiff',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'rtf', 'txt', 'csv', 'zip', 'rar', '7z', 'gz', 'tar',
        'ai', 'eps', 'psd', 'dxf',
    ];

    // the title is shown back to the customer, so it is bounded the way an option title is
    protected const TITLE_MAX_LENGTH = 255;

    /**
     * reduce a client-supplied name to a bare, allow-listed file name fit to show as a title
     * @param string $name
     * @param string[] $optionExtensions extensions the product option itself declares, narrowing the list
     * @return string
     * @throws LocalizedException
     */
    protected function sanitizeUploadedFileName(string $name, array $optionExtensions = []): string
    {
        // Windows separators count as directory parts too
        $title = basename(str_replace('\\', '/', $name));

        if ($title === '' || $title === '.' || $title === '..' || str_contains($title, "\0")) {
            throw new LocalizedException(__('The uploaded file name is not valid.'));
        }

        $extension = $this->uploadedFileExtension($title);

        if ($extension === '') {
            throw new LocalizedException(__('The uploaded file must have a file extension.'));
        }

        if (!in_array($extension, $this->acceptedUploadExtensions($optionExtensions), true)) {
            throw new LocalizedException(
                __('Files with the "%1" extension are not allowed.', $extension)
            );
        }

        return mb_substr($title, 0, static::TITLE_MAX_LENGTH);
    }

    /**
     * the stored name is generated, so a double extension such as evil.php.jpg never reaches disk
     * @param string $title
     * @return string
     * @throws \Random\RandomException
     */
    protected function generateStoredFileName(string $title): string
    {
        $extension = $this->uploadedFileExtension($title);
        $name = bin2hex(random_bytes(16));

        return $extension === '' ? $name : $name . '.' . $extension;
    }

    /**
     * guard a value used as a directory component of the upload path
     * @param string $segment
     * @return string
     * @throws LocalizedException
     */
    protected function sanitizePathSegment(string $segment): string
    {
        // quote ids are numeric and option uids base64, so `..` and absolute paths are unrepresentable here
        if (!preg_match('#^[A-Za-z0-9+/=_-]{1,255}$#', $segment)) {
            throw new LocalizedException(__('The uploaded file could not be stored.'));
        }

        return $segment;
    }

    /**
     * last line of defence for the public write helpers, which may be called directly
     * @param string $name
     * @return string
     * @throws LocalizedException
     */
    protected function assertStorableFileName(string $name): string
    {
        $extension = $this->uploadedFileExtension($name);

        if (!preg_match('#^[A-Za-z0-9_-]+(\.[A-Za-z0-9]+)?$#', $name)
            || ($extension !== '' && !in_array($extension, static::ALLOWED_UPLOAD_EXTENSIONS, true))
        ) {
            throw new LocalizedException(__('The uploaded file could not be stored.'));
        }

        return $name;
    }

    /**
     * this path carries the bytes in the request body, so nothing bounds them but this check
     * @param int $bytes
     * @param int $maxBytes
     * @return void
     * @throws LocalizedException
     */
    protected function assertUploadedFileSize(int $bytes, int $maxBytes): void
    {
        if ($bytes <= 0) {
            throw new LocalizedException(__('The uploaded file is empty.'));
        }

        if ($maxBytes > 0 && $bytes > $maxBytes) {
            throw new LocalizedException(
                __('The uploaded file is larger than the %1 byte limit.', $maxBytes)
            );
        }
    }

    /**
     * @param string[] $optionExtensions
     * @return string[]
     */
    private function acceptedUploadExtensions(array $optionExtensions): array
    {
        if (!$optionExtensions) {
            return static::ALLOWED_UPLOAD_EXTENSIONS;
        }

        // the option narrows the built-in list, it never widens it: an option naming php still stores no php
        return array_values(array_intersect(static::ALLOWED_UPLOAD_EXTENSIONS, $optionExtensions));
    }

    /**
     * @param string $name
     * @return string
     */
    private function uploadedFileExtension(string $name): string
    {
        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    }
}
