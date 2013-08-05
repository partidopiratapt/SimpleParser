<?php

define("BBCODE_VERBATIM", 2);  // Content type:  Content is not processed as BBCode.
define("BBCODE_REQUIRED", 1);  // Content type:  Content may not be empty or whitespace.
// End tags:  End tag must be given by user.
define("BBCODE_OPTIONAL", 0);  // Content type:  Content is permitted but not required.
// End tags:  End tag is permitted but not required.
define("BBCODE_PROHIBIT", -1);  // Content type:  Content may not be provided by user.
// End tags:  End tag is disallowed; start tag only.

define("BBCODE_CHECK", 1);   // Callback operation: Check validitity of input
define("BBCODE_OUTPUT", 2);   // Callback operation: Generate HTML output

define("BBCODE_ENDTAG", 5);   // Token: An [/end tag]
define("BBCODE_TAG", 4);   // Token: A [start tag] or [empty tag]
define("BBCODE_TEXT", 3);   // Token: Non-whitespace non-tag plain text
define("BBCODE_NL", 2);    // Token: A single newline
define("BBCODE_WS", 1);    // Token: Non-newline whitespace
define("BBCODE_EOI", 0);   // Token: End-of-input

define("BBCODE_LEXSTATE_TEXT", 0); // Lexer: Next token is plain text.
define("BBCODE_LEXSTATE_TAG", 1); // Lexer: Next token is non-text element.

define("BBCODE_MODE_SIMPLE", 0); // Swap BBCode tags with HTML tags.
define("BBCODE_MODE_CALLBACK", 1); // Use provided callback function or method.
define("BBCODE_MODE_INTERNAL", 2); // Use internal callback function.
define("BBCODE_MODE_LIBRARY", 3); // Use library callback function.
define("BBCODE_MODE_ENHANCED", 4); // Insert BBCode input into the provided HTML template.

define("BBCODE_STACK_TOKEN", 0); // Stack node: Token type
define("BBCODE_STACK_TEXT", 1);  // Stack node: HTML text content
define("BBCODE_STACK_TAG", 2);  // Stack node: Tag contents (array)
define("BBCODE_STACK_CLASS", 3); // Stack node: Classname

require_once("nbbc_lex.php");  // The lexical analyzer.

function do_shortcodeppp($str) {
    $ret = array();
    $i = strpos($str, '[');
    echo $i;
    if ($i !== FALSE) {
        $str = substr($str, $i);
        $ignore = 0;
        $a = 0;
        $bbcode = new BBCodeLexer($str);
        while (($token_type = $bbcode->NextToken()) != 0) {
            $ret[$a][0] .= $bbcode->text;
            if ($token_type == 4) {
                if (!isset($tag)) {
                    $tag = substr($bbcode->text, 1, strlen($bbcode->text) - 2);
                    $m = explode(' ', $tag);
                    $ret[$a][2] = $m[0];
                    unset($m[0]);
                    $ret[$a][3] = ' ' . implode(' ', array_values($m));
                } else {
                    $newTag = substr($bbcode->text, 1, strlen($bbcode->text) - 2);
                    $c = explode(' ', $newTag);
                    if ($ret[$a][2] == $c[0])
                        $ignore++;
                    $ret[$a][5] .= $bbcode->text;
                }
            } elseif ($token_type == 5) {
                if ($bbcode->text == '[/' . $ret[$a][2] . ']') {
                    if ($ignore == 0) {
                        $ret[$a][1] = '';
                        $ret[$a][4] = '';
                        $ret[$a][6] = '';
                        ksort($ret[$a]);
                        unset($tag);
                        $a++;
                    } elseif ($ignore > 0) {
                        $ret[$a][5] .= $bbcode->text;
                        $ignore--;
                    }
                } else {
                    $ret[$a][5] .= $bbcode->text;
                }
            } elseif ($token_type == 1) {
                $ret[$a][5] .= $bbcode->text;
            } elseif ($token_type == 2) { 
                $ret[$a][5] .= $bbcode->text;
            } elseif ($token_type == 3) {
                $ret[$a][5] .= $bbcode->text;
            }
        }
        
    }
    foreach ($ret as $value) {
        do_shortcode_tag($value);
    }
}

function get_shortcode_regex() {
    return '\\[(\\[?)(list|li|div|a)(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
}

function do_shortcode($content) {
    $pattern = get_shortcode_regex();
    return preg_replace_callback("/$pattern/s", 'do_shortcode_tag', $content);
}

function do_shortcode_tag($m) {
    return print_r($m, true);
}

$str = 'Quem se lembra do longo e apaixonante [url=http://www.forum.partidopiratapt.eu/index.php/topic,6.0.html]debate sobre o [/url][b][url=http://www.forum.partidopiratapt.eu/index.php/topic,6.0.html]Logótipo[/url][/b]? Foram [b]408 [/b]respostas e [b]6072 [/b]leituras…de longe o tema mais apaixonante de todos até agora porque se tratava de algo que desde sempre inspirou todos os movimentos, a simbologia. Algo que identifica um ideal sem serem precisas quaisquer palavras para o designar. Mas no meio do aceso debate a própria semântica da simbologia acabou por deturpar o nome do tópico ([url=http://www.forum.partidopiratapt.eu/index.php/topic,6.0.html][b]LogoTipo[/b] do Partido Pirata[/url]) e acabamos por estar a escolher não um [b]Logótipo [/b]mas sim um [b]Símbolo [/b]oficial para entregar no Tribunal Constitucional na altura da constituição do Partido. Essa mudança implicou restrições à criatividade, visto não se puderem incluir quaisquer símbolos nacionais ou até cores nacionais na concepção de um [b]Símbolo [/b]oficial de um Partido. A votação foi feita e um [b]Símbolo [/b]foi escolhido (e bem escolhido na minha opinião), mas o debate não se esgota, muito pelo contrário. Agora que há um [b]Símbolo [/b]oficial podemos retomar o debate e escolha de um [b]Logótipo[/b], ou até mais do que um para que se adaptem consoante os contextos em que se insiram. Lanço aqui um repto a todos os que participaram no anterior debate para que o voltem a fazer agora. Façam novas propostas de [b]Logótipos [/b]ou repesquem as vossas propostas antigas e apresentem-nas de novo tendo em conta o [b]Símbolo [/b]oficial que foi escolhido. Todas as outras restrições que havia no caso da escolha desse mesmo [b]Símbolo [/b]oficial já não se aplicam, pelo que podem usar as cores nacionais e símbolos nacionais extra se assim o desejarem. Dêem largas à imaginação e não se façam rogados. Participem! :) Eis alguns dos [b]Logótipos [/b]de outros partidos piratas que têm por base o [b]Símbolo [/b]oficial do Partido Pirata Sueco, que também foi o que adoptámos: [img]http://a3.twimg.com/profile_images/625707085/p_normal.png[/img][img]http://a1.twimg.com/profile_images/237916954/twitterp_normal.png[/img][img]http://a1.twimg.com/profile_images/334235276/Pirat_rund_normal.png[/img][img]http://a3.twimg.com/profile_images/264628671/pplogo_normal.png[/img][img]http://a3.twimg.com/profile_images/540323203/logo_normal.png[/img][img]http://a1.twimg.com/profile_images/465269164/PPI-signet_normal.png[/img][img]http://a1.twimg.com/profile_images/622162056/dox4p1_bigger_normal.png[/img][img]http://a1.twimg.com/profile_images/306546102/Picture_10_normal.png[/img][img]http://a1.twimg.com/profile_images/342953002/ppie_normal.png[/img][img]http://a3.twimg.com/profile_images/344128071/ppar2_normal.png[/img][img]http://a1.twimg.com/profile_images/261162726/463px-steag_150_normal.png[/img][img]http://a3.twimg.com/profile_images/294066165/ppbr_normal.png[/img][img]http://a3.twimg.com/profile_images/360429811/logo_block_copy_normal.jpg[/img]            Este já se afasta um pouquinho daquilo que é o [b]Símbolo [/b]oficial [img]http://a3.twimg.com/profile_images/313145681/logo_normal.png[/img] E este não tem nada a ver [img]http://a3.twimg.com/profile_images/437811261/tweeter_logo_normal.jpg[/img] Boa inspiração a todos :) ';
echo 'A minha solu&ccedil;&atilde;o --> <br />';
do_shortcodeppp($str);
echo 'Solu&ccedil;&atilde;o do wordpress --> <br />';
echo do_shortcode($str);
?>