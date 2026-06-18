<?php
/*
Plugin Name: TWP - Easy Redirects
Plugin URI: https://github.com/tommasov/twp-redirects
Description: Lightweight 301 redirect manager with wildcard (*) support. Designed to blend in seamlessly with WordPress, just like a native feature — manage your redirects from Settings → Redirects.
Version: 1.0.1
Author: Tommaso Vietina
Author URI: https://www.tommasovietina.it
Text Domain: twp-redirects
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TWP_REDIRECTS_OPTION', 'twp_redirects' );

/**
 * Register the menu entry under WordPress Settings.
 */
add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Redirects', 'twp-redirects' ),
		__( 'Redirects', 'twp-redirects' ),
		'manage_options',
		'twp-redirects',
		'twp_redirects_render_page'
	);
} );

/**
 * Quick "Settings" link on the plugins page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url            = admin_url( 'options-general.php?page=twp-redirects' );
	$settings_link  = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'twp-redirects' ) . '</a>';
	array_unshift( $links, $settings_link );

	return $links;
} );

/**
 * Perform the redirect if the current URL matches one of the saved rules.
 */
add_action( 'template_redirect', function () {
	$redirects = get_option( TWP_REDIRECTS_OPTION, [] );
	if ( empty( $redirects ) || ! is_array( $redirects ) ) {
		return;
	}

	$current_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	$current_url = home_url( $current_uri );

	foreach ( $redirects as $rule ) {
		$from_url = isset( $rule['from'] ) ? trim( $rule['from'] ) : '';
		$to_url   = isset( $rule['to'] ) ? trim( $rule['to'] ) : '';
		$type     = isset( $rule['type'] ) ? (int) $rule['type'] : 301;
		if ( ! in_array( $type, twp_redirects_allowed_types(), true ) ) {
			$type = 301;
		}

		if ( $from_url === '' || $to_url === '' ) {
			continue;
		}

		// If the source URL is a relative path, make it absolute for comparison.
		if ( strpos( $from_url, 'http' ) !== 0 ) {
			$from_url = home_url( '/' . ltrim( $from_url, '/' ) );
		}

		if ( twp_redirects_is_match( $from_url, $current_url ) ) {
			wp_redirect( $to_url, $type );
			exit;
		}
	}
} );

/**
 * Allowed redirect HTTP status codes.
 *
 * @return int[]
 */
function twp_redirects_allowed_types() {
	return array( 301, 302, 307, 308 );
}

/**
 * Human-readable labels for each redirect type.
 *
 * @return array<int,string>
 */
function twp_redirects_type_labels() {
	return array(
		301 => __( '301 - Moved Permanently', 'twp-redirects' ),
		302 => __( '302 - Found (Temporary)', 'twp-redirects' ),
		307 => __( '307 - Temporary Redirect', 'twp-redirects' ),
		308 => __( '308 - Permanent Redirect', 'twp-redirects' ),
	);
}

/**
 * Check whether the current URL matches the redirect rule.
 * Supports the * wildcard.
 *
 * @param string $rule_url    The source URL specified in the rule.
 * @param string $current_url The full current URL.
 *
 * @return bool
 */
function twp_redirects_is_match( $rule_url, $current_url ) {
	if ( strpos( $rule_url, '*' ) === false ) {
		return untrailingslashit( $rule_url ) === untrailingslashit( $current_url );
	}

	$regex = preg_quote( $rule_url, '/' );
	$regex = str_replace( '\*', '.*', $regex );
	$regex = '/^' . $regex . '$/i';

	return (bool) preg_match( $regex, $current_url );
}

/**
 * Render the redirects management page using native WordPress styles.
 */
function twp_redirects_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Save data.
	if ( isset( $_POST['twp_redirects_save'] ) && check_admin_referer( 'twp_redirects_save_action', 'twp_redirects_nonce' ) ) {
		$from_urls  = isset( $_POST['redirect_from'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['redirect_from'] ) ) : [];
		$to_urls    = isset( $_POST['redirect_to'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['redirect_to'] ) ) : [];
		$type_codes = isset( $_POST['redirect_type'] ) ? array_map( 'intval', (array) $_POST['redirect_type'] ) : [];

		$allowed_types = twp_redirects_allowed_types();
		$redirects     = [];
		$count         = count( $from_urls );
		for ( $i = 0; $i < $count; $i ++ ) {
			$from = isset( $from_urls[ $i ] ) ? trim( $from_urls[ $i ] ) : '';
			$to   = isset( $to_urls[ $i ] ) ? trim( $to_urls[ $i ] ) : '';
			$type = isset( $type_codes[ $i ] ) ? (int) $type_codes[ $i ] : 301;
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 301;
			}
			if ( $from !== '' && $to !== '' ) {
				$redirects[] = [
					'from' => $from,
					'to'   => $to,
					'type' => $type,
				];
			}
		}
		update_option( TWP_REDIRECTS_OPTION, $redirects );
		add_settings_error( 'twp_redirects', 'twp_redirects_saved', __( 'Redirects saved successfully.', 'twp-redirects' ), 'updated' );
	}

	$redirects = get_option( TWP_REDIRECTS_OPTION, [] );
	if ( ! is_array( $redirects ) ) {
		$redirects = [];
	}
	?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Redirects', 'twp-redirects' ); ?></h1>
		<?php settings_errors( 'twp_redirects' ); ?>
        <p class="description"><?php esc_html_e( 'This plugin integrates seamlessly into WordPress as if it were a native feature, following the standard admin look and feel.', 'twp-redirects' ); ?></p>
        <p><?php
			printf(
				/* translators: %s: wildcard character */
				esc_html__( 'Specify the source and destination URLs. You can use the asterisk %s as a wildcard in the source URL.', 'twp-redirects' ),
				'<code>*</code>'
			);
			?></p>

        <form method="post" action="">
			<?php wp_nonce_field( 'twp_redirects_save_action', 'twp_redirects_nonce' ); ?>

            <table class="wp-list-table widefat fixed striped" id="twp-redirects-table">
                <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Source URL (wildcard * supported)', 'twp-redirects' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Destination URL', 'twp-redirects' ); ?></th>
                    <th scope="col" style="width: 220px;"><?php esc_html_e( 'Type', 'twp-redirects' ); ?></th>
                    <th scope="col" style="width: 90px; text-align: center;"><?php esc_html_e( 'Action', 'twp-redirects' ); ?></th>
                </tr>
                </thead>
                <tbody>
				<?php
				$type_labels = twp_redirects_type_labels();
				$render_type_select = function ( $selected ) use ( $type_labels ) {
					$selected = (int) $selected;
					if ( ! isset( $type_labels[ $selected ] ) ) {
						$selected = 301;
					}
					echo '<select name="redirect_type[]" class="regular-text">';
					foreach ( $type_labels as $code => $label ) {
						echo '<option value="' . esc_attr( $code ) . '" ' . selected( $selected, $code, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
				};
				?>
				<?php if ( ! empty( $redirects ) ) : ?>
					<?php foreach ( $redirects as $rule ) : ?>
                        <tr>
                            <td><input type="text" name="redirect_from[]" value="<?php echo esc_attr( $rule['from'] ); ?>" class="large-text code" placeholder="https://example.com/old-page/*" /></td>
                            <td><input type="text" name="redirect_to[]" value="<?php echo esc_attr( $rule['to'] ); ?>" class="large-text code" placeholder="https://example.com/new-page/" /></td>
                            <td><?php $render_type_select( isset( $rule['type'] ) ? $rule['type'] : 301 ); ?></td>
                            <td style="text-align: center;"><button type="button" class="button button-link-delete remove-row"><?php esc_html_e( 'Remove', 'twp-redirects' ); ?></button></td>
                        </tr>
					<?php endforeach; ?>
				<?php else : ?>
                    <tr>
                        <td><input type="text" name="redirect_from[]" value="" class="large-text code" placeholder="https://example.com/old-page" /></td>
                        <td><input type="text" name="redirect_to[]" value="" class="large-text code" placeholder="https://example.com/new-page" /></td>
                        <td><?php $render_type_select( 301 ); ?></td>
                        <td style="text-align: center;"><button type="button" class="button button-link-delete remove-row"><?php esc_html_e( 'Remove', 'twp-redirects' ); ?></button></td>
                    </tr>
				<?php endif; ?>
                </tbody>
            </table>

            <p class="submit" style="display:flex; gap:8px;">
                <button type="button" class="button" id="twp-add-redirect-row"><?php esc_html_e( 'Add row', 'twp-redirects' ); ?></button>
                <input type="submit" name="twp_redirects_save" class="button button-primary" value="<?php esc_attr_e( 'Save Redirects', 'twp-redirects' ); ?>" />
            </p>
        </form>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            $('#twp-add-redirect-row').on('click', function () {
                var $tbody = $('#twp-redirects-table tbody');
                var $row = $tbody.find('tr:last').clone();
                if ($row.length === 0) {
                    return;
                }
                $row.find('input').val('');
                $row.find('select').val('301');
                $tbody.append($row);
            });

            $(document).on('click', '.remove-row', function () {
                var $tbody = $('#twp-redirects-table tbody');
                if ($tbody.find('tr').length > 1) {
                    $(this).closest('tr').remove();
                } else {
                    $(this).closest('tr').find('input').val('');
                }
            });
        });
    </script>
	<?php
}