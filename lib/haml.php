<?

function haml($path, $data=array())
{
  global $run_mode;
  
  $unique_name = ftov($path);
  $unique_name = preg_replace("/\\//", '_', $unique_name);
  $php_path = HAML_CACHE_FPATH."/$unique_name.php";
  if (is_newer($path, $php_path))
  {
    haml_to_php($path, $php_path);
  }
  return eval_php($php_path,$data);
}


function haml_to_php($src,$dst)
{
  $lex = new HamlLexer();
  $lex->N = 0;
  $lex->data = file_get_contents($src);
  $s = $lex->render_to_string();
  file_put_contents($dst, $s);
}

function str_to_haml($s)
{
  $lex = new HamlLexer();
  $lex->N = 0;
  $lex->data = $s;
  $s = $lex->render_to_string();
  return $s;
}