import { defineConfig } from '@playwright/test';
import { BASE_URL } from './e2e/base-url';

export default defineConfig( {
	testDir: './e2e',
	testMatch: [ '**/*.spec.ts' ],
	timeout: process.env.CI ? 150_000 : 60_000,
	expect: { timeout: process.env.CI ? 20_000 : 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
