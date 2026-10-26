<?php

namespace VatrightEdd;

class Scheduler {

	/** @var array<string,string> */
	private static $jobs = array();

	public static function register(): void {
		add_action( 'init', array( self::class, 'ensure' ) );
	}

	public static function recurring( string $hook, string $recurrence ): void {
		self::$jobs[ $hook ] = $recurrence;
	}

	public static function ensure(): void {
		foreach ( self::$jobs as $hook => $recurrence ) {
			if ( self::uses_action_scheduler() ) {
				if ( ! as_has_scheduled_action( $hook ) ) {
					as_schedule_recurring_action( time(), self::interval( $recurrence ), $hook, array(), 'vatright-edd' );
				}
			} elseif ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	/**
	 * @param list<mixed> $args
	 */
	public static function once( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( self::uses_action_scheduler() && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( $hook, $args, 'vatright-edd' ) ) {
				as_schedule_single_action( time() + $delay, $hook, $args, 'vatright-edd' );
			}
		} elseif ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + $delay, $hook, $args );
		}
	}

	public static function clear( string $hook ): void {
		if ( self::uses_action_scheduler() ) {
			as_unschedule_all_actions( $hook );
		}
		wp_unschedule_hook( $hook );
	}

	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	public static function reset(): void {
		self::$jobs = array();
	}

	private static function interval( string $recurrence ): int {
		$schedules = wp_get_schedules();
		return isset( $schedules[ $recurrence ]['interval'] ) ? (int) $schedules[ $recurrence ]['interval'] : DAY_IN_SECONDS;
	}
}
