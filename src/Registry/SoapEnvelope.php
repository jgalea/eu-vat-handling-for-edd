<?php

namespace VatrightEdd\Registry;

use DOMDocument;
use DOMXPath;

class SoapEnvelope {

	const NS_ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';

	/**
	 * @param array<string,string> $params Element name => text value, all in the operation namespace.
	 */
	public static function build( string $ns, string $op, array $params ): string {
		$inner = '';
		foreach ( $params as $name => $value ) {
			$inner .= '<' . $name . '>' . htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</' . $name . '>';
		}
		return '<?xml version="1.0" encoding="utf-8"?>'
			. '<s:Envelope xmlns:s="' . self::NS_ENVELOPE . '"><s:Body>'
			. '<' . $op . ' xmlns="' . htmlspecialchars( $ns, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '">' . $inner . '</' . $op . '>'
			. '</s:Body></s:Envelope>';
	}

	/**
	 * Returns null for anything that is not well-formed XML or that carries a DTD. The `s` prefix is bound to the SOAP envelope namespace.
	 */
	public static function xpath( string $body ): ?DOMXPath {
		if ( false !== stripos( $body, '<!DOCTYPE' ) || false !== stripos( $body, '<!ENTITY' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadXML( $body, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 's', self::NS_ENVELOPE );
		return $xpath;
	}
}
