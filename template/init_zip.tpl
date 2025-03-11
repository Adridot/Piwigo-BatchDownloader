{combine_css path=$BATCH_DOWNLOAD_PATH|cat:"template/style.css"}

{if $set.U_DOWNLOAD|default: null}
{footer_script}
setTimeout(function() {
  document.location.href = '{$set.U_DOWNLOAD}';
}, 1000);
{/footer_script}
{/if}

{if $missing_derivatives|default: null}
  {* Gestion des dérivés (non modifiée) *}
{/if}

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

<!-- Bloc 2 : Instructions -->
<div class="download-instructions-block">
  <p>
    <strong>Votre téléchargement est prêt.</strong><br>
    En raison de la taille conséquente du dossier de photos, celui-ci a été divisé en plusieurs archives.<br>
  </p>
  <ul class="download-instructions-list">
    <li>Chaque archive peut peser jusqu'à <strong>800 Mo</strong>.</li>
    <li>La compression sur le serveur et le téléchargement peuvent prendre quelques minutes.</li>
    <li>Assurez-vous que le téléchargement de chaque archive est terminé avant de lancer la suivante.</li>
    <li>Si vous rencontrez un problème, vérifiez votre connexion internet et réessayez.</li>
  </ul>
  <p>
    <em>Note : Le système d'extraction natif de Windows et macOS vous permettra de décompresser facilement les fichiers ZIP téléchargés.</em>
  </p>
</div>

  <!-- Bloc 3 : Archives + bouton d’annulation -->
  <div class="download-archives-block">
    {assign var="zip_links" value=$zip_links|default:[]}
    {if $zip_links|@count > 0}
      <div class="archives-grid">
        {foreach from=$zip_links item=archive}
          {*
            Conversion de la taille de chaque archive.
            $archive.size_ko est en KiB, donc :
              bytes = size_ko * 1024
              Mo base10 = bytes / 1,000,000
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

          <div class="archive-card">
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
              {if $archive.status == 'ready'}
                <a href="{$archive.url}" class="archive-button"
                   data-archive-id="{$archive.id}"
                   onclick="return onDownloadClick(this){if $archive.confirm} && confirm('{$archive.confirm}'){/if};">
                  {$archive.label}
                </a>
              {elseif $archive.status == 'pending'}
                <button class="archive-button disabled" disabled="disabled">
                  {$archive.label}
                </button>
              {elseif $archive.status == 'downloaded'}
                <span class="archive-downloaded">{$archive.label}</span>
              {/if}
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

</div>
{/if}


<script type="text/javascript">
function onDownloadClick(el) {
  // Affiche un petit spinner statique dans le bouton
  el.innerHTML = '<span class="spinner"></span> Préparation...';
  // On ne bloque pas la suite, la page se rechargera quand le ZIP sera prêt
  return true;
}
{footer_script}
{/footer_script}
</script>