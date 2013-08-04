<?php
require_once("c/src/nbbc_main.php");  // Expanded version.
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