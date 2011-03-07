
<h2>Cachegrind Links</h2>
<? if (count($links)>0) { ?>
  <ul>
    <? foreach($links as $link) { ?>
      <li>
        <a href="<?= htmlentities($link['href']) ?>"><?= $link['label'] ?></a>
      </li>
    <? } ?>
  </ul>
<? } ?>

