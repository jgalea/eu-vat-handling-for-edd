<?php

namespace VatrightEdd\Rates;

use DOMDocument;
use DOMElement;
use DOMXPath;
use VatrightEdd\Registry\HttpTransport;
use Throwable;

class Tedb {

	const ENDPOINT = 'https://ec.europa.eu/taxation_customs/tedb/ws/';

	const ACTION = 'urn:ec.europa.eu:taxud:tedb:services:v1:VatRetrievalService/RetrieveVatRates';

	const NS_SERVICE = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService';

	const NS_TYPES = 'urn:ec.europa.eu:taxud:tedb:services:v1:IVatRetrievalService:types';

	const MEMBER_STATES = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
	);

	const REDUCED_TYPES = array(
		'REDUCED_RATE'       => 'reduced',
		'SUPER_REDUCED_RATE' => 'super_reduced',
		'PARKING_RATE'       => 'parking',
	);

	/** @var HttpTransport */
	private $transport;

	/** @var int */
	private $timeout;

	public function __construct( HttpTransport $transport, int $timeout = 30 ) {
		$this->transport = $transport;
		$this->timeout   = $timeout;
	}

	public function fetch( string $situation_on ): ?RateSet {
		try {
			$response = $this->transport->request(
				'POST',
				self::ENDPOINT,
				self::request_body( $situation_on ),
				array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => self::ACTION,
				),
				$this->timeout
			);
			if ( 200 !== $response['status'] ) {
				return null;
			}
			$set = self::parse( $response['body'], $situation_on );
		} catch ( Throwable $e ) {
			return null;
		}
		return count( $set->country_codes() ) === count( self::MEMBER_STATES ) ? $set : null;
	}

	public static function request_body( string $situation_on ): string {
		$states = '';
		foreach ( self::MEMBER_STATES as $country ) {
			$states .= '<t:isoCode>' . self::to_tedb_code( $country ) . '</t:isoCode>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
			. ' xmlns:i="' . self::NS_SERVICE . '" xmlns:t="' . self::NS_TYPES . '">'
			. '<soapenv:Body><i:retrieveVatRatesReqMsg>'
			. '<t:memberStates>' . $states . '</t:memberStates>'
			. '<t:situationOn>' . $situation_on . '</t:situationOn>'
			. '</i:retrieveVatRatesReqMsg></soapenv:Body></soapenv:Envelope>';
	}

	public static function parse( string $xml, string $situation_on ): RateSet {
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return new RateSet( array(), 'tedb', $situation_on );
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return new RateSet( array(), 'tedb', $situation_on );
		}

		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 't', self::NS_TYPES );
		$standard = array();
		$reduced  = array();
		$results  = $xpath->query( '//t:vatRateResults' );
		foreach ( false === $results ? array() : $results as $result ) {
			if ( ! $result instanceof DOMElement ) {
				continue;
			}
			$country    = self::from_tedb_code( self::text( $xpath, 't:memberState', $result ) );
			$rate_type  = self::text( $xpath, 't:rate/t:type', $result );
			$value      = self::text( $xpath, 't:rate/t:value', $result );
			$category   = self::text( $xpath, 't:category/t:identifier', $result );
			$is_default = 'STANDARD' === self::text( $xpath, 't:type', $result ) && 'DEFAULT' === $rate_type;
			if ( $is_default ) {
				// TEDB also lists territory rows (Canary Islands at 7%) as STANDARD; the national rate is the highest.
				if ( '' !== $value && (float) $value > ( $standard[ $country ] ?? 0 ) ) {
					$standard[ $country ] = (float) $value;
				}
				continue;
			}
			if ( ! isset( self::REDUCED_TYPES[ $rate_type ] ) || 'REGION' === $category ) {
				continue;
			}
			$key = $value . '|' . $rate_type;
			if ( ! isset( $reduced[ $country ][ $key ] ) ) {
				$reduced[ $country ][ $key ] = array(
					'rate'       => (float) $value,
					'type'       => self::REDUCED_TYPES[ $rate_type ],
					'categories' => array(),
				);
			}
			if ( '' !== $category ) {
				$reduced[ $country ][ $key ]['categories'][ $category ] = $category;
			}
		}

		$countries = array();
		foreach ( self::MEMBER_STATES as $country ) {
			if ( ! isset( $standard[ $country ] ) ) {
				continue;
			}
			$entries = array_values( $reduced[ $country ] ?? array() );
			foreach ( $entries as $i => $entry ) {
				$categories = array_values( $entry['categories'] );
				sort( $categories );
				$entries[ $i ]['categories'] = $categories;
			}
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return $b['rate'] <=> $a['rate'];
				}
			);
			$countries[ $country ] = array(
				'standard' => $standard[ $country ],
				'reduced'  => $entries,
			);
		}
		return new RateSet( $countries, 'tedb', $situation_on );
	}

	private static function text( DOMXPath $xpath, string $query, DOMElement $context ): string {
		return trim( (string) $xpath->evaluate( 'string(' . $query . ')', $context ) );
	}

	private static function to_tedb_code( string $country ): string {
		return 'GR' === $country ? 'EL' : $country;
	}

	private static function from_tedb_code( string $code ): string {
		return 'EL' === $code ? 'GR' : $code;
	}
}
