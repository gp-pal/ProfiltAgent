<?php

namespace App\Services;

/**
 * Guards against filename-like tokens that a broad email regex captures but that
 * are not real addresses — e.g. a PGP key reference `soc@energitra.lt.asc`, whose
 * final domain label is a file extension rather than a TLD. FILTER_VALIDATE_EMAIL
 * accepts these (they are syntactically valid), so a dedicated check is needed.
 */
final class EmailSanitizer
{
    /**
     * File extensions that show up appended to addresses in scraped page text
     * (PGP keys, asset filenames, download links). None is a real TLD in the
     * contexts this agent collects, so an address ending in one is a filename.
     *
     * @var array<int, string>
     */
    private const FILE_EXTENSION_LABELS = [
        'asc', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'ico',
        'pdf', 'zip', 'rar', 'gz', 'tar', 'doc', 'docx', 'xls', 'xlsx',
        'ppt', 'pptx', 'csv', 'txt', 'json', 'xml', 'html', 'htm', 'css',
        'js', 'mjs', 'map', 'woff', 'woff2', 'ttf', 'eot', 'mp4', 'mov',
        'webm', 'mp3', 'wav',
    ];

    /**
     * True when the address's final domain label is a file extension, so the
     * token is a filename that merely looks like an email rather than a real one.
     */
    public static function looksLikeFilename(string $email): bool
    {
        $host = strtolower((string) substr((string) strrchr($email, '@'), 1));
        $lastLabel = (string) substr((string) strrchr($host, '.'), 1);

        return in_array($lastLabel, self::FILE_EXTENSION_LABELS, true);
    }
}
