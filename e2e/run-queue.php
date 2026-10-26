<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- a WP-CLI script, its variables are not globals of the plugin.

if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
	ActionScheduler_QueueRunner::instance()->run();
	echo '@@1@@';
	return;
}

// EDD before 3.6.5 has no Action Scheduler, so the plugin's due WP-Cron events run here, including the ones they schedule.
for ( $pass = 0; $pass < 100; $pass++ ) {
	$ran = false;
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		if ( $timestamp > time() ) {
			continue;
		}
		foreach ( $hooks as $hook => $events ) {
			if ( 0 !== strpos( $hook, 'vatright_edd_' ) ) {
				continue;
			}
			foreach ( $events as $event ) {
				wp_unschedule_event( $timestamp, $hook, $event['args'] );
				do_action_ref_array( $hook, $event['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
				$ran = true;
			}
		}
	}
	if ( ! $ran ) {
		break;
	}
}
echo '@@1@@';
