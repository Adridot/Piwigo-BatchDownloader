<?php
define('PHPWG_ROOT_PATH', '/app/www/public/');
include(PHPWG_ROOT_PATH.'include/common.inc.php');

// Load ZipStream-PHP
require_once(dirname(__FILE__).'/vendor/autoload.php');

use ZipStream\ZipStream;
use ZipStream\CompressionMethod;

check_status(ACCESS_GUEST);

if (check_download_access() === false)
{
  http_response_code(403);
  echo 'Access denied';
  exit(0);
}

if (empty($_GET['set_id']) || !preg_match('#^[0-9]+$#', $_GET['set_id']))
{
  http_response_code(400);
  echo 'Invalid set_id';
  exit(0);
}

try
{
  $BatchDownloader = new BatchDownloader($_GET['set_id']);

  if ($BatchDownloader->getParam('nb_images') == 0)
  {
    throw new Exception('No images in this set');
  }

  // Limit number of elements
  $images = $BatchDownloader->getImages();
  $image_ids = array_slice(array_keys($images), 0, $conf['batch_download']['max_elements']);

  // Get image data
  global $pwg_loaded_plugins;

  $query = '
SELECT
    id, name, file, path,
    rotation, filesize, width, height,
    author, representative_ext';

  if (isset($pwg_loaded_plugins['expiry_date']))
  {
    $query .= ', expiry_date';
  }

  $query .= '
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;';
  $images_data = hash_from_query($query, 'id');

  // Get derivative sizes if not original
  $filesizes = array();
  if ($BatchDownloader->getParam('size') != 'original')
  {
    $query = '
SELECT image_id, filesize, width, height
  FROM '.IMAGE_SIZES_TABLE.'
  WHERE image_id IN ('.implode(',', $image_ids).')
    AND type = "'.$BatchDownloader->getParam('size').'"
;';
    $filesizes = hash_from_query($query, 'image_id');
  }

  // Get set names for the ZIP filename
  $set = $BatchDownloader->getNames();
  $zip_filename = (!empty($conf['batch_download']['archive_prefix']) ? $conf['batch_download']['archive_prefix'] . '_' : '')
    . $set['BASENAME'] . '.zip';

  // Disable time limit
  set_time_limit(0);

  // Disable output buffering
  while (ob_get_level()) {
    ob_end_clean();
  }

  // Disable Nginx buffering
  header('X-Accel-Buffering: no');

  // Update status
  $BatchDownloader->updateParam('status', 'download');
  $BatchDownloader->updateParam('date_creation', date('Y-m-d H:i:s'));

  trigger_notify('batchdownload_init_zip', $BatchDownloader->getParam('id'), $image_ids);

  // Create streaming ZIP
  $zip = new ZipStream(
    outputName: $zip_filename,
    defaultCompressionMethod: CompressionMethod::STORE,
    sendHttpHeaders: true,
    comment: 'Generated on '.date('r').' with PHP '.PHP_VERSION.' by Piwigo Batch Downloader (streaming).'
      . "\n" . $conf['gallery_title'] . ' - ' . get_absolute_root_url()
      . (!empty($conf['batch_download_comment']) ? "\n\n" . wordwrap(remove_accents($conf['batch_download_comment']), 60) : '')
  );

  $total_size = 0;
  $images_added = array();

  foreach ($image_ids as $image_id)
  {
    if (!isset($images_data[$image_id])) continue;
    $row = $images_data[$image_id];

    if (!file_exists(PHPWG_ROOT_PATH.$row['path'])) continue;

    if ($BatchDownloader->getParam('size') == 'original')
    {
      unset($row['representative_ext']);
      $file_path = PHPWG_ROOT_PATH.$row['path'];
      $filename = $BatchDownloader->getFilename($row, $row);
      $zip->addFileFromPath(fileName: $filename, path: $file_path);
      $total_size += $row['filesize'];
    }
    else
    {
      $src_image = new SrcImage($row);

      if (
        !$src_image->is_original()
        && (empty($row['representative_ext']) || !in_array(get_extension($row['path']), $conf['batch_download']['use_representative_for_ext']))
      )
      {
        unset($row['representative_ext']);
        $file_path = PHPWG_ROOT_PATH.$row['path'];
        $filename = $BatchDownloader->getFilename($row, array());
        $zip->addFileFromPath(fileName: $filename, path: $file_path);
        $total_size += $row['filesize'];
      }
      else
      {
        $derivative = new DerivativeImage($BatchDownloader->getParam('size'), $src_image);
        $deriv_path = $derivative->get_path();

        if (!file_exists($deriv_path)) continue;

        $filesize_info = isset($filesizes[$row['id']]) ? $filesizes[$row['id']] : array();
        $filename = $BatchDownloader->getFilename($row, $filesize_info);
        $zip->addFileFromPath(fileName: $filename, path: $deriv_path);
        if (isset($filesizes[$row['id']]['filesize'])) {
          $total_size += $filesizes[$row['id']]['filesize'];
        }
      }
    }

    $images_added[] = $image_id;
  }

  $zip->finish();

  // Mark set as done
  $BatchDownloader->updateParam('total_size', $total_size);
  $BatchDownloader->updateParam('nb_zip', 1);
  $BatchDownloader->updateParam('last_zip', 1);
  $BatchDownloader->updateParam('status', 'done');

  // Update all images as belonging to zip 1
  if (!empty($images_added))
  {
    $query = '
UPDATE '.BATCH_DOWNLOAD_TIMAGES.'
  SET zip = 1
  WHERE
    set_id = '.$BatchDownloader->getParam('id').'
    AND image_id IN('.implode(',', $images_added).')
;';
    pwg_query($query);
  }

  trigger_notify('batchdownload_end_zip', $BatchDownloader->getParam('id'), $images_added);
}
catch (Exception $e)
{
  if (!headers_sent())
  {
    http_response_code(500);
    echo $e->getMessage();
  }
}

exit(0);
