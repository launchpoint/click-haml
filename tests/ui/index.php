<?

require_once("../../../../../../kernel/bootstrap.php");

$root = dirname(__FILE__);

foreach(glob("$root/*.haml") as $haml_path)
{
  $lex = new HamlLexer();
  $lex->data = file_get_contents($haml_path);
  $php = $lex->render_to_string();
  if ($php != file_get_contents($haml_path.".php"))
  {
    echo($haml_path. " failed validation.<br/>");
  }
}
if (array_key_exists('haml', $_REQUEST))
{
  $lex = new HamlLexer();
  $lex->data = $_REQUEST['haml'];
  $generated_php = $lex->render_to_string();
  
  if (array_key_exists('save', $_REQUEST))
  {
    $fname = time();
    file_put_contents("$root/$fname.haml", $lex->data);
    file_put_contents("$root/$fname.haml.php", $generated_php);
    system("chmod 770 $root/*.haml");
    system("chmod 770 $root/*.haml.php");
  }
} 

?>

<html>
<body>
<?
if (isset($generated_php))
{
  ?>
  <pre>
<?=htmlentities($generated_php)?>
  </pre>
  <?
}
?>

<form>
<textarea name="haml" style="width:600px;height:250px"><? if (isset($generated_php)) echo $_REQUEST['haml']?></textarea>
<br/>
<input type=checkbox name="save" value="1"/> Save
<input type=submit name="Generate" value="Generate"/> 

</form>