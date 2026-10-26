<?php

namespace VatrightEdd\Tests\Unit\Registry;

use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Registry\HttpTransport;
use VatrightEdd\Registry\Registry;
use VatrightEdd\Registry\Router;
use VatrightEdd\Registry\Vies;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase {

	private function registry( string $id, string $prefix ): Registry {
		return new class( $id, $prefix ) implements Registry {
			/** @var string */
			private $id;

			/** @var string */
			private $prefix;

			public function __construct( string $id, string $prefix ) {
				$this->id     = $id;
				$this->prefix = $prefix;
			}

			public function id(): string {
				return $this->id;
			}

			public function supports( VatNumber $n ): bool {
				return $n->prefix() === $this->prefix;
			}

			public function check( VatNumber $n, ?VatNumber $requester ): ValidationResult {
				return new ValidationResult( ValidationResult::VALID, $this->id, '2026-10-17T00:00:00Z' );
			}
		};
	}

	private function router(): Router {
		$transport = new class() implements HttpTransport {
			public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array {
				return array(
					'status' => 0,
					'body'   => '',
					'error'  => 'unused',
				);
			}
		};
		return new Router(
			new Vies( $transport ),
			$this->registry( 'hmrc', 'GB' ),
			$this->registry( 'uid', 'CHE' ),
			$this->registry( 'brreg', 'NO' )
		);
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function routes(): array {
		return array(
			'Germany'          => array( 'DE123475223', 'vies' ),
			'Greece'           => array( 'EL123456789', 'vies' ),
			'Northern Ireland' => array( 'XI980780684', 'vies' ),
			'Great Britain'    => array( 'GB980780684', 'hmrc' ),
			'Switzerland'      => array( 'CHE101329561', 'uid' ),
			'Norway'           => array( 'NO923609016', 'brreg' ),
		);
	}

	/**
	 * @dataProvider routes
	 */
	public function test_routes_each_number_to_its_registry( string $canonical, string $expected ): void {
		$number = VatNumber::parse( $canonical, substr( $canonical, 0, 2 ) );
		$this->assertNotNull( $number );

		$registry = $this->router()->for( $number );

		$this->assertNotNull( $registry );
		$this->assertSame( $expected, $registry->id() );
	}

	public function test_returns_null_when_no_registry_supports_the_number(): void {
		$number = VatNumber::parse( 'NO923609016', 'NO' );
		$this->assertNotNull( $number );

		$this->assertNull( ( new Router( $this->registry( 'hmrc', 'GB' ) ) )->for( $number ) );
	}
}
