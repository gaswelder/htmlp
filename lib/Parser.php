<?php
namespace gaswelder\htmlparser;

use gaswelder\htmlparser\dom\DocumentNode;
use gaswelder\htmlparser\dom\CommentNode;
use gaswelder\htmlparser\dom\TextNode;
use gaswelder\htmlparser\dom\ElementNode;

const UTF8_BOM = "\xEF\xBB\xBF";

class Parser
{
	const alpha = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
	const num = "0123456789";
	const spaces = "\r\n\t ";

	private static $singles = array(
		'hr',
		'img',
		'br',
		'meta',
		'link',
		'input',
		'base'
	);

	/*
	 * Parsing options and their defaults
	 */
	private $options;
	private static $def = array(
		'xml_perversion' => true,
		'single_quotes' => true,
		'missing_quotes' => false
	);

	/*
	 * DocumentNode object that we will return on success
	 */
	private $doc;

	/*
	 * html tokens stream
	 */
	private $s;

	function __construct($options = array())
	{
		/*
		 * Fill in defaults options where needed
		 */
		$k = array_diff(array_keys($options), array_keys(self::$def));
		if (!empty($k)) {
			throw new \Exception("Unknown options: ".implode(', ', $k));
		}
		foreach (self::$def as $k => $v) {
			if (!isset($options[$k])) {
				$options[$k] = $v;
			}
		}
		$this->options = $options;
	}

	function parse($s)
	{
		/*
		 * Skip UTF-8 marker if it's present
		 */
		if (substr($s, 0, 3) == UTF8_BOM) {
			$s = substr($s, 3);
		}

		$this->s = new tokstream($s);
		$this->doc = new DocumentNode();

		$t = $this->tok();
		if (!$t) {
			return $this->error("No data");
		}
		if ($t->type != token::DOCTYPE) {
			return $this->error("Missing doctype");
		}

		$this->doc->type = $t->content;

		$tree = $this->parse_subtree();
		if (!$tree) {
			return $this->error("Empty document");
		}

		$this->doc->appendChild($tree);

		$this->skip_empty_text();
		if ($this->s->more()) {
			$tok = $this->s->peek();
			return $this->error("Only one root element allowed, $tok");
		}
		return $this->doc;
	}

	/*
	 * Returns next "significant" token from the stream.
	 * Non-significant tokens (spaces and comments) that are
	 * encountered are automatically added to the document tree.
	 */
	private function tok()
	{
		while (1) {
			$t = $this->s->get();
			if (!$t) return null;

			if ($t->type == token::COMMENT) {
				$this->doc->appendChild(new CommentNode($t->content));
				continue;
			}

			if ($t->type == token::TEXT && ctype_space($t->content)) {
				continue;
			}

			return $t;
		}
	}

	private function skip_empty_text()
	{
		while ($t = $this->s->peek()) {
			if ($t->type == token::TEXT && ctype_space($t->content)) {
				$this->s->get();
				continue;
			}

			if ($t->type == token::COMMENT) {
				$this->s->get();
				continue;
			}

			break;
		}
	}

	/*
	 * Returns a subtree or just one element if that element is not a
	 * container. Assumes that the next token will be a tag. If not,
	 * returns null;
	 */
	private function parse_subtree($parent_element = null)
	{
		$tok = $this->tok();
		if (!$tok || $tok->type != token::TAG) {
			return $this->error("No tag in the stream ($tok)", $this->s->pos());
		}

		/*
		 * This must be an opening tag.
		 */
		if (strpos($tok->content, '</') === 0) {
			$msg = "Unexpected closing tag ($tok->content)";
			if($parent_element) {
				$msg .= " (inside $parent_element->tagName)";
			}
			return $this->error($msg, $this->s->pos());
		}

		/*
		 * Parse the tag token into an element.
		 */
		$element = $this->parse_tag($tok);
		if (!$element) {
			return $this->error("Couldn't parse the tag ($tok->content)",
				$this->s->pos());
		}

		/*
		 * If the element is not a container kind, return the element.
		 */
		if (in_array(strtolower($element->tagName), self::$singles)) {
			return $element;
		}

		/*
		 * Closing tag that we will expect
		 */
		$close = strtolower("</".$element->tagName.">");

		/*
		 * Process the tokens that will correspond to child nodes of
		 * the current element.
		 */
		while ($tok = $this->tok()) {
			/*
			 * If this is our closing tag, put it back and exit the
			 * loop.
			 */
			if ($tok->type == token::TAG && strtolower($tok->content) == $close) {
				$this->s->unget($tok);
				break;
			}

			/*
			 * Convert whatever comes in into a node and append as
			 * a child to the tree.
			 */
			switch ($tok->type) {
			case token::TEXT:
				$element->appendChild(new TextNode($tok->content));
				break;
			case token::TAG:
				$this->s->unget($tok);
				$subtree = $this->parse_subtree($element);
				if (!$subtree){
					return $this->error("Subtree failed");
				}
				$element->appendChild($subtree);
				break;
			default:
				return $this->error("Unexpected token: $tok", $tok->pos);
			}
		}

		$tok = $this->s->peek();
		if (!$tok || $tok->type != token::TAG || strtolower($tok->content) != $close) {
			return $this->error("$close expected", $this->s->pos());
		}
		$this->s->get();
		return $element;
	}

	/*
	 * Parses a tag string and returns a corresponding element.
	 */
	private function parse_tag(token $tok)
	{
		/*
		 * This is a parser inside a parser, so we create another
		 * stream to work with.
		 */
		$s = new parsebuf($tok->content, $tok->pos);

		if ($s->get() != '<') {
			return $this->error("'<' expected", $tok->pos);
		}

		/*
		 * Read the tag name.
		 */
		$name = $s->get();
		if (!$name || strpos(self::alpha, $name) === false) {
			return $this->error("Tag name expected", $s->pos());
		}
		$name .= $s->read_set(self::alpha.self::num);

		$element = new ElementNode($name);

		/*
		 * Read attributes, one pair/flag at a time.
		 */
		while (ctype_space($s->peek())) {
			$s->read_set(self::spaces);
			list($name, $val) = $this->tagattr($s);
			if (!$name) {
				break;
			}
			$element->setAttribute($name, $val);
		}

		if ($this->options['xml_perversion'] && $s->peek() == '/') {
			$s->get();
		}

		$ch = $s->get();
		if ($ch != '>') {
			return $this->error("'>' expected, got '$ch'", $s->pos());
		}

		return $element;
	}

	private function tagattr(parsebuf $s)
	{
		/*
		 * Read attribute name.
		 */
		$name = $s->read_set(self::alpha.'-_0123456789');
		if (!$name) {
			return array(null, null);
		}

		/*
		 * If no '=' follows, this is a flag only.
		 */
		if ($s->peek() != '=') {
			return array($name, true);
		}
		$s->get();

		/*
		 * Read the value.
		 */
		$val = $this->tagval($s);
		if ($val === null) {
			return array(null, null);
		}

		return array($name, $val);
	}

	private function tagval(parsebuf $s)
	{
		if ($s->peek() == '"') {
			$s->get();
			$val = $s->skip_until('"');
			if ($s->get() != '"') {
				return $this->error("'\"' expected", $s->pos());
			}
			return $val;
		}

		if ($this->options['missing_quotes'] && ctype_alpha($s->peek())) {
			return $s->read_set(self::alpha);
		}

		if ($this->options['single_quotes'] && $s->peek() == "'") {
			$s->get();
			$val = $s->skip_until("'");
			if ($s->get() != "'") {
				return $this->error("''' expected", $s->pos());
			}
			return $val;
		}

		return $this->error("Unexpected character: ".$s->peek(), $s->pos());
	}

	private function error($msg, $pos = null)
	{
		if ($pos) $msg .= " at $pos";
		throw new ParsingException($msg);
	}
}

?>
