{combine_css path=$BATCH_DOWNLOAD_PATH|cat:"template/style.css"}

{if $set}
<div class="download-container">

  <!-- Bloc 1 : Titre + infos -->
  <div class="download-title-block">
    <h2>
      Téléchargement de l'{$set.NAME}
    </h2>
    {*
      Conversion de la taille totale en partant d'une valeur en "Mio"
      (1 Mio = 1,048,576 octets) vers des Mo (base 10, 1 Mo = 1,000,000 octets)
    *}
    {assign var="rawSize" value=$set.TOTAL_SIZE|regex_replace:"/[^0-9\.]/":""}
    {assign var="bytesAlbum" value=$rawSize * 1048576}
    {assign var="moBase10" value=$bytesAlbum / 1000000}
    {if $moBase10 >= 1000}
      {assign var="goBase10" value=$moBase10 / 1000}
      {assign var="albumDisplaySize" value=$goBase10|string_format:"%.2f"}
      {assign var="albumDisplayUnit" value="Go"}
    {else}
      {assign var="albumDisplaySize" value=$moBase10|string_format:"%.2f"}
      {assign var="albumDisplayUnit" value="Mo"}
    {/if}

    <p class="album-stats">
      <span>{$set.NB_IMAGES} photos</span> –
      <span>{$albumDisplaySize} {$albumDisplayUnit}</span>
    </p>
  </div>

{if $use_streaming}
  <!-- MODE STREAMING -->
  <div class="download-streaming-block">
    <p class="streaming-info">
      <strong>Votre téléchargement est prêt.</strong><br>
      Le fichier ZIP sera généré et téléchargé directement.<br>
      Le téléchargement va commencer automatiquement dans quelques secondes.
    </p>
    
    <div class="streaming-action">
      <a href="{$set.U_STREAM}" class="big-download-button">
        <span class="icon-download"></span> Télécharger l'album complet
      </a>
    </div>
  </div>

  <script type="text/javascript">
  setTimeout(function() {
    window.location.href = '{$set.U_STREAM}';
  }, 1500);
  </script>

{else}
  <!-- MODE ARCHIVES MULTIPLES -->
  <div class="download-instructions-block">
    <p>
      <strong>Votre téléchargement est prêt.</strong><br>
      En raison de la taille conséquente du dossier de photos, celui-ci a été divisé en plusieurs archives.<br>
    </p>
    <ul class="download-instructions-list">
      <li>Chaque archive peut peser jusqu'à <strong>800 Mo</strong>.</li>
      <li>La compression sur le serveur et le téléchargement peuvent prendre quelques minutes.</li>
      <li>Vous pouvez lancer le téléchargement de toutes les archives automatiquement.</li>
    </ul>
    
    <div class="download-all-container">
      <button id="btn-download-all" onclick="startDownloadAll()" class="download-all-button">
        Tout télécharger automatiquement
      </button>
    </div>
  </div>

  <!-- Bloc 3 : Archives + bouton d’annulation -->
  <div class="download-archives-block">
    {assign var="zip_links" value=$zip_links|default:[]}
    {if $zip_links|@count > 0}
      <div class="archives-grid">
        {foreach from=$zip_links item=archive}
          {*
            Conversion de la taille de chaque archive.
          *}
          {assign var="bytesArchive" value=$archive.size_ko * 1024}
          {assign var="moArchive" value=$bytesArchive / 1000000}
          {if $moArchive >= 1000}
            {assign var="goArchive" value=$moArchive / 1000}
            {assign var="archiveDisplayUnit" value="Go"}
            {assign var="archiveDisplaySize" value=$goArchive|string_format:"%.2f"}
          {else}
            {assign var="archiveDisplayUnit" value="Mo"}
            {assign var="archiveDisplaySize" value=$moArchive|string_format:"%.2f"}
          {/if}

          <div class="archive-card" id="archive-card-{$archive.id}" data-url="{$archive.url}" data-status="{$archive.status}">
            <div class="archive-header">
              <img src="{$archive.icon}" alt="" class="archive-icon">
              <span class="archive-title">Archive #{$archive.id}</span>
            </div>
            <div class="archive-size">
              {if $archive.size_estimated}
                ~{$archiveDisplaySize} {$archiveDisplayUnit}
              {else}
                {$archiveDisplaySize} {$archiveDisplayUnit}
              {/if}
            </div>
            <div class="archive-action">
              {if $archive.status == 'ready' or $archive.status == 'pending'}
                <a href="{$archive.url}" class="archive-button" target="download_frame_{$archive.id}">
                  Télécharger
                </a>
              {elseif $archive.status == 'downloaded'}
                <span class="archive-downloaded">Téléchargé</span>
              {/if}
              <div class="progress-bar-bg" style="display:none;">
                 <div class="progress-bar-fill" style="width:0%"></div>
              </div>
            </div>
          </div>
        {/foreach}
      </div>
    {else}
      <p>Aucune archive n'est disponible pour le moment.</p>
    {/if}

    {if $set.U_CANCEL|default:null}
      <div class="cancel-download">
        <a href="{$set.U_CANCEL}" class="cancel-down" onClick="return confirm('Etes-vous sûr ?');">
          Annuler ce téléchargement
        </a>
      </div>
    {/if}
  </div>
  
  <div id="hidden-iframes" style="display:none;"></div>

  <script type="text/javascript">
  {literal}
  var archivesToDownload = [];
  var currentDownloadIndex = 0;

  function startDownloadAll() {
    archivesToDownload = [];
    $('.archive-card').each(function() {
      var status = $(this).data('status');
      if (status == 'ready' || status == 'pending') {
         archivesToDownload.push($(this).attr('id'));
      }
    });
    
    if (archivesToDownload.length > 0) {
      $('#btn-download-all').prop('disabled', true).text('Téléchargement en cours...');
      currentDownloadIndex = 0;
      processNextDownload();
    }
  }

  function processNextDownload() {
    if (currentDownloadIndex >= archivesToDownload.length) {
       $('#btn-download-all').text('Téléchargements terminés');
       return;
    }
    
    var cardId = archivesToDownload[currentDownloadIndex];
    var $card = $('#' + cardId);
    var url = $card.data('url');
    var archiveId = cardId.replace('archive-card-', '');
    
    // Update UI
    $card.find('.archive-button').hide();
    $card.find('.progress-bar-bg').show();
    $card.find('.progress-bar-fill').animate({width: '100%'}, 2000); // Fake progress for visual feedback
    
    // Create iframe
    var iframeId = 'download_frame_' + archiveId;
    var $iframe = $('<iframe id="' + iframeId + '" name="' + iframeId + '"></iframe>');
    $('#hidden-iframes').append($iframe);
    
    // Trigger download
    // Note: The download script should return a file, so the load event might not trigger correctly on all browsers
    // but for the sake of chaining, we might use a timeout or try to detect
    // Actually, since we need to wait for the server to generate the zip (if not ready)
    // we should wait.
    
    $iframe.attr('src', url);
    
    // Assume download starts/completes after a delay or when iframe loads (if it returns a page on error)
    // Since it's a file download, iframe load event is tricky.
    // For this implementation, we will use a timeout to start the next one, 
    // assuming the browser handles the download queue or the server handles the generation.
    // If generation is synchronous, the server won't respond until zip is ready.
    // So we can try to wait for some response? 
    // Actually, simple chaining with delay is safer for now.
    
    setTimeout(function() {
       $card.find('.progress-bar-bg').hide();
       $card.find('.archive-action').append('<span class="archive-downloaded">Téléchargé (lancé)</span>');
       currentDownloadIndex++;
       processNextDownload();
    }, 5000); // 5 seconds delay between starts
  }
  {/literal}
  </script>
{/if}

</div>
{/if}