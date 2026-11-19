
<?php

/**
 * Validate and read an uploaded item photo.
 *
 * @param array|null $file Uploaded file data from $_FILES.
 * @return string|null Binary photo data, or null when no photo is uploaded.
 *
 * @throws RuntimeException When the uploaded photo is invalid.
 */
function processItemPhotoUpload(?array $file): ?string
{
    if ($file === null || !isset($file['error'])) {
        return null;
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'The photo upload failed. Please try again.'
        );
    }

    $maxBytes = 5 * 1024 * 1024;
    $allowedTypes = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    $temporaryPath = $file['tmp_name'] ?? '';

    if (
        !is_string($temporaryPath) ||
        $temporaryPath === '' ||
        !is_uploaded_file($temporaryPath)
    ) {
        throw new RuntimeException('Invalid uploaded photo.');
    }

    $fileSize = filesize($temporaryPath);

    if ($fileSize === false || $fileSize > $maxBytes) {
        throw new RuntimeException(
            'The maximum photo size is 5 MB.'
        );
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);

    if (!in_array($mimeType, $allowedTypes, true)) {
        throw new RuntimeException(
            'Only JPG, PNG, and WEBP images are allowed.'
        );
    }

    $photoData = file_get_contents($temporaryPath);

    if ($photoData === false) {
        throw new RuntimeException(
            'Unable to read the uploaded photo.'
        );
    }

    return $photoData;
}
