<?

$fpath = dirname(__FILE__)."/../test_files";
foreach(glob($fpath . "/*.haml") as $haml_path)
{
  $lex = new HamlLexer();
  $lex->data = file_get_contents($haml_path);
  $php = $lex->render_to_string();
  ae($php, file_get_contents($haml_path.".php"));
}
