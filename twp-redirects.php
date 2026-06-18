<?php
/*
Plugin Name: TWP - Easy Redirects
Plugin URI: https://github.com/tommasov/twp-redirects
Description: Lightweight 301 redirect manager with wildcard (*) support. Designed to blend in seamlessly with WordPress, just like a native feature — manage your redirects from Settings → Redirects.
Version: 1.0.6
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
 *
 * We hook as early as possible (on 'init') so that rules apply also to URLs
 * that would otherwise be handled by 404s, custom rewrites, or other plugins
 * that short-circuit 'template_redirect'.
 */
function twp_redirects_do_redirect() {
	// Skip admin, AJAX, cron, REST and CLI contexts to avoid breaking the backend.
	if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	$redirects = get_option( TWP_REDIRECTS_OPTION, [] );
	if ( empty( $redirects ) || ! is_array( $redirects ) ) {
		return;
	}

	$current_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( $current_uri === '' ) {
		return;
	}

	// Build the current absolute URL using the actual host/scheme of the request,
	// NOT home_url() + REQUEST_URI, which would duplicate the subdirectory path
	// on installs located in a sub-folder (e.g. /wp-test-site/).
	$scheme = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
	if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) === 'https' ) {
		$scheme = 'https';
	}
	$host        = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : ( isset( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : '' );
	$current_url = $scheme . '://' . $host . $current_uri;

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

		// If the source URL is a relative path, make it absolute for comparison
		// using the actual current host (avoids subdirectory duplication issues).
		if ( strpos( $from_url, 'http' ) !== 0 ) {
			$from_url = $scheme . '://' . $host . '/' . ltrim( $from_url, '/' );
		}

		if ( twp_redirects_is_match( $from_url, $current_url ) ) {
			// Avoid infinite redirect loops when source and destination match.
			if ( untrailingslashit( $to_url ) === untrailingslashit( $current_url ) ) {
				return;
			}
			wp_redirect( esc_url_raw( $to_url ), $type );
			exit;
		}
	}
}
add_action( 'init', 'twp_redirects_do_redirect', 1 );

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
	$normalize = function ( $url ) {
		// Strip scheme and leading www. so the match is tolerant of http/https and www variants.
		$url = preg_replace( '#^https?://#i', '', $url );
		$url = preg_replace( '#^www\.#i', '', $url );

		return untrailingslashit( $url );
	};

	$rule_norm    = $normalize( $rule_url );
	$current_norm = $normalize( $current_url );

	if ( strpos( $rule_norm, '*' ) === false ) {
		if ( $rule_norm === $current_norm ) {
			return true;
		}
		// Also match ignoring the query string of the current URL.
		$current_no_qs = $normalize( strtok( $current_url, '?' ) );
		return $rule_norm === $current_no_qs;
	}

	$regex = preg_quote( $rule_norm, '/' );
	$regex = str_replace( '\*', '.*', $regex );
	$regex = '/^' . $regex . '$/i';

	return (bool) preg_match( $regex, $current_norm );
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
                    <th scope="col" style="width: 110px; text-align: center; white-space: nowrap;"><?php esc_html_e( 'Action', 'twp-redirects' ); ?></th>
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
					echo '<select name="redirect_type[]" style="width:100%; max-width:100%; box-sizing:border-box;">';
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

            <div class="twp-redirects-types-help" style="margin-top: 12px;">
                <h2 style="font-size: 14px; margin-bottom: 6px;"><?php esc_html_e( 'Redirect types', 'twp-redirects' ); ?></h2>
                <ul style="margin-left: 18px; list-style: disc;">
                    <li><strong>301 - <?php esc_html_e( 'Moved Permanently', 'twp-redirects' ); ?>:</strong> <?php esc_html_e( 'Permanent redirect. Search engines transfer ranking to the new URL and browsers cache it aggressively. Use it when a page has moved for good.', 'twp-redirects' ); ?></li>
                    <li><strong>302 - <?php esc_html_e( 'Found (Temporary)', 'twp-redirects' ); ?>:</strong> <?php esc_html_e( 'Temporary redirect. The original URL is expected to come back, so search engines keep indexing the source. Browsers may switch the request method.', 'twp-redirects' ); ?></li>
                    <li><strong>307 - <?php esc_html_e( 'Temporary Redirect', 'twp-redirects' ); ?>:</strong> <?php esc_html_e( 'Like 302, but the HTTP method and body are preserved. Recommended for temporary moves of non-GET requests.', 'twp-redirects' ); ?></li>
                    <li><strong>308 - <?php esc_html_e( 'Permanent Redirect', 'twp-redirects' ); ?>:</strong> <?php esc_html_e( 'Like 301, but the HTTP method and body are preserved. Use it for permanent moves where the request method must not change.', 'twp-redirects' ); ?></li>
                </ul>
            </div>

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