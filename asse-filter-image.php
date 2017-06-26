<?php

// @codingStandardsIgnoreFile

use Asse\Plugin\AsseHelpers\HelperFactory;
use Asse\Plugin\AsseImporter\ImporterFields;

/**
 * Unsets the default image sizes of WordPress.
 *
 * @wp-hook intermediate_image_sizes_advanced
 * @param $sizes
 * @return mixed
 */
function unsetDefaultImageSizes($sizes)
{
    // The size 'thumbnail' has to be generated due to its usage in media browsing
//    unset($sizes['thumbnail']);
    // The size 'medium' has to be generated due to its usage in media library
//    unset($sizes['medium']);
    unset($sizes['large']);

    return $sizes;
}
add_filter('intermediate_image_sizes_advanced', 'unsetDefaultImageSizes');

/**
 * Shows image sizes in WordPress selection.
 *
 * @wp-hook image_size_names_choose
 * @param $sizes
 * @return mixed
 */
function showImageSizes($sizes)
{
    $imageSizes = get_intermediate_image_sizes();
    foreach ($imageSizes as $imageSize) {
        $sizes[$imageSize] = __($imageSize);
    }

    return $sizes;
}
add_filter('image_size_names_choose', 'showImageSizes');

/**
 * Reads image metadata to be stored into custom fields.
 *
 * @wp-hook wp_read_image_metadata
 * @param $imageMetadata
 * @param $file
 * @return mixed
 */
function readImageMetadata($imageMetadata, $file)
{
    // Get IPTC fields and store them into $imageMetadata for further custom field storage
    if (is_callable('iptcparse')) {
        getimagesize($file, $info);

        if (!empty($info['APP13'])) {
            $iptc = iptcparse($info['APP13']);

            // creator / legacy byline
            if (!empty($iptc['2#080'][0])) {
                $imageMetadata['IPTC2:80'] = trim($iptc['2#080'][0]);
            }
            // credit
            if (!empty($iptc['2#110'][0])) {
                $imageMetadata['IPTC2:110'] = trim($iptc['2#110'][0]);
            }
            // copyright (custom)
            if (!empty($iptc['2#122'][0])) {
                $imageMetadata['IPTC2:122'] = trim($iptc['2#122'][0]);
            }
        }
    }

    return $imageMetadata;
}
add_filter('wp_read_image_metadata', 'readImageMetadata', 10, 2);

/**
 * Saves a backup of the original media file and sets specified IPTC fields.
 *
 * @wp-hook wp_generate_attachment_metadata
 * @param $attachmentMetadata
 * @param $attachmentId
 * @return mixed
 */
function processAttachmentMetadata($attachmentMetadata, $attachmentId)
{
    if (isset($attachmentMetadata['image_meta'])) {
        $data = array();

        $data[ImporterFields::getMediaIsArchive()] = '0';
        if (isset($attachmentMetadata['image_meta']['IPTC2:80'])) {
            $data[ImporterFields::getMediaAuthor()] = $attachmentMetadata['image_meta']['IPTC2:80'];
        }
        if (isset($attachmentMetadata['image_meta']['IPTC2:122'])) {
            $data[ImporterFields::getMediaCopyright()] = $attachmentMetadata['image_meta']['IPTC2:122'];
        }
        if (isset($attachmentMetadata['image_meta']['IPTC2:110'])) {
            $data[ImporterFields::getMediaAgency()] = $attachmentMetadata['image_meta']['IPTC2:110'];
        }

        if (!empty($data)) {
            HelperFactory::get('pods')->savePodFields($data, $attachmentId, 'media');
        }
    }

    if (isset($attachmentMetadata['file'])) {
        $uploadDir = wp_upload_dir();

        // Read original file with all IPTC fields
        $filePathOriginal = $uploadDir['basedir'] . '/' . $attachmentMetadata['file'];

        // Copy original file to use as backup
        $pathInfo = pathinfo($filePathOriginal);
        $filePathOriginalNew =
            $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_' . 'original' . '.' . $pathInfo['extension'];
        copy($filePathOriginal, $filePathOriginalNew);

        // Unfortunately we have to read and then write the file to get rid of all IPTC fields.
        // This file replaces the original file which was backed up before.
        $image = wp_get_image_editor($filePathOriginal);
        if (!is_wp_error($image)) {
            $image->save($filePathOriginal);
        } else {
            return $attachmentMetadata;
        }

        // Set IPTC fields from backup file to new original file
        $fileContentModified = HelperFactory::get('image')->getIptcFileContent($filePathOriginalNew, $filePathOriginal);
        if (!empty($fileContentModified)) {
            file_put_contents($filePathOriginal, $fileContentModified);
        }

        $imageSizes = get_intermediate_image_sizes();
        foreach ($imageSizes as $imageSize) {
            if (isset($attachmentMetadata['sizes'][$imageSize])) {
                // Thumbnails generated by Wordpress do not have IPTC fields so use directly.
                $filePathModified = $uploadDir['path'] . '/' . $attachmentMetadata['sizes'][$imageSize]['file'];

                // Set IPTC fields from backup file to resized file
                $content = HelperFactory::get('image')->getIptcFileContent($filePathOriginalNew, $filePathModified);
                if (!empty($content)) {
                    file_put_contents($filePathModified, $content);
                }
            }
        }
    }

    return $attachmentMetadata;
}
// quick fix
if ( ! defined( 'WP_CLI') && ! class_exists( 'WP_CLI' ) ) {
    add_filter('wp_generate_attachment_metadata', 'processAttachmentMetadata', 10, 2);
}

/**
 * Return jpeg quality set by parameters.yml constant
 * @return int
 */
function asseJpegQuality()
{
    if (defined('ASSE_JPEG_QUALITY')) {
        return ASSE_JPEG_QUALITY;
    } else {
        return 90; //default wordpress quality
    }
}
// change quality via filter
add_filter('wp_editor_set_quality', 'asseJpegQuality');

// allow responsive images in wp4.4?
// http://wordpress.stackexchange.com/questions/211375/how-do-i-disable-responsive-images-in-wp-4-4
if (defined('ASSE_DISABLE_SRCSET_IMAGES') && ASSE_DISABLE_SRCSET_IMAGES == true) {
    add_filter( 'wp_calculate_image_srcset_meta', '__return_null' );
}
