
<div class="developer">
  <div class="panel">
    <? var_dump($manifests); ?>
  </div>
</div>
<? if (count($error_messages)>0) { ?>
  <script>
    $('#developer_panel').toggle();
  </script>
<? } ?>

