<?php
class HamlLexer {
  var $current_block_level = 0;
  var $tag_stack = array();
  var $current_tag = null;
  var $php_open = false;
  var $new_block_level = 0;
  var $is_new_line = true;
  var $counter = 0;
  var $line=0;
  var $debug_path = null;
  var $short_tags = array('br', 'hr', 'link', 'meta', 'img', 'input');
  var $is_current_tag_open = false;
  var $php_wrap=false;
  var $is_sass_mode = false;
  var $css_classes = array();

  function render_to_string()
  {
    $this->data = preg_replace("/\r/", "", $this->data);
    ob_start();
    if ($this->debug_path) echo "\n<!-- HAML $this->debug_path -->\n";
    do
    {
      $res = $this->yylex();
    } while($res);
    $this->finish_render();
    if ($this->debug_path) echo "\n<!-- END HAML $this->debug_path -->\n";
    $s = ob_get_contents();
    ob_end_clean();
    return $s;
  }
  
  function finish_render()
  {
    if ($this->php_open) echo " ?>";
    if ($this->current_tag !== null)
    {
      if (count($this->css_classes)>0)
      {
        echo ' class="' . join(' ', $this->css_classes) . '">';
        $this->css_classes=array();
      }
      if ($this->current_tag && array_search($this->current_tag, $this->short_tags) !== false)
      {
        echo '/>';
      } else {
        if ($this->is_current_tag_open==true) echo ">";
        echo "</$this->current_tag>";
      }
    }
    echo "\n";
    while($this->current_block_level>=0)
    {
      $this->current_block_level--;
      $indent = str_pad("", $this->current_block_level*2);
      if ($this->is_sass_mode)
      {
        $this->output_sass();
      }
      $tag = array_pop($this->tag_stack);
      if ($tag == "*php_block_end*")
      {
        $tag = "<? } ?>";
      }
      echo "$indent$tag\n";
    }
  }
  
  function indent($is_php=false)
  {
    $indent = str_pad("", $this->new_block_level*2);
    $current_block_level = $this->current_block_level;
    if ($this->current_block_level>$this->new_block_level)
    {
      if ($this->php_open) echo " ?>";
      $this->php_open=false;
      if ($this->current_tag == null) echo "\n";
    }
    while($current_block_level>$this->new_block_level)
    {
      $current_block_level--;
      $indent = str_pad("", $current_block_level*2);
      if ($this->current_tag !== null)
      {
        echo "</$this->current_tag>\n";
        $this->current_tag=null;
      }
      $tag = array_pop($this->tag_stack);
      if ($tag == "*php_block_end*")
      {
        if ($is_php && $current_block_level == $this->new_block_level)
        {
          $tag = "<? } ";
          $this->php_open=true;
        } else {
          $tag = "<? } ?>";
        }
      }
      echo "$indent$tag\n";
        
    }
    if ($this->current_block_level>$this->new_block_level)
    {
      echo "$indent";
    }    
    if ($this->new_block_level == $this->current_block_level)
    {
      if ($this->php_open) echo " ?>";
      $this->php_open=false;
      if ($this->current_tag !== null)
      {
        echo "</$this->current_tag>";
        $this->current_tag=null;
      }
      echo "\n$indent";
    }
    if ($this->new_block_level > $this->current_block_level)
    {
      if ($this->php_open)
      {
        echo " { ?>";
        $this->tag_stack[] = "*php_block_end*";
        $this->php_open=false;
      } else {
        $this->tag_stack[] = "</$this->current_tag>"; // fixme??
      }
      echo "\n$indent";
      $this->current_tag=null;
    }

    $this->current_block_level=$this->new_block_level;
  }
  function handle_command_start($matches)
  {
    $this->css_classes=array();
    if ($this->is_new_line) $this->new_block_level=0;
    $cmd = array_shift($matches);
    switch($cmd)
    {
      case ' ':
        $this->yybegin(self::INDENT_COMMAND);
        return true;
        break;
      case '.': 
        $this->indent();
        $this->yybegin(self::CLASS_COMMAND);
        break;
      case '%':
        $this->indent();
        $this->yybegin(self::TAG_COMMAND);
        break;
      case '-':
        $this->indent(true);
        $this->yybegin(self::PHP_COMMAND);
        break;
      case '=':
        $this->indent();
        $this->yybegin(self::PHP_COMMAND_ECHO_BEGIN);
        break;
      case '+':
        $this->indent();
        $this->php_wrap = true;
        $this->yybegin(self::PHP_COMMAND_ECHO_BEGIN);
        break;
      case '#':
        $this->indent();
        $this->yybegin(self::ID_COMMAND);
        break;
      case ':':
        $this->indent();
        $this->yybegin(self::ESCAPE_COMMAND);
        break;
      case '!':
        $this->indent();
        $this->yybegin(self::DOCTYPE_COMMAND);
        return true;
        break;
      case "\t":
        click_error("HAML error: You have a TAB where you shouldn't ({$this->new_block_level}). Check for tabs masquerading as invisible spaces. On line {$this->line} around " . trim(substr($this->data, $this->counter, 20)), $this->data);
      default:
        $this->indent();
        $this->yybegin(self::TEXT_LINE);
        return true;
    }
  }
  
  function handle_tag($matches)
  {
     $tag = array_shift($matches);
     $this->current_tag = $tag;
     echo "<$tag";
     $this->is_current_tag_open=true;
     $this->yybegin(self::COMMAND_CONTENT);
  }
  
  
  function handle_command_content_token($matches)
  {
    $short_tags = array('br', 'hr', 'link', 'meta', 'img', 'input');
    $content = array_shift($matches);
    switch($content)
    {
      case '{':
        if (count($this->css_classes)>0)
        {
          echo ' class="' . join(' ', $this->css_classes) . '"';
          $this->css_classes=array();
        }
        $this->yybegin(self::ATTRIBUTE_START);
        break;
      case '-':
      case '=':
      case '+':
        if (count($this->css_classes)>0)
        {
          echo ' class="' . join(' ', $this->css_classes) . '"';
          $this->css_classes=array();
        }
        $this->php_wrap = ($content == '+');
        switch($content)
        {
          case '=':
          case '+':
            $this->yybegin(self::PHP_COMMAND_ECHO_BEGIN);
            break;
          case '-':
            $this->yybegin(self::PHP_COMMAND);
            break;
        }
        if ($this->current_tag && array_search($this->current_tag, $this->short_tags) !== false)
        {
          echo '/';
          $this->current_tag = null;
        }
        echo ">";
        $this->is_current_tag_open=false;
        break;
      case '%':
        if (count($this->css_classes)>0)
        {
          echo ' class="' . join(' ', $this->css_classes) . '"';
          $this->css_classes=array();
        }
        if ($this->current_tag && array_search($this->current_tag, $this->short_tags) !== false)
        {
          echo '/';
          $this->current_tag = null;
        }
        echo ">";
        $this->tag_stack[] = "</$this->current_tag>";
        $this->current_block_level++;
        $this->is_current_tag_open=false;
        $this->yybegin(self::TAG_COMMAND);
        break;
      case "\n":
        if (count($this->css_classes)>0)
        {
          echo ' class="' . join(' ', $this->css_classes) . '"';
          $this->css_classes=array();
        }
        if ($this->current_tag && array_search($this->current_tag, $this->short_tags) !== false)
        {
          echo '/';
          $this->current_tag = null;
        }
        echo ">";
        $this->is_current_tag_open=false;
        $this->is_new_line=true;
        $this->yybegin(self::COMMAND_START);
        break;
      case '.':
        $this->yybegin(self::CLASS_COMMAND);
        break;
      case '#':
        $this->yybegin(self::ID_COMMAND);
        break;
      case ' ':
        break;
      default:
        if (count($this->css_classes)>0)
        {
          echo ' class="' . join(' ', $this->css_classes) . '"';
          $this->css_classes=array();
        }
        if ($this->current_tag && array_search($this->current_tag, $this->short_tags) !== false)
        {
          echo '/';
          $this->current_tag = null;
        }
        echo ">";
        $this->is_current_tag_open=false;
        $this->yybegin(self::TEXT_LINE);
        return true;
        break;
    }
  }
  
  function handle_attribute_assign($matches)
  {
    $name = array_shift($matches);
    echo " $name=\"<?= htmlentities(";
    $this->yybegin(self::ATTRIBUTE_VALUE_TOKEN);
    $this->attribute_value_expecting = array('php');
  }
  
  function handle_attribute_value_token($matches)
  {
    $expecting = $this->attribute_value_expecting[count($this->attribute_value_expecting)-1];
    $tok = array_shift($matches);
    
#    echo "\n|$tok| - expecting $expecting - stack " . join(", ", $this->attribute_value_expecting) ."\n";
    
    switch($expecting)
    {
      case 'php':
        switch($tok)
        {
          case '(':
            echo $tok;
            $this->attribute_value_expecting[] = "nested_php";
            break;
          case '\'':
            echo $tok;
            $this->attribute_value_expecting[] = "squoted_text";
            break;
          case '"':
            echo $tok;
            $this->attribute_value_expecting[] = "dquoted_text";
            break;
          case '}':
            array_pop($this->attribute_value_expecting);
            echo ") ?>\"";
            $this->yybegin(self::COMMAND_CONTENT);
            break;
          case ',':
            array_pop($this->attribute_value_expecting);
            echo ") ?>\"";
            $this->yybegin(self::ATTRIBUTE_START);
            break;
            
          default:
            echo $tok;
        }
        break;
      case 'nested_php':
        echo $tok;
        switch($tok)
        {
          case '(':
            $this->attribute_value_expecting[] = "nested_php";
            break;
          case ')':
            array_pop($this->attribute_value_expecting);
            break;
          case '\'':
            $this->attribute_value_expecting[] = "squoted_text";
            break;
          case '"':
            $this->attribute_value_expecting[] = "dquoted_text";
            break;
        }
        break;
      case 'dquoted_text':
        echo $tok;
        switch($tok)
        {
          case '$':
            $this->attribute_value_expecting[] = "possible_php_escape";
            break;
          case '{':
            $this->attribute_value_expecting[] = "escaped_php";
            break;
          case '"':
            array_pop($this->attribute_value_expecting);
            break;
            
        }
        break;
      case 'squoted_text':
        echo $tok;
        switch($tok)
        {
          case '\'':
            array_pop($this->attribute_value_expecting);
            break;
          case '\\':
            $this->attribute_value_expecting[] = "escaped_text_char";
            break;
        }
        break;
      case 'escaped_text_char':
        echo $tok;
        array_pop($this->attribute_value_expecting);
        break;
      case 'possible_php_escape':
        echo $tok;
        array_pop($this->attribute_value_expecting);
        switch($tok)
        {
          case '{':
            $this->attribute_value_expecting[] = "escaped_php";
            break;
        }
        break;
      case 'escaped_php':
        echo $tok;
        switch($tok)
        {
          case '(':
            $this->attribute_value_expecting[] = "nested_php";
          case '\'':
            $this->attribute_value_expecting[] = "squoted_text";
            break;
          case '"':
            $this->attribute_value_expecting[] = "dquoted_text";
            break;
          case '}':
            array_pop($this->attribute_value_expecting);
            break;
        }
        break;
    }
  }
  
  function handle_text_line($matches, $is_new_line=false, $is_last_line=false)
  {
    $line = array_shift($matches);
    if ($is_new_line) $this->indent();
    echo $line;
    $this->is_new_line=true;
    $this->yybegin(self::COMMAND_START);
  }
  
  function handle_indent_run($matches)
  {
    $indent = array_shift($matches);
    $this->new_block_level = strlen($indent)/2;
    if ($this->new_block_level > count($this->tag_stack)+1) click_error("HAML error: You have indentation where you shouldn't ({$this->new_block_level}). Check for blank lines with indentation. On line {$this->line} around " . trim(substr($this->data, $this->counter, 20)), $this->data);
    $this->is_new_line=false;
    $this->yybegin(self::COMMAND_START);
  }
  

  function handle_class_command($matches)
  {
    $this->css_classes[] = array_shift($matches);
    if ($this->current_tag==null)
    {
      echo "<div";
      $this->current_tag="div";
    }
    $this->yybegin(self::COMMAND_CONTENT);
  }
  
  function handle_escape_command($matches)
  {
    $tok = array_shift($matches);
    switch($tok)
    {
      case 'js':
        echo "<script type='text/javascript'>\n//<![CDATA[";
        $this->tag_stack[] = "//]]>\n</script>";
        break;
      case 'css':
        echo '<style type="text/css">';
        $this->tag_stack[] = '</style>';
        break;
      case 'sass':
        $this->is_sass_mode = true;
        $this->sass_lines = array();
        echo '<style type="text/css">';
        $this->tag_stack[] = '</style>';
        break;
      case 'php':
        echo '<?';
        $this->tag_stack[] = "?>";
        break;
      default:
        click_error("Unsupported escape command: $tok -> {$this->data}");
    }
    $this->is_new_line=false;
    $this->yybegin(self::ESCAPED_LINE);
  }
  
  function output_sass()
  {
    $sass = join($this->sass_lines, "\n");
    $md5 = md5($sass);
    $fname = HAML_CACHE_FPATH."/$md5.sass";
    file_put_contents($fname, $sass);
    $renderer = SassRenderer::EXPANDED;
    $parser = new SassParser(dirname($fname), SASS_CACHE_FPATH , $renderer);
    $css = $parser->fetch($fname, $renderer);
    $lines = split("\n",$css);
    echo "\n";
    foreach($lines as $line)
    {
      $indent = str_pad("", count($this->tag_stack)*2);
      echo $indent.$line."\n";    
    }
  }
  
  function handle_escaped_line($matches)
  {
    $indent = array_shift($matches);
    $line = array_shift($matches);
    if ($this->is_new_line)
    {
      $current_block_level = count($this->tag_stack);
      $this->new_block_level = strlen($indent)/2;
      if ($this->new_block_level < $current_block_level)
      {
        if ($this->is_sass_mode)
        {
          $this->output_sass();
        }
        $this->is_sass_mode=false;
        echo $indent;
        echo array_pop($this->tag_stack);
        $this->yybegin(self::COMMAND_START);
        return true;
      }
    }
    $out = $indent.$line;
    if (!$this->is_sass_mode)
    {
      echo $out."\n";
    } else {
      if ($this->is_new_line) $this->sass_lines[] = substr($out,count($this->tag_stack)*2);
    }
    $this->is_new_line=true;
    $this->yybegin(self::ESCAPED_LINE);
  }
  
  function handle_id_command($matches)
  {
    $tok = array_shift($matches);
    if ($this->current_tag==null)
    {
      echo "<div";
      $this->current_tag="div";
    }
    echo " id=\"$tok\"";
    $this->yybegin(self::COMMAND_CONTENT);
  }
  
  
  function handle_php_line($matches, $use_echo=false)
  {
    $php = array_shift($matches);
    if (!$this->php_open)
    {
      if ($use_echo)
      {
        echo "<?= ";
        if ($this->php_wrap) echo "htmlentities(";
      } else {
        echo "<? ";
      }
    }
    echo $php;
    if ($use_echo)
    {
      if ($this->php_wrap) echo ")";
      echo " ?>";
      $this->is_new_line=true;
    } else {
      $this->php_open = true;
    }
    $this->is_new_line=true;
    $this->php_wrap = false;
    $this->yybegin(self::COMMAND_START);
  }
  
  function handle_doctype($matches)
  {
    $type=trim(array_shift($matches));
    if (!$type) $type='1.1';
    $types = array
  	(
  		'1.1' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">',
  		'Strict' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">',
  		'Transitional' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">',
  		'Frameset' => '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Frameset//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-frameset.dtd">',
  		'XML' => "<?php echo '<?xml version=\"1.0\" encoding=\"utf-8\" ?>'; ?>\n"
  	);
  	echo $types[$type];
  	$this->yybegin(self::COMMAND_START);
  }
  
function strToHex($string)
{
    $hex='';
    for ($i=0; $i < strlen($string); $i++)
    {
        $hex .= dechex(ord($string[$i])) . " ";
    }
    return $hex;
}
  

    private $_yy_state = 1;
    private $_yy_stack = array();

    function yylex()
    {
        return $this->{'yylex' . $this->_yy_state}();
    }

    function yypushstate($state)
    {
        array_push($this->_yy_stack, $this->_yy_state);
        $this->_yy_state = $state;
    }

    function yypopstate()
    {
        $this->_yy_state = array_pop($this->_yy_stack);
    }

    function yybegin($state)
    {
        $this->_yy_state = $state;
    }



    function yylex1()
    {
        $tokenMap = array (
              1 => 1,
              3 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^((.))|^((.*)\n)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state COMMAND_START');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r1_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, "^((.*)\n)"),
        3 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r1_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const COMMAND_START = 1;
    function yy_r1_1($yy_subpatterns)
    {

  return $this->handle_command_start($yy_subpatterns);
    }
    function yy_r1_3($yy_subpatterns)
    {

  echo "\n";
    }


    function yylex2()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(([\w\-_]+) *)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state TAG_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r2_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r2_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const TAG_COMMAND = 2;
    function yy_r2_1($yy_subpatterns)
    {

  return $this->handle_tag($yy_subpatterns);
    }


    function yylex3()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^((.|\n))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state COMMAND_CONTENT');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r3_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r3_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const COMMAND_CONTENT = 3;
    function yy_r3_1($yy_subpatterns)
    {

  return $this->handle_command_content_token($yy_subpatterns);
    }


    function yylex4()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^( *:(\\w+) *=> *)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state ATTRIBUTE_START');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r4_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r4_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const ATTRIBUTE_START = 4;
    function yy_r4_1($yy_subpatterns)
    {

  return $this->handle_attribute_assign($yy_subpatterns);
    }


    function yylex5()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^((.|\n))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state ATTRIBUTE_VALUE_TOKEN');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r5_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r5_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const ATTRIBUTE_VALUE_TOKEN = 5;
    function yy_r5_1($yy_subpatterns)
    {

  return $this->handle_attribute_value_token($yy_subpatterns);
    }


    function yylex6()
    {
        $tokenMap = array (
              1 => 1,
              3 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^( *(.*?)\n)|^( *(.*))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state TEXT_LINE');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r6_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, "^( *(.*))"),
        3 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r6_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const TEXT_LINE = 6;
    function yy_r6_1($yy_subpatterns)
    {

  return $this->handle_text_line($yy_subpatterns);
    }
    function yy_r6_3($yy_subpatterns)
    {

  return $this->handle_text_line($yy_subpatterns);
    }


    function yylex7()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(( +))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state INDENT_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r7_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r7_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const INDENT_COMMAND = 7;
    function yy_r7_1($yy_subpatterns)
    {

  return $this->handle_indent_run($yy_subpatterns);
    }


    function yylex8()
    {
        $tokenMap = array (
              1 => 1,
              3 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^( *(.*?)\n)|^( *(.*))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state PHP_COMMAND_ECHO_BEGIN');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r8_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, "^( *(.*))"),
        3 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r8_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const PHP_COMMAND_ECHO_BEGIN = 8;
    function yy_r8_1($yy_subpatterns)
    {

  return $this->handle_php_line($yy_subpatterns,true);
    }
    function yy_r8_3($yy_subpatterns)
    {

  return $this->handle_php_line($yy_subpatterns,true);
    }


    function yylex9()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(([\w\-_]+) *)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state CLASS_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r9_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r9_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const CLASS_COMMAND = 9;
    function yy_r9_1($yy_subpatterns)
    {

  return $this->handle_class_command($yy_subpatterns);
    }


    function yylex10()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(([\w\-_]+) *)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state ESCAPE_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r10_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r10_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const ESCAPE_COMMAND = 10;
    function yy_r10_1($yy_subpatterns)
    {

  return $this->handle_escape_command($yy_subpatterns);
    }


    function yylex11()
    {
        $tokenMap = array (
              1 => 2,
              4 => 2,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(( *)(.*?)\n)|^(( *)(.*))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state ESCAPED_LINE');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r11_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(( *)(.*))"),
        4 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r11_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const ESCAPED_LINE = 11;
    function yy_r11_1($yy_subpatterns)
    {

  return $this->handle_escaped_line($yy_subpatterns);
    }
    function yy_r11_4($yy_subpatterns)
    {

  return $this->handle_escaped_line($yy_subpatterns);
    }



    function yylex12()
    {
        $tokenMap = array (
              1 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(([\w\-_]+) *)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state ID_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r12_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r12_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const ID_COMMAND = 12;
    function yy_r12_1($yy_subpatterns)
    {

  return $this->handle_id_command($yy_subpatterns);
    }


    function yylex13()
    {
        $tokenMap = array (
              1 => 1,
              3 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^( *(.*?)\n)|^( *(.*))/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state PHP_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r13_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, "^( *(.*))"),
        3 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r13_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const PHP_COMMAND = 13;
    function yy_r13_1($yy_subpatterns)
    {

  return $this->handle_php_line($yy_subpatterns);
    }
    function yy_r13_3($yy_subpatterns)
    {

  return $this->handle_php_line($yy_subpatterns);
    }


    function yylex14()
    {
        $tokenMap = array (
              1 => 1,
              3 => 1,
            );
        if ($this->counter >= strlen($this->data)) {
            return false; // end of input
        }
        $yy_global_pattern = "/^(!!!(.*?)\n)|^( *(.*?)\n)/";

        do {
            if (preg_match($yy_global_pattern, substr($this->data, $this->counter), $yymatches)) {
                $yysubmatches = $yymatches;
                $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                if (!count($yymatches)) {
                    throw new Exception('Error: lexing failed because a rule matched' .
                        'an empty string.  Input "' . substr($this->data,
                        $this->counter, 5) . '... state DOCTYPE_COMMAND');
                }
                next($yymatches); // skip global match
                $this->token = key($yymatches); // token number
                if ($tokenMap[$this->token]) {
                    // extract sub-patterns for passing to lex function
                    $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                        $tokenMap[$this->token]);
                } else {
                    $yysubmatches = array();
                }
                $this->value = current($yymatches); // token value
                $r = $this->{'yy_r14_' . $this->token}($yysubmatches);
                if ($r === null) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    // accept this token
                    return true;
                } elseif ($r === true) {
                    // we have changed state
                    // process this token in the new state
                    return $this->yylex();
                } elseif ($r === false) {
                    $this->counter += strlen($this->value);
                    $this->line += substr_count($this->value, "\n");
                    if ($this->counter >= strlen($this->data)) {
                        return false; // end of input
                    }
                    // skip this token
                    continue;
                } else {                    $yy_yymore_patterns = array(
        1 => array(0, "^( *(.*?)\n)"),
        3 => array(0, ""),
    );

                    // yymore is needed
                    do {
                        if (!strlen($yy_yymore_patterns[$this->token][1])) {
                            throw new Exception('cannot do yymore for the last token');
                        }
                        $yysubmatches = array();
                        if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
                              substr($this->data, $this->counter), $yymatches)) {
                            $yysubmatches = $yymatches;
                            $yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
                            next($yymatches); // skip global match
                            $this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
                            $this->value = current($yymatches); // token value
                            $this->line = substr_count($this->value, "\n");
                            if ($tokenMap[$this->token]) {
                                // extract sub-patterns for passing to lex function
                                $yysubmatches = array_slice($yysubmatches, $this->token + 1,
                                    $tokenMap[$this->token]);
                            } else {
                                $yysubmatches = array();
                            }
                        }
                    	$r = $this->{'yy_r14_' . $this->token}($yysubmatches);
                    } while ($r !== null && !is_bool($r));
			        if ($r === true) {
			            // we have changed state
			            // process this token in the new state
			            return $this->yylex();
                    } elseif ($r === false) {
                        $this->counter += strlen($this->value);
                        $this->line += substr_count($this->value, "\n");
                        if ($this->counter >= strlen($this->data)) {
                            return false; // end of input
                        }
                        // skip this token
                        continue;
			        } else {
	                    // accept
	                    $this->counter += strlen($this->value);
	                    $this->line += substr_count($this->value, "\n");
	                    return true;
			        }
                }
            } else {
		var_dump(substr($this->data, $this->counter, 20));
                throw new Exception('Unexpected input at line' . $this->line .
                    ': ' . $this->data[$this->counter]);
            }
            break;
        } while (true);

    } // end function


    const DOCTYPE_COMMAND = 14;
    function yy_r14_1($yy_subpatterns)
    {

  return $this->handle_doctype($yy_subpatterns);
    }
    function yy_r14_3($yy_subpatterns)
    {

  return $this->handle_text_line($yy_subpatterns,true);
    }


}
?>