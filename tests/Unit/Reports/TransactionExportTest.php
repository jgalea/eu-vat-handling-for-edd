<?php

namespace VatrightEdd\Tests\Unit\Reports;

use PHPUnit\Framework\TestCase;
use VatrightEdd\Reports\Csv;
use VatrightEdd\Reports\TransactionExport;

require_once dirname( __DIR__, 2 ) . '/Support/SettingsStubs.php';

class TransactionExportTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function entry(): array {
		return array(
			'order_id'         => '41',
			'entry_type'       => 'sale',
			'supply_at'        => '2026-10-02 09:30:00',
			'customer_country' => 'DE',
			'place_of_supply'  => 'DE',
			'customer_vat'     => 'DE123456789',
			'scheme'           => 'reverse_charge_services',
			'rule'             => 'rc_services_art196',
			'supply_type'      => 'digital',
			'rate_percent'     => '0.0000',
			'net'              => '100.0000',
			'vat'              => '0.0000',
			'currency'         => 'USD',
			'eur_rate'         => '1.08000000',
			'net_eur'          => '92.5926',
			'vat_eur'          => '0.0000',
		);
	}

	public function test_a_row_carries_the_evidence_and_the_registry_answer(): void {
		$record = array(
			'evidence'   => array(
				'billing'        => 'DE',
				'ip'             => 'DE',
				'self_certified' => null,
			),
			'validation' => array(
				'registry'     => 'vies',
				'status'       => 'valid',
				'name'         => 'Example GmbH',
				'consultation' => 'WAPIAAAA',
				'checked_at'   => '2026-10-02T09:29:58Z',
			),
			'flags'      => array( 'single_evidence' ),
		);
		$row    = TransactionExport::row( $this->entry(), $record, '41' );

		$this->assertCount( count( TransactionExport::header() ), $row );
		$this->assertSame( array( 41, '41', 'sale', '2026-10-02 09:30:00', 'DE', 'DE', 'DE', '', 'DE', '' ), array_slice( $row, 0, 10 ) );
		$this->assertSame( array( 'DE123456789', 'vies', 'valid', 'Example GmbH', 'WAPIAAAA' ), array_slice( $row, 10, 5 ) );
		$this->assertSame( 'single_evidence', $row[26] );
	}

	public function test_an_order_without_a_stored_decision_still_exports(): void {
		$row = TransactionExport::row( $this->entry(), array(), '41' );

		$this->assertCount( count( TransactionExport::header() ), $row );
		$this->assertSame( '', $row[12] );
	}

	public function test_a_registry_name_that_reads_as_a_formula_is_neutralised(): void {
		$record                = array( 'validation' => array( 'name' => '=HYPERLINK("http://example.com","x")' ) );
		$entry                 = $this->entry();
		$entry['customer_vat'] = '@SUM(1)';
		$csv                   = Csv::render( array( 'a' ), array( TransactionExport::row( $entry, $record, '=1+1' ) ) );

		$this->assertStringContainsString( '\'=HYPERLINK', $csv );
		$this->assertStringContainsString( '\'@SUM(1)', $csv );
		$this->assertStringContainsString( '\'=1+1', $csv );
		$this->assertContains( '=HYPERLINK("http://example.com","x")', TransactionExport::row( $entry, $record, '1' ), 'the raw row holds the formula, so the guard is what removes it' );
		$this->assertStringNotContainsString( ',=HYPERLINK', $csv );
	}
}
