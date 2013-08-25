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
//-----------------------------------------------------------------------------
//
	//  nbbc_lex.php
//
	//  This file is part of NBBC, the New BBCode Parser.
//
	//  NBBC implements a fully-validating, high-speed, extensible parser for the
//  BBCode document language.  Its output is XHTML 1.0 Strict conformant no
//  matter what its input is.  NBBC supports the full standard BBCode language,
//  as well as comments, columns, enhanced quotes, spoilers, acronyms, wiki
//  links, several list styles, justification, indentation, and smileys, among
//  other advanced features.
//
	//-----------------------------------------------------------------------------
//
	//  Copyright (c) 2008-9, the Phantom Inker.  All rights reserved.
//
	//  Redistribution and use in source and binary forms, with or without
//  modification, are permitted provided that the following conditions
//  are met:
//
	//    * Redistributions of source code must retain the above copyright
//       notice, this list of conditions and the following disclaimer.
//
	//    * Redistributions in binary form must reproduce the above copyright
//       notice, this list of conditions and the following disclaimer in
//       the documentation and/or other materials provided with the
//       distribution.
//
	//  THIS SOFTWARE IS PROVIDED BY THE PHANTOM INKER "AS IS" AND ANY EXPRESS
//  OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
//  WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
//  DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
//  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
//  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
//  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR
//  BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
//  WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
//  OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN
//  IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
//
	//-----------------------------------------------------------------------------
//
	//  This file implements the NBBC lexical analyzer, which breaks down the
//  input text from characters into tokens.  This uses an event-based
//  interface, somewhat like lex or flex uses, wherein each time
//  $this->NextToken is called, the next token is returned until it returns
//  BBCODE_EOI at the end of the input.
//
	//-----------------------------------------------------------------------------

class BBCodeLexer {

    var $token;   // Return token type:  One of the BBCODE_* constants.
    var $text;   // Actual exact, original text of token.
    var $tag;   // If token is a tag, this is the decoded array version.
    var $state;   // Next state of the lexer's state machine: text, or tag/ws/nl
    var $input;   // The input string, split into an array of tokens.
    var $ptr;   // Read pointer into the input array.
    var $unget;   // Whether to "unget" the last token.
    var $verbatim;  // In verbatim mode, we return all input, unparsed, including comments.
    var $debug;   // In debug mode, we dump decoded tags when we find them.
    var $tagmarker;  // Which kind of tag marker we're using:  "[", "<", "(", or "{"
    var $end_tagmarker; // The ending tag marker:  "]", ">", "(", or "{"
    var $pat_main;  // Main tag-matching pattern.
    var $pat_comment; // Pattern for matching comments.
    var $pat_comment2; // Pattern for matching comments.
    var $pat_wiki;  // Pattern for matching wiki-links.

    function BBCodeLexer($string, $tagmarker = '[') {
        // First thing we do is to split the input string into tuples of
        // text and tags.  This will make it easy to tokenize.  We define a tag as
        // anything starting with a [, ending with a ], and containing no [ or ] in
        // between unless surrounded by "" or '', and containing no newlines.
        // We also separate out whitespace and newlines.
        // Choose a tag marker based on the possible tag markers.
        $regex_beginmarkers = Array('[' => '\[', '<' => '<', '{' => '\{', '(' => '\(');
        $regex_endmarkers = Array('[' => '\]', '<' => '>', '{' => '\}', '(' => '\)');
        $endmarkers = Array('[' => ']', '<' => '>', '{' => '}', '(' => ')');
        if (!isset($regex_endmarkers[$tagmarker]))
            $tagmarker = '[';
        $e = $regex_endmarkers[$tagmarker];
        $b = $regex_beginmarkers[$tagmarker];
        $this->tagmarker = $tagmarker;
        $this->end_tagmarker = $endmarkers[$tagmarker];

        // $this->input will be an array of tokens, with the special property that
        // the elements strictly alternate between plain text and tags/whitespace/newlines,
        // and that tags always have *two* entries per tag.  The first element will
        // always be plain text.  Note that the regexes below make VERY heavy use of
        // PCRE regex-syntax extensions, so don't even try to modify them unless you
        // know how things like (?!) and (?:) and (?=) work.  We use the /x modifier
        // here to make this a *lot* more legible and debuggable.
        $this->pat_main = "/( "
                // Match tags, as long as they do not start with [-- or [' or [!-- or [rem or [[.
                // Tags may contain "quoted" or 'quoted' sections that may contain [ or ] characters.
                // Tags may not contain newlines.
                . "{$b}"
                . "(?! -- | ' | !-- | {$b}{$b} )"
                . "(?: [^\\n\\r{$b}{$e}] | \\\" [^\\\"\\n\\r]* \\\" | \\' [^\\'\\n\\r]* \\' )*"
                . "{$e}"

                // Match wiki-links, which are of the form [[...]] or [[...|...]].  Unlike
                // tags, wiki-links treat " and ' marks as normal input characters; but they
                // still may not contain newlines.
                . "| {$b}{$b} (?: [^{$e}\\r\\n] | {$e}[^{$e}\\r\\n] )* {$e}{$e}"

                // Match single-line comments, which start with [-- or [' or [rem .
                . "| {$b} (?: -- | ' ) (?: [^{$e}\\n\\r]* ) {$e}"

                // Match multi-line comments, which start with [!-- and end with --] and contain
                // no --] in between.
                . "| {$b}!-- (?: [^-] | -[^-] | --[^{$e}] )* --{$e}"

                // Match five or more hyphens as a special token, which gets returned as a [rule] tag.
                . "| -----+"

                // Match newlines, in all four possible forms.
                . "| \\x0D\\x0A | \\x0A\\x0D | \\x0D | \\x0A"

                // Match whitespace, but only if it butts up against a newline, rule, or
                // bracket on at least one end.
                . "| [\\x00-\\x09\\x0B-\\x0C\\x0E-\\x20]+(?=[\\x0D\\x0A{$b}]|-----|$)"
                . "| (?<=[\\x0D\\x0A{$e}]|-----|^)[\\x00-\\x09\\x0B-\\x0C\\x0E-\\x20]+"
                . " )/Dx";

        $this->input = preg_split($this->pat_main, $string, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Patterns for matching specific types of tokens during lexing.
        $this->pat_comment = "/^ {$b} (?: -- | ' ) /Dx";
        $this->pat_comment2 = "/^ {$b}!-- (?: [^-] | -[^-] | --[^{$e}] )* --{$e} $/Dx";
        $this->pat_wiki = "/^ {$b}{$b} ([^\\|]*) (?:\\|(.*))? {$e}{$e} $/Dx";

        // Current lexing state.
        $this->ptr = 0;
        $this->unget = false;
        $this->state = BBCODE_LEXSTATE_TEXT;
        $this->verbatim = false;

        // Return values.
        $this->token = BBCODE_EOI;
        $this->tag = false;
        $this->text = "";
    }

    // Compute how many non-tag characters there are in the input, give or take a few.
    // This is optimized for speed, not accuracy, so it'll get some stuff like
    // horizontal rules and weird whitespace characters wrong, but it's only supposed
    // to provide a rough quick guess, not a hard fact.
    function GuessTextLength() {
        $length = 0;
        $ptr = 0;
        $state = BBCODE_LEXSTATE_TEXT;

        // Loop until we find a valid (nonempty) token.
        while ($ptr < count($this->input)) {
            $text = $this->input[$ptr++];

            if ($state == BBCODE_LEXSTATE_TEXT) {
                $state = BBCODE_LEXSTATE_TAG;
                $length += strlen($text);
            } else {
                switch (ord(substr($this->text, 0, 1))) {
                    case 10:
                    case 13:
                        $state = BBCODE_LEXSTATE_TEXT;
                        $length++;
                        break;
                    default:
                        $state = BBCODE_LEXSTATE_TEXT;
                        $length += strlen($text);
                        break;
                    case 40:
                    case 60:
                    case 91:
                    case 123:
                        $state = BBCODE_LEXSTATE_TEXT;
                        break;
                }
            }
        }

        return $length;
    }

    // Return the type of the next token, either BBCODE_TAG or BBCODE_TEXT or
    // BBCODE_EOI.  This stores the content of this token into $this->text, the
    // type of this token in $this->token, and possibly an array into $this->tag.
    //
		// If this is a BBCODE_TAG token, $this->tag will be an array computed from
    // the tag's contents, like this:
    //    Array(
    //       '_name' => tag_name,
    //       '_end' => true if this is an end tag (i.e., the name starts with a /)
    //       '_default' => default value (for example, in [url=foo], this is "foo").
    //       ...
    //       ...all other key => value parameters given in the tag...
    //       ...
    //    )
    function NextToken() {

        // Handle ungets; if the last token has been "ungotten", just return it again.
        if ($this->unget) {
            $this->unget = false;
            return $this->token;
        }

        // Loop until we find a valid (nonempty) token.
        while (true) {

            // Did we run out of tokens in the input?
            if ($this->ptr >= count($this->input)) {
                $this->text = "";
                $this->tag = false;
                return $this->token = BBCODE_EOI;
            }

            // Inhale one token, sanitizing away any weird control characters.  We
            // allow \t, \r, and \n to pass through, but that's it.
            $this->text = preg_replace("/[\\x00-\\x08\\x0B-\\x0C\\x0E-\\x1F]/", "", $this->input[$this->ptr++]);

            if ($this->verbatim) {

                // In verbatim mode, we return *everything* as plain text or whitespace.
                $this->tag = false;
                if ($this->state == BBCODE_LEXSTATE_TEXT) {
                    $this->state = BBCODE_LEXSTATE_TAG;
                    $token_type = BBCODE_TEXT;
                } else {
                    // This must be either whitespace, a newline, or a tag.
                    $this->state = BBCODE_LEXSTATE_TEXT;
                    switch (ord(substr($this->text, 0, 1))) {
                        case 10:
                        case 13:
                            // Newline.
                            $token_type = BBCODE_NL;
                            break;
                        default:
                            // Whitespace.
                            $token_type = BBCODE_WS;
                            break;
                        case 45:
                        case 40:
                        case 60:
                        case 91:
                        case 123:
                            // Tag or comment.
                            $token_type = BBCODE_TEXT;
                            break;
                    }
                }

                if (strlen($this->text) > 0)
                    return $this->token = $token_type;
            }
            else if ($this->state == BBCODE_LEXSTATE_TEXT) {
                // Next up is plain text, but only return it if it's nonempty.
                $this->state = BBCODE_LEXSTATE_TAG;
                $this->tag = false;
                if (strlen($this->text) > 0)
                    return $this->token = BBCODE_TEXT;
            }
            else {
                // This must be either whitespace, a newline, or a tag.
                switch (ord(substr($this->text, 0, 1))) {
                    case 10:
                    case 13:
                        // Newline.
                        $this->tag = false;
                        $this->state = BBCODE_LEXSTATE_TEXT;
                        return $this->token = BBCODE_NL;
                    case 45:
                        // A rule made of hyphens; return it as a [rule] tag.
                        if (preg_match("/^-----/", $this->text)) {
                            $this->tag = Array('_name' => 'rule', '_endtag' => false, '_default' => '');
                            $this->state = BBCODE_LEXSTATE_TEXT;
                            return $this->token = BBCODE_TAG;
                        } else {
                            $this->tag = false;
                            $this->state = BBCODE_LEXSTATE_TEXT;
                            if (strlen($this->text) > 0)
                                return $this->token = BBCODE_TEXT;
                            continue;
                        }
                    default:
                        // Whitespace.
                        $this->tag = false;
                        $this->state = BBCODE_LEXSTATE_TEXT;
                        return $this->token = BBCODE_WS;
                    case 40:
                    case 60:
                    case 91:
                    case 123:
                        // Tag or comment.  This is the most complicated one, because it
                        // needs to be parsed into its component pieces.
                        // See if this is a comment; if so, skip it.
                        if (preg_match($this->pat_comment, $this->text)) {
                            // This is a comment, not a tag, so treat it like it doesn't exist.
                            $this->state = BBCODE_LEXSTATE_TEXT;
                            continue;
                        }
                        if (preg_match($this->pat_comment2, $this->text)) {
                            // This is a comment, not a tag, so treat it like it doesn't exist.
                            $this->state = BBCODE_LEXSTATE_TEXT;
                            continue;
                        }

                        // See if this is a [[wiki link]]; if so, convert it into a [wiki="" title=""] tag.
                        if (preg_match($this->pat_wiki, $this->text, $matches)) {
                            $this->tag = Array('_name' => 'wiki', '_endtag' => false,
                                '_default' => @$matches[1], 'title' => @$matches[2]);
                            $this->state = BBCODE_LEXSTATE_TEXT;
                            return $this->token = BBCODE_TAG;
                        }

                        // Not a comment, so parse it like a tag.
                        $this->tag = $this->Internal_DecodeTag($this->text);
                        $this->state = BBCODE_LEXSTATE_TEXT;
                        return $this->token = ($this->tag['_end'] ? BBCODE_ENDTAG : BBCODE_TAG);
                }
            }
        }
    }

    // Ungets the last token read so that a subsequent call to NextToken() will
    // return it.  Note that UngetToken() does not switch states when you switch
    // between verbatim mode and standard mode:  For example, if you read a tag,
    // unget the tag, switch to verbatim mode, and then get the next token, you'll
    // get back a BBCODE_TAG --- exactly what you ungot, not a BBCODE_TEXT token.
    function UngetToken() {
        if ($this->token !== BBCODE_EOI)
            $this->unget = true;
    }

    // Peek at the next token, but don't remove it.
    function PeekToken() {
        $result = $this->NextToken();
        if ($this->token !== BBCODE_EOI)
            $this->unget = true;
        return $result;
    }

    // Save the state of this lexer so it can be restored later.  The return
    // value from this should be considered opaque.  Because PHP uses copy-on-write
    // references, the total cost of the returned state is relatively small, and
    // the running time of this function (and RestoreState) is very fast.
    function SaveState() {
        return Array(
            'token' => $this->token,
            'text' => $this->text,
            'tag' => $this->tag,
            'state' => $this->state,
            'input' => $this->input,
            'ptr' => $this->ptr,
            'unget' => $this->unget,
            'verbatim' => $this->verbatim
        );
    }

    // Restore the state of this lexer from a saved previous state.
    function RestoreState($state) {
        if (!is_array($state))
            return;
        $this->token = @$state['token'];
        $this->text = @$state['text'];
        $this->tag = @$state['tag'];
        $this->state = @$state['state'];
        $this->input = @$state['input'];
        $this->ptr = @$state['ptr'];
        $this->unget = @$state['unget'];
        $this->verbatim = @$state['verbatim'];
    }

    // Given a string, if it's surrounded by "quotes" or 'quotes', remove them.
    function Internal_StripQuotes($string) {
        if (preg_match("/^\\\"(.*)\\\"$/", $string, $matches))
            return $matches[1];
        else if (preg_match("/^\\'(.*)\\'$/", $string, $matches))
            return $matches[1];
        else
            return $string;
    }

    // Given a tokenized piece of a tag, decide what type of token it is.  Our
    // return values are:
    //    -1    End-of-input (EOI).
    //    '='   Token is an = sign.
    //    ' '   Token is whitespace.
    //    '"'   Token is quoted text.
    //    'A'   Token is unquoted text.
    function Internal_ClassifyPiece($ptr, $pieces) {
        if ($ptr >= count($pieces))
            return -1; // EOI.
        $piece = $pieces[$ptr];
        if ($piece == '=')
            return '=';
        else if (preg_match("/^[\\'\\\"]/", $piece))
            return '"';
        else if (preg_match("/^[\\x00-\\x20]+$/", $piece))
            return ' ';
        else
            return 'A';
    }

    // Given a string containing a complete [tag] (including its brackets), break
    // it down into its components and return them as an array.
    function Internal_DecodeTag($tag) {

        if ($this->debug) {
            print "<b>Lexer::InternalDecodeTag:</b> input: " . htmlspecialchars($tag) . "<br />\n";
        }

        // Create the initial result object.
        $result = Array('_tag' => $tag, '_endtag' => '', '_name' => '',
            '_hasend' => false, '_end' => false, '_default' => false);

        // Strip off the [brackets] around the tag, leaving just its content.
        $tag = substr($tag, 1, strlen($tag) - 2);

        // The starting bracket *must* be followed by a non-whitespace character.
        $ch = ord(substr($tag, 0, 1));
        if ($ch >= 0 && $ch <= 32)
            return $result;

        // Break it apart into words, quoted text, whitespace, and equal signs.
        $pieces = preg_split("/(\\\"[^\\\"]+\\\"|\\'[^\\']+\\'|=|[\\x00-\\x20]+)/", $tag, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $ptr = 0;

        // Handle malformed (empty) tags correctly.
        if (count($pieces) < 1)
            return $result;

        // The first piece should be the tag name, whatever it is.  If it starts with a /
        // we remove the / and mark it as an end tag.
        if (@substr($pieces[$ptr], 0, 1) == '/') {
            $result['_name'] = strtolower(substr($pieces[$ptr++], 1));
            $result['_end'] = true;
        } else {
            $result['_name'] = strtolower($pieces[$ptr++]);
            $result['_end'] = false;
        }

        // Skip whitespace after the tag name.
        while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) == ' ')
            $ptr++;

        $params = Array();

        // If the next piece is an equal sign, then the tag's default value follows.
        if ($type != '=') {
            $result['_default'] = false;
            $params[] = Array('key' => '', 'value' => '');
        } else {
            $ptr++;

            // Skip whitespace after the initial equal-sign.
            while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) == ' ')
                $ptr++;

            // Examine the next (real) piece, and see if it's quoted; if not, we need to
            // use heuristics to guess where the default value begins and ends.
            if ($type == "\"")
                $value = $this->Internal_StripQuotes($pieces[$ptr++]);
            else {
                // Collect pieces going forward until we reach an = sign or the end of the
                // tag; then rewind before whatever comes before the = sign, and everything
                // between here and there becomes the default value.  This allows tags like
                // [font=Times New Roman size=4] to make sense even though the font name is
                // not quoted.  Note, however, that there's a special initial case, where
                // any equal-signs before whitespace are considered to be part of the parameter
                // as well; this allows an ugly tag like [url=http://foo?bar=baz target=my_window]
                // to behave in a way that makes (tolerable) sense.
                $after_space = false;
                $start = $ptr;
                while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) != -1) {
                    if ($type == ' ')
                        $after_space = true;
                    if ($type == '=' && $after_space)
                        break;
                    $ptr++;
                }
                if ($type == -1)
                    $ptr--;

                // We've now found the first (appropriate) equal-sign after the start of the
                // default value.  (In the example above, that's the "=" after "target".)  We
                // now have to rewind back to the last whitespace to find where the default
                // value ended.
                if ($type == '=') {
                    // Rewind before = sign.
                    $ptr--;
                    // Rewind before any whitespace before = sign.
                    while ($ptr > $start && $this->Internal_ClassifyPiece($ptr, $pieces) == ' ')
                        $ptr--;
                    // Rewind before any text elements before that.
                    while ($ptr > $start && $this->Internal_ClassifyPiece($ptr, $pieces) != ' ')
                        $ptr--;
                }

                // The default value is everything from $start to $ptr, inclusive.
                $value = "";
                for (; $start <= $ptr; $start++) {
                    if ($this->Internal_ClassifyPiece($start, $pieces) == ' ')
                        $value .= " ";
                    else
                        $value .= $this->Internal_StripQuotes($pieces[$start]);
                }
                $value = trim($value);

                $ptr++;
            }

            $result['_default'] = $value;
            $params[] = Array('key' => '', 'value' => $value);
        }

        // The rest of the tag is composed of either floating keys or key=value pairs, so walk through
        // the tag and collect them all.  Again, we have the nasty special case where an equal sign
        // in a parameter but before whitespace counts as part of that parameter.
        while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) != -1) {

            // Skip whitespace before the next key name.
            while ($type == ' ') {
                $ptr++;
                $type = $this->Internal_ClassifyPiece($ptr, $pieces);
            }

            // Decode the key name.
            if ($type == 'A' || $type == '"')
                $key = strtolower($this->Internal_StripQuotes(@$pieces[$ptr++]));
            else if ($type == '=') {
                $ptr++;
                continue;
            } else if ($type == -1)
                break;

            // Skip whitespace after the key name.
            while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) == ' ')
                $ptr++;

            // If an equal-sign follows, we need to collect a value.  Otherwise, we
            // take the key itself as the value.
            if ($type != '=')
                $value = $this->Internal_StripQuotes($key);
            else {
                $ptr++;
                // Skip whitespace after the equal sign.
                while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) == ' ')
                    $ptr++;
                if ($type == '"') {
                    // If we get a quoted value, take that as the only value.
                    $value = $this->Internal_StripQuotes($pieces[$ptr++]);
                } else if ($type != -1) {
                    // If we get a non-quoted value, consume non-quoted values
                    // until we reach whitespace.
                    $value = $pieces[$ptr++];
                    while (($type = $this->Internal_ClassifyPiece($ptr, $pieces)) != -1 && $type != ' ')
                        $value .= $pieces[$ptr++];
                }
                else
                    $value = "";
            }

            // Record this in the associative array if it's a legal public identifier name.
            // Legal *public* identifier names must *not* begin with an underscore.
            if (substr($key, 0, 1) != '_')
                $result[$key] = $value;

            // Record this in the parameter list always.
            $params[] = Array('key' => $key, 'value' => $value);
        }

        // Add the parameter list as a member of the associative array.
        $result['_params'] = $params;

        if ($this->debug) {
            // In debugging modes, output the tag as we collected it.
            print "<b>Lexer::InternalDecodeTag:</b> output: ";
            ob_start();
            print_r($result);
            $output = ob_get_clean();
            print htmlspecialchars($output) . "<br />\n";
        }

        // Save the resulting parameters, and return the whole shebang.
        return $result;
    }

}

/**
 * WordPress API for creating bbcode like tags or what WordPress calls
 * "shortcodes." The tag and attribute parsing or regular expression code is
 * based on the Textpattern tag parser.
 *
 * A few examples are below:
 *
 * [shortcode /]
 * [shortcode foo="bar" baz="bing" /]
 * [shortcode foo="bar"]content[/shortcode]
 *
 * Shortcode tags support attributes and enclosed content, but does not entirely
 * support inline shortcodes in other shortcodes. You will have to call the
 * shortcode parser in your function to account for that.
 *
 * {@internal
 * Please be aware that the above note was made during the beta of WordPress 2.6
 * and in the future may not be accurate. Please update the note when it is no
 * longer the case.}}
 *
 * To apply shortcode tags to content:
 *
 * <code>
 * $out = do_shortcode($content);
 * </code>
 *
 * @link http://codex.wordpress.org/Shortcode_API
 *
 * @package WordPress
 * @subpackage Shortcodes
 * @since 2.5
 */
/**
 * Container for storing shortcode tags and their hook to call for the shortcode
 *
 * @since 2.5
 * @name $shortcode_tags
 * @var array
 * @global array $shortcode_tags
 */
$shortcode_tags = array();

/**
 * Add hook for shortcode tag.
 *
 * There can only be one hook for each shortcode. Which means that if another
 * plugin has a similar shortcode, it will override yours or yours will override
 * theirs depending on which order the plugins are included and/or ran.
 *
 * Simplest example of a shortcode tag using the API:
 *
 * <code>
 * // [footag foo="bar"]
 * function footag_func($atts) {
 * 	return "foo = {$atts[foo]}";
 * }
 * add_shortcode('footag', 'footag_func');
 * </code>
 *
 * Example with nice attribute defaults:
 *
 * <code>
 * // [bartag foo="bar"]
 * function bartag_func($atts) {
 * 	extract(shortcode_atts(array(
 * 		'foo' => 'no foo',
 * 		'baz' => 'default baz',
 * 	), $atts));
 *
 * 	return "foo = {$foo}";
 * }
 * add_shortcode('bartag', 'bartag_func');
 * </code>
 *
 * Example with enclosed content:
 *
 * <code>
 * // [baztag]content[/baztag]
 * function baztag_func($atts, $content='') {
 * 	return "content = $content";
 * }
 * add_shortcode('baztag', 'baztag_func');
 * </code>
 *
 * @since 2.5
 * @uses $shortcode_tags
 *
 * @param string $tag Shortcode tag to be searched in post content.
 * @param callable $func Hook to run when shortcode is found.
 */
function add_shortcode($tag, $func) {
    global $shortcode_tags;

    if (is_callable($func))
        $shortcode_tags[$tag] = $func;
}

/**
 * Removes hook for shortcode.
 *
 * @since 2.5
 * @uses $shortcode_tags
 *
 * @param string $tag shortcode tag to remove hook for.
 */
function remove_shortcode($tag) {
    global $shortcode_tags;

    unset($shortcode_tags[$tag]);
}

/**
 * Clear all shortcodes.
 *
 * This function is simple, it clears all of the shortcode tags by replacing the
 * shortcodes global by a empty array. This is actually a very efficient method
 * for removing all shortcodes.
 *
 * @since 2.5
 * @uses $shortcode_tags
 */
function remove_all_shortcodes() {
    global $shortcode_tags;

    $shortcode_tags = array();
}

/**
 * Whether a registered shortcode exists named $tag
 *
 * @since 3.6.0
 *
 * @global array $shortcode_tags
 * @param string $tag
 * @return boolean
 */
function shortcode_exists($tag) {
    global $shortcode_tags;
    return array_key_exists($tag, $shortcode_tags);
}

/**
 * Whether the passed content contains the specified shortcode
 *
 * @since 3.6.0
 *
 * @global array $shortcode_tags
 * @param string $tag
 * @return boolean
 */
function has_shortcode($content, $tag) {
    if (shortcode_exists($tag)) {
        preg_match_all('/' . get_shortcode_regex() . '/s', $content, $matches, PREG_SET_ORDER);
        if (empty($matches))
            return false;

        foreach ($matches as $shortcode) {
            if ($tag === $shortcode[2])
                return true;
        }
    }
    return false;
}

/**
 * Search content for shortcodes and filter shortcodes through their hooks.
 *
 * If there are no shortcode tags defined, then the content will be returned
 * without any filtering. This might cause issues when plugins are disabled but
 * the shortcode will still show up in the post or content.
 *
 * @since 2.5
 * @uses $shortcode_tags
 * @uses get_shortcode_regex() Gets the search pattern for searching shortcodes.
 *
 * @param string $content Content to search for shortcodes
 * @return string Content with shortcodes filtered out.
 */
function do_shortcode($str) {
    global $shortcode_tags;
    if (empty($shortcode_tags) || !is_array($shortcode_tags))
        return $str;
    $tagnames = array_keys($shortcode_tags);
    $ret = array();
    $i = strpos($str, '[');
    if ($i !== FALSE) {
        $result = substr($str, 0, -(strlen($str)-$i));
        $str = substr($str, $i);
        $ignore = 0;
        $bbcode = new BBCodeLexer($str);
        while (($token_type = $bbcode->NextToken()) != 0) {
            $ret[0] .= $bbcode->text;
            if ($token_type == 4) {
                if (!isset($tag)) {
                    $tag = substr($bbcode->text, 1, strlen($bbcode->text) - 2);
                    $i = strpos($tag, ' ');
                    if ($i !== FALSE) {
                        $m = explode(' ', $tag);
                        $ret[2] = $m[0];
                        unset($m[0]);
                        $ret[3] = ' ' . implode(' ', array_values($m));
                    } else {
                        $m = explode('=', $tag);
                        $ret[2] = $m[0];
                        unset($m[0]);
                        $ret[3] = '=' . $m[1];
                    }
                    if (!in_array($ret[2], $tagnames)) {
                        unset($tag);
                        $result .= $bbcode->text;
                        $ret = array();
                    }
                } else {
                    $newTag = substr($bbcode->text, 1, strlen($bbcode->text) - 2);
                    $c = explode(' ', $newTag);
                    if ($ret[2] == $c[0])
                        $ignore++;
                    $ret[5] .= $bbcode->text;
                }
            } elseif ($token_type == 5) {
                if ($bbcode->text == '[/' . $ret[2] . ']') {
                    if ($ignore == 0) {
                        $ret[1] = '';
                        $ret[4] = '';
                        $ret[6] = '';
                        ksort($ret);
                        unset($tag);
                        $result .= do_shortcode_tag($ret);
                        $ret = array();
                    } elseif ($ignore > 0) {
                        $ret[5] .= $bbcode->text;
                        $ignore--;
                    }
                } else {
                    $ret[5] .= $bbcode->text;
                }
            } elseif ($token_type == 1) {
                if (isset($tag))
                    $ret[5] .= $bbcode->text;
                else
                    $result .= $bbcode->text;
            } elseif ($token_type == 2) {
                if (isset($tag))
                    $ret[5] .= $bbcode->text;
                else
                    $result .= $bbcode->text;
            } elseif ($token_type == 3) {
                if (isset($tag))
                    $ret[5] .= $bbcode->text;
                else
                    $result .= $bbcode->text;
            }
        }
    } else
        return $str;
    return $result;
}

/**
 * Retrieve the shortcode regular expression for searching.
 *
 * The regular expression combines the shortcode tags in the regular expression
 * in a regex class.
 *
 * The regular expression contains 6 different sub matches to help with parsing.
 *
 * 1 - An extra [ to allow for escaping shortcodes with double [[]]
 * 2 - The shortcode name
 * 3 - The shortcode argument list
 * 4 - The self closing /
 * 5 - The content of a shortcode when it wraps some content.
 * 6 - An extra ] to allow for escaping shortcodes with double [[]]
 *
 * @since 2.5
 * @uses $shortcode_tags
 *
 * @return string The shortcode search regular expression
 */
function get_shortcode_regex() {
    global $shortcode_tags;
    $tagnames = array_keys($shortcode_tags);
    $tagregexp = join('|', array_map('preg_quote', $tagnames));

    // WARNING! Do not change this regex without changing do_shortcode_tag() and strip_shortcode_tag()
    // Also, see shortcode_unautop() and shortcode.js.
    return
            '\\['                              // Opening bracket
            . '(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]]
            . "($tagregexp)"                     // 2: Shortcode name
            . '(?![\\w-])'                       // Not followed by word character or hyphen
            . '('                                // 3: Unroll the loop: Inside the opening shortcode tag
            . '[^\\]\\/]*'                   // Not a closing bracket or forward slash
            . '(?:'
            . '\\/(?!\\])'               // A forward slash not followed by a closing bracket
            . '[^\\]\\/]*'               // Not a closing bracket or forward slash
            . ')*?'
            . ')'
            . '(?:'
            . '(\\/)'                        // 4: Self closing tag ...
            . '\\]'                          // ... and closing bracket
            . '|'
            . '\\]'                          // Closing bracket
            . '(?:'
            . '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags
            . '[^\\[]*+'             // Not an opening bracket
            . '(?:'
            . '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag
            . '[^\\[]*+'         // Not an opening bracket
            . ')*+'
            . ')'
            . '\\[\\/\\2\\]'             // Closing shortcode tag
            . ')?'
            . ')'
            . '(\\]?)';                          // 6: Optional second closing brocket for escaping shortcodes: [[tag]]
}

/**
 * Regular Expression callable for do_shortcode() for calling shortcode hook.
 * @see get_shortcode_regex for details of the match array contents.
 *
 * @since 2.5
 * @access private
 * @uses $shortcode_tags
 *
 * @param array $m Regular expression match array
 * @return mixed False on failure.
 */
function do_shortcode_tag($m) {
    global $shortcode_tags;

    // allow [[foo]] syntax for escaping a tag
    if ($m[1] == '[' && $m[6] == ']') {
        return substr($m[0], 1, -1);
    }

    $tag = $m[2];
    $attr = shortcode_parse_atts($m[3]);

    if (isset($m[5])) {
        // enclosing tag - extra parameter
        return $m[1] . call_user_func($shortcode_tags[$tag], $attr, $m[5], $tag) . $m[6];
    } else {
        // self-closing tag
        return $m[1] . call_user_func($shortcode_tags[$tag], $attr, null, $tag) . $m[6];
    }
}

/**
 * Retrieve all attributes from the shortcodes tag.
 *
 * The attributes list has the attribute name as the key and the value of the
 * attribute as the value in the key/value pair. This allows for easier
 * retrieval of the attributes, since all attributes have to be known.
 *
 * @since 2.5
 *
 * @param string $text
 * @return array List of attributes and their value.
 */
function shortcode_parse_atts($text) {
    $atts = array();
    $pattern = '/(\w+)\s*=\s*"([^"]*)"(?:\s|$)|(\w+)\s*=\s*\'([^\']*)\'(?:\s|$)|(\w+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|(\S+)(?:\s|$)/';
    $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);
    if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
        foreach ($match as $m) {
            if (!empty($m[1]))
                $atts[strtolower($m[1])] = stripcslashes($m[2]);
            elseif (!empty($m[3]))
                $atts[strtolower($m[3])] = stripcslashes($m[4]);
            elseif (!empty($m[5]))
                $atts[strtolower($m[5])] = stripcslashes($m[6]);
            elseif (isset($m[7]) and strlen($m[7]))
                $atts[] = stripcslashes($m[7]);
            elseif (isset($m[8]))
                $atts[] = stripcslashes($m[8]);
        }
    } else {
        $atts = ltrim($text);
    }
    return $atts;
}

/**
 * Combine user attributes with known attributes and fill in defaults when needed.
 *
 * The pairs should be considered to be all of the attributes which are
 * supported by the caller and given as a list. The returned attributes will
 * only contain the attributes in the $pairs list.
 *
 * If the $atts list has unsupported attributes, then they will be ignored and
 * removed from the final returned list.
 *
 * @since 2.5
 *
 * @param array $pairs Entire list of supported attributes and their defaults.
 * @param array $atts User defined attributes in shortcode tag.
 * @param string $shortcode Optional. The name of the shortcode, provided for context to enable filtering
 * @return array Combined and filtered attribute list.
 */
function shortcode_atts($pairs, $atts, $shortcode = '') {
    $atts = (array) $atts;
    $out = array();
    foreach ($pairs as $name => $default) {
        if (array_key_exists($name, $atts))
            $out[$name] = $atts[$name];
        else
            $out[$name] = $default;
    }

    if ($shortcode)
        $out = apply_filters("shortcode_atts_{$shortcode}", $out, $pairs, $atts);

    return $out;
}

/**
 * Remove all shortcode tags from the given content.
 *
 * @since 2.5
 * @uses $shortcode_tags
 *
 * @param string $content Content to remove shortcode tags.
 * @return string Content without shortcode tags.
 */
function strip_shortcodes($content) {
    global $shortcode_tags;

    if (empty($shortcode_tags) || !is_array($shortcode_tags))
        return $content;

    $pattern = get_shortcode_regex();

    return preg_replace_callback("/$pattern/s", 'strip_shortcode_tag', $content);
}

function strip_shortcode_tag($m) {
    // allow [[foo]] syntax for escaping a tag
    if ($m[1] == '[' && $m[6] == ']') {
        return substr($m[0], 1, -1);
    }

    return $m[1] . $m[6];
}

add_filter('the_content', 'do_shortcode', 11); // AFTER wpautop()
