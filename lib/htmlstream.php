<?php
/*
 * First stage parser for HTML documents.
 */
class htmlstream
{
	const spaces = "\r\n\t ";
	private $buf;

	private $peek = null;

	function __construct($s)
	{
		$this->buf = new parsebuf($s);
	}
	
	function pos() { return $this->buf->pos(); }
	
	function more() {
		return $this->peek() !== null;
	}

	/*
	 * Returns next token or null
	 * and removes it from the stream
	 */
	function get()
	{
		if($this->peek !== null) {
			$p = $this->peek;
			$this->peek = null;
			return $p;
		}
		return $this->read();
	}

	function unget(token $tok)
	{
		if($this->peek !== null) {
			trigger_error("Can't unget $tok: buffer full");
			return;
		}

		$this->peek = $tok;
	}

	/*
	 * Returns next token or null
	 */
	function peek()
	{
		if($this->peek === null) {
			$this->peek = $this->read();
		}
		return $this->peek;
	}

	/*
	 * Reads a token from the string
	 */
	private function read()
	{
		if(!$this->buf->more()) {
			return null;
		}
		
		$pos = $this->buf->pos();

		if($this->buf->literal_follows('<!DOCTYPE')) {
			$t = $this->read_doctype();
		}
		else if($this->buf->literal_follows('<!--')) {
			$t = $this->read_comment();
		}
		else if($this->buf->peek() == '<') {
			$t = $this->read_tag();
		}
		else {
			$t = $this->read_text();
		}
		
		$t->pos = $pos;
		return $t;
	}

	private function read_doctype()
	{
		$this->buf->skip_literal( "<!DOCTYPE" );

		if( !$this->buf->read_set( self::spaces ) ) {
			return $this->error( "Missing space after <!DOCTYPE" );
		}

		if( !$this->buf->skip_literal( "html" ) ) {
			return $this->error( "Unknown doctype" );
		}

		$this->buf->read_set( self::spaces );
		if( $this->buf->get() != '>' ) {
			return $this->error( "Missing '>'" );
		}

		return new token( 'doctype', 'html' );
	}

	private function read_comment()
	{
		$this->buf->skip_literal( "<!--" );
		$s = '';
		while( $this->buf->more() ) {
			$ch = $this->buf->get();
			if( $ch == '-' && $this->buf->skip_literal( '->' ) ) {
				return new token( 'comment', $s );
			}
			$s .= $ch;
		}
		return $this->error( "--> expected" );
	}

	private function read_tag()
	{
		$s = $this->buf->get();
		assert( $s == '<' );
		while( $this->buf->more() ) {
			$ch = $this->buf->get();
			$s .= $ch;
			if( $ch == '>' ) {
				return new token( 'tag', $s );
			}
		}
		return $this->error( "Missing '>'" );
	}

	private function read_text()
	{
		$s = '';
		while( $this->buf->more() ) {
			$ch = $this->buf->get();
			if( $ch == '<' ) {
				$this->buf->unget( $ch );
				break;
			}

			if( $ch == '&' ) {
				$this->buf->unget( $ch );
				$s .= $this->read_entity();
				continue;
			}

			$s .= $ch;
		}

		return new token( 'text', $s );
	}

	private function read_entity()
	{
		$s = $this->buf->get();

		while( $this->buf->more() && $this->buf->peek() != ';' ) {
			$s .= $this->buf->get();
		}
		$s .= $this->buf->get();

		return html_entity_decode( $s );
	}
}

?>