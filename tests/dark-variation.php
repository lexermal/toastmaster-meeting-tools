<?php
/**
 * Dev helper: switch Twenty Twenty-Five to its dark "Evening" style variation (arg "on") or back ("off").
 * Run: docker compose run --rm -v $PWD/tests:/tests:ro cli wp eval-file /tests/dark-variation.php on
 */
wp_set_current_user( 1 );
$on      = isset( $args[0] ) && 'on' === $args[0];
$post_id = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
$data    = array( 'version' => 3, 'isGlobalStylesUserThemeJSON' => true );
if ( $on ) {
	$file = get_theme_file_path( 'styles/01-evening.json' );
	$data = array_merge( json_decode( file_get_contents( $file ), true ), array( 'isGlobalStylesUserThemeJSON' => true ) );
	unset( $data['$schema'], $data['title'] );
}
wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_json_encode( $data ) ) );
WP_CLI::success( $on ? 'Evening (dark) variation on' : 'Default variation' );
