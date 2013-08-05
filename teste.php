<?php
	define("BBCODE_VERBATIM", 2);		// Content type:  Content is not processed as BBCode.
	define("BBCODE_REQUIRED", 1);		// Content type:  Content may not be empty or whitespace.
										// End tags:  End tag must be given by user.
	define("BBCODE_OPTIONAL", 0);		// Content type:  Content is permitted but not required.
										// End tags:  End tag is permitted but not required.
	define("BBCODE_PROHIBIT", -1);		// Content type:  Content may not be provided by user.
										// End tags:  End tag is disallowed; start tag only.

	define("BBCODE_CHECK", 1);			// Callback operation: Check validitity of input
	define("BBCODE_OUTPUT", 2);			// Callback operation: Generate HTML output

	define("BBCODE_ENDTAG", 5);			// Token: An [/end tag]
	define("BBCODE_TAG", 4);			// Token: A [start tag] or [empty tag]
	define("BBCODE_TEXT", 3);			// Token: Non-whitespace non-tag plain text
	define("BBCODE_NL", 2);				// Token: A single newline
	define("BBCODE_WS", 1);				// Token: Non-newline whitespace
	define("BBCODE_EOI", 0);			// Token: End-of-input

	define("BBCODE_LEXSTATE_TEXT", 0);	// Lexer: Next token is plain text.
	define("BBCODE_LEXSTATE_TAG", 1);	// Lexer: Next token is non-text element.

	define("BBCODE_MODE_SIMPLE", 0);	// Swap BBCode tags with HTML tags.
	define("BBCODE_MODE_CALLBACK", 1);	// Use provided callback function or method.
	define("BBCODE_MODE_INTERNAL", 2);	// Use internal callback function.
	define("BBCODE_MODE_LIBRARY", 3);	// Use library callback function.
	define("BBCODE_MODE_ENHANCED", 4);	// Insert BBCode input into the provided HTML template.

	define("BBCODE_STACK_TOKEN", 0);	// Stack node: Token type
	define("BBCODE_STACK_TEXT", 1);		// Stack node: HTML text content
	define("BBCODE_STACK_TAG", 2);		// Stack node: Tag contents (array)
	define("BBCODE_STACK_CLASS", 3);	// Stack node: Classname

	require_once("nbbc_lex.php");		// The lexical analyzer.
function do_shortcodeppp($str) {
    $ret = array();
    $i = 0;
    while ($str[$i] != '[')
        $i++;
    $str = substr($str, $i);
    $ret[1] = '';
    $ret[4] = '';
    $ret[6] = '';
    $ignore = 0;
    $bbcode = new BBCodeLexer($str);
    //$bbcode->debug = true;
    while (($token_type = $bbcode->NextToken()) != BBCODE_EOI) {
        $ret[0] .= $bbcode->text;
        if ($token_type == BBCODE_TAG) {
            if(!isset($tag)) {
                $tag = substr($bbcode->text, 1, strlen($bbcode->text)-2);
                $m = explode(' ', $tag);
                $ret[2] = $m[0];
                unset($m[0]);
                $ret[3] = ' ' . implode(' ', array_values($m));
            } else {
                $newTag = substr($bbcode->text, 1, strlen($bbcode->text)-2);
                $c = explode(' ', $newTag);
                if ($ret[2] == $c[0]) $ignore++;
                else $ret[5] .= $bbcode->text;
            }
        } elseif ($token_type == BBCODE_ENDTAG) {
            if ($bbcode->text == '[/'.$ret[2].']') {
                if ($ignore == 0) {
                    break;
                } elseif ($ignore > 0) {
                    $ret[5] .= $bbcode->text;
                    $ignore--;
                }
            } else {
                $ret[5] .= $bbcode->text;
            }
        } elseif ($token_type == BBCODE_WS) {
            $ret[5] .= $bbcode->text;
        } elseif ($token_type == BBCODE_TEXT) {
            $ret[5] .= $bbcode->text;
        }
    }
    ksort($ret);
    echo '<pre>';
    print_r($ret);
    echo '</pre>';
    //do_shortcode_tag($ret);
}

function get_shortcode_regex() {
    return '\\[(\\[?)(list|li|div|a)(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}

function do_shortcode($content) {
    $pattern = get_shortcode_regex();
    return preg_replace_callback( "/$pattern/s", 'do_shortcode_tag', $content );
}
function do_shortcode_tag($m) {
    echo '<pre>';
    print_r($m);
    echo '</pre>';
}
$str = '';
echo 'A minha solução --> <br />';
do_shortcodeppp($str);
echo 'Solução do wordpress --> <br />';
do_shortcode($str);
?>