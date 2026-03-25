<?php
$docker_root = '/app/www/public/';

if (file_exists($docker_root.'include/common.inc.php'))
{
  define('PHPWG_ROOT_PATH', $docker_root);
}
else
{
  define('PHPWG_ROOT_PATH', '../../');
}

include(PHPWG_ROOT_PATH.'include/common.inc.php');

$autoload_path = dirname(__FILE__).'/vendor/autoload.php';
if (!file_exists($autoload_path))
{
  http_response_code(500);
  echo 'ZipStream dependency is missing';
  exit(0);
}
require_once($autoload_path);

use ZipStream\CompressionMethod;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

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
  global $conf, $pwg_loaded_plugins;

  $BatchDownloader = new BatchDownloader($_GET['set_id']);

  if ($BatchDownloader->getParam('nb_images') == 0)
  {
    throw new Exception('No images in this set');
  }

  $images = $BatchDownloader->getImages();
  $image_ids = array_keys($images);

  if (empty($image_ids))
  {
    throw new Exception('No images available for this set');
  }

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

  $set = $BatchDownloader->getNames();
  $zip_filename = (!empty($conf['batch_download']['archive_prefix']) ? $conf['batch_download']['archive_prefix'].'_' : '')
    . $set['BASENAME'].'.zip';

  // Hard server-side auto-cancel after 3 hours.
  @ini_set('max_execution_time', '10800');
  @set_time_limit(10800);
  $deadline = time() + 10800;
  while (ob_get_level() > 0)
  {
    ob_end_clean();
  }

  header('X-Accel-Buffering: no');

  $BatchDownloader->updateParam('status', 'download');
  $BatchDownloader->updateParam('date_creation', date('Y-m-d H:i:s'));

  trigger_notify('batchdownload_init_zip', $BatchDownloader->getParam('id'), $image_ids);

  $zip_entries = array();
  $total_size = 0;
  $images_added = array();

  foreach ($image_ids as $image_id)
  {
    if (time() >= $deadline)
    {
      throw new Exception('Streaming timeout');
    }

    if (!isset($images_data[$image_id]))
    {
      continue;
    }

    $row = $images_data[$image_id];

    if ($BatchDownloader->getParam('size') == 'original')
    {
      unset($row['representative_ext']);

      $file_path = PHPWG_ROOT_PATH.$row['path'];
      if (!file_exists($file_path))
      {
        continue;
      }

      $filename = $BatchDownloader->getFilename($row, $row);
      $zip_entries[] = array(
        'filename' => $filename,
        'path' => $file_path,
      );
      $total_size += $row['filesize'];
      $images_added[] = $image_id;
    }
    else
    {
      $src_image = new SrcImage($row);

      if (
        !$src_image->is_original()
        and (empty($row['representative_ext']) or !in_array(get_extension($row['path']), $conf['batch_download']['use_representative_for_ext']))
      )
      {
        unset($row['representative_ext']);

        $file_path = PHPWG_ROOT_PATH.$row['path'];
        if (!file_exists($file_path))
        {
          continue;
        }

        $filename = $BatchDownloader->getFilename($row, array());
        $zip_entries[] = array(
          'filename' => $filename,
          'path' => $file_path,
        );
        $total_size += $row['filesize'];
        $images_added[] = $image_id;
      }
      else
      {
        $derivative = new DerivativeImage($BatchDownloader->getParam('size'), $src_image);
        $deriv_path = $derivative->get_path();
        if (!file_exists($deriv_path))
        {
          continue;
        }

        $filesize_info = isset($filesizes[$row['id']]) ? $filesizes[$row['id']] : array();
        $filename = $BatchDownloader->getFilename($row, $filesize_info);
        $zip_entries[] = array(
          'filename' => $filename,
          'path' => $deriv_path,
        );

        if (isset($filesize_info['filesize']))
        {
          $total_size += $filesize_info['filesize'];
        }
        else
        {
          $total_size += @filesize($deriv_path)/1024;
        }
        $images_added[] = $image_id;
      }
    }
  }

  if (empty($zip_entries))
  {
    throw new Exception('No source files found to stream');
  }

  $zip = new ZipStream(
    operationMode: OperationMode::SIMULATE_LAX,
    outputName: $zip_filename,
    defaultCompressionMethod: CompressionMethod::STORE,
    defaultEnableZeroHeader: false,
    sendHttpHeaders: false,
    comment: 'Generated on '.date('r').' with PHP '.PHP_VERSION.' by Piwigo Batch Downloader (streaming).'
      . "\n".$conf['gallery_title'].' - '.get_absolute_root_url()
      . (!empty($conf['batch_download_comment']) ? "\n\n".wordwrap(remove_accents($conf['batch_download_comment']), 60) : '')
  );

  foreach ($zip_entries as $entry)
  {
    if (time() >= $deadline)
    {
      throw new Exception('Streaming timeout');
    }

    $zip->addFileFromPath(
      fileName: $entry['filename'],
      path: $entry['path']
    );
  }

  $zip_size = $zip->finish();

  $safe_name = trim(str_replace(array('"', "'", '\\', ';', "\n", "\r"), '', $zip_filename));
  $encoded_name = rawurlencode($safe_name);

  header('Content-Type: application/x-zip');
  header("Content-Disposition: attachment; filename*=UTF-8''{$encoded_name}");
  header('Pragma: public');
  header('Cache-Control: public, must-revalidate');
  header('Content-Transfer-Encoding: binary');
  header('Content-Length: '.$zip_size);

  $zip->executeSimulation();

  $BatchDownloader->updateParam('total_size', $total_size);
  $BatchDownloader->updateParam('nb_zip', 1);
  $BatchDownloader->updateParam('last_zip', 1);
  $BatchDownloader->updateParam('status', 'done');

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
  }
}

exit(0);
