<?
$parser_src = HAML_FPATH . "/codegen/HamlLexer.plex";
$parser_dst = HAML_FPATH . "/codegen/HamlLexer.php";

if (is_newer($parser_src,$parser_dst))
{
  require_once 'LexerGenerator.php';
  ob_start();
  $lex = new PHP_LexerGenerator($parser_src);
  ob_get_clean();
}

require($parser_dst);

foreach($manifests as $module_name=>$manifest)
{
  foreach( glob($manifest['path']."/views/*.haml") as $src)
  {
    $event_name = basename($src,".haml");
    $dst_path = normalize_path(CACHE_FPATH."/{$module_name}/views");
    ensure_writable_folder($dst_path);
    $dst = $dst_path."/{$event_name}.php";
    if (is_newer($src,$dst) || is_newer($parser_dst,$dst))
    {
      haml_to_php($src,$dst);
      if(!file_exists($dst)) die('wtf');
    }
    $codegen[] = "\$event_injections['$module_name']['$event_name'][] = '$dst';";
  }
}
