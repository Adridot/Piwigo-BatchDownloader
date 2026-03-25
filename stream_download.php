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

$autoload_candidates = array(
  dirname(__FILE__).'/vendor/autoload.php',
  PHPWG_ROOT_PATH.'plugins/BatchDownloader/vendor/autoload.php',
  dirname(__FILE__).'/../BatchDownloader/vendor/autoload.php',
);
$zipstream_available = false;
foreach ($autoload_candidates as $autoload_path)
{
  if (file_exists($autoload_path))
  {
    require_once($autoload_path);
    $zipstream_available = class_exists('ZipStream\\ZipStream');
    if ($zipstream_available)
    {
      break;
    }
  }
}

use ZipStream\CompressionMethod;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

check_status(ACCESS_GUEST);

function batch_download_redirect_to_native_flow($set_id)
{
  $script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
  $native_path = preg_replace(
    '#/plugins/BatchDownloader/[^/]+$#',
    '/index.php?/download/init_zip',
    $script_name
  );

  if (empty($native_path) || $native_path === $script_name)
  {
    $native_path = '/index.php?/download/init_zip';
  }

  $native_url = add_url_params($native_path, array('set_id' => $set_id));
  redirect($native_url);
}

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

  if (empty($conf['batch_download']['direct_stream_download']))
  {
    throw new Exception('Direct stream mode is disabled');
  }

  $BatchDownloader = new BatchDownloader($_GET['set_id']);

  if (!$zipstream_available)
  {
    // Fallback to native zip generation workflow.
    batch_download_redirect_to_native_flow($BatchDownloader->getParam('id'));
  }

  if ($BatchDownloader->getParam('nb_images') == 0)
  {
    throw new Exception('No images in this set');
  }

  // Avoid long-running stream requests that are likely to be terminated by FPM.
  if (
    $BatchDownloader->getParam('size') != 'original'
    || $BatchDownloader->getEstimatedArchiveNumber() > 1
  )
  {
    batch_download_redirect_to_native_flow($BatchDownloader->getParam('id'));
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
    operationMode: OperationMode::NORMAL,
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

  $safe_name = trim(str_replace(array('"', "'", '\\', ';', "\n", "\r"), '', $zip_filename));
  $encoded_name = rawurlencode($safe_name);

  header('Content-Type: application/x-zip');
  header("Content-Disposition: attachment; filename*=UTF-8''{$encoded_name}");
  header('Pragma: public');
  header('Cache-Control: public, must-revalidate');
  header('Content-Transfer-Encoding: binary');

  $zip->finish();

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
catch (Throwable $e)
{
  if (!headers_sent())
  {
    http_response_code(500);
  }
}

exit(0);
