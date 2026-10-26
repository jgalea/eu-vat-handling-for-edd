<?php

namespace VatrightEdd\Registry;

interface HttpTransport {

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int,body:string,error:string}
	 */
	public function request( string $method, string $url, string $body = '', array $headers = array(), int $timeout = 5 ): array;
}
