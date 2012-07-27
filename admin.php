<?php
if (!defined('BATCH_DOWNLOAD_PATH')) die('Hacking attempt!');

global $template, $page, $conf;

define('BATCH_DOWNLOAD_PUBLIC',  make_index_url(array('section' => 'download')) . '/');

// tabsheet
include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');
$page['tab'] = (isset($_GET['tab'])) ? $_GET['tab'] : 'sets';
  
$tabsheet = new tabsheet();
$tabsheet->add('sets', l10n('Download history'), BATCH_DOWNLOAD_ADMIN . '-sets');
$tabsheet->add('config', l10n('Configuration'), BATCH_DOWNLOAD_ADMIN . '-config');
$tabsheet->select($page['tab']);
$tabsheet->assign();

if (!class_exists('ZipArchive'))
{
  array_push($page['errors'], l10n('Unable to find ZipArchive PHP extension, Batch Downloader can\'t work without this extension.'));
}

// include page
include(BATCH_DOWNLOAD_PATH . 'admin/' . $page['tab'] . '.php');

// template
$template->assign(array(
  'BATCH_DOWNLOAD_PATH' => BATCH_DOWNLOAD_PATH,
  'BATCH_DOWNLOAD_ADMIN' => BATCH_DOWNLOAD_ADMIN,
  ));
  
$template->assign_var_from_handle('ADMIN_CONTENT', 'batch_download');

?>