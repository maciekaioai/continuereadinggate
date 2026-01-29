<?php
/**
 * Plugin Name: Continue Reading Gate
 * Description: Displays a blocking modal that requires an email and consent before continuing to read.
 * Version: 1.0.0
 * Author: TechLed
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TLW_Continue_Reading_Gate {
	const OPTION_KEY           = 'tlw_read_gate_settings';
	const DB_VERSION           = '1.0.0';
	const TABLE_NAME           = 'tlw_read_gate_leads';
	const COOKIE_UNLOCKED_NAME = 'tlw_read_gate_unlocked';
	const COOKIE_ATTEMPT_NAME  = 'tlw_gate_attempt_id';
	const COOKIE_VISITOR_NAME  = 'tlw_gate_vid';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_https_notice' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ) );
		add_action( 'wp_ajax_tlw_gate_token', array( __CLASS__, 'ajax_gate_token' ) );
		add_action( 'wp_ajax_nopriv_tlw_gate_token', array( __CLASS__, 'ajax_gate_token' ) );
		add_action( 'wp_ajax_tlw_gate_submit', array( __CLASS__, 'ajax_gate_submit' ) );
		add_action( 'wp_ajax_nopriv_tlw_gate_submit', array( __CLASS__, 'ajax_gate_submit' ) );
	}

	public static function activate() {
		self::create_table();
		add_option( 'tlw_read_gate_db_version', self::DB_VERSION );
	}

	public static function deactivate() {
	}

	public static function maybe_upgrade() {
		$version = get_option( 'tlw_read_gate_db_version', '' );
		if ( self::DB_VERSION !== $version ) {
			self::create_table();
			update_option( 'tlw_read_gate_db_version', self::DB_VERSION );
		}
	}

	private static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(254) NOT NULL,
			consent tinyint(1) NOT NULL DEFAULT 0,
			page_url text NOT NULL,
			page_title text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	public static function register_settings() {
		register_setting(
			'tlw_read_gate_settings_group',
			self::OPTION_KEY,
			array( __CLASS__, 'sanitize_settings' )
		);

		add_settings_section(
			'tlw_read_gate_main',
			__( 'Continue Reading Gate Settings', 'tlw' ),
			'__return_false',
			'tlw_read_gate_settings'
		);

		$fields = array(
			'enabled'                 => 'Enable gate',
			'include_posts'           => 'Include posts',
			'include_pages'           => 'Include pages',
			'delay_backstop'          => 'Delay backstop (seconds)',
			'delay_max'               => 'Max delay (seconds)',
			'scroll_depth'            => 'Scroll depth percent',
			'meaningful_scroll_count' => 'Minimum meaningful scroll events',
			'content_selector'        => 'Content container selector',
			'cookie_duration_days'    => 'Cookie duration (days)',
			'privacy_policy_url'      => 'Privacy Policy URL',
			'exclude_urls'            => 'Exclude URL patterns (one per line)',
			'exclude_post_ids'         => 'Exclude post IDs (comma-separated)',
			'exclude_categories'      => 'Exclude category slugs (comma-separated)',
		);

		foreach ( $fields as $field => $label ) {
			add_settings_field(
				$field,
				esc_html( $label ),
				array( __CLASS__, 'render_field' ),
				'tlw_read_gate_settings',
				'tlw_read_gate_main',
				array( 'field' => $field )
			);
		}
	}

	public static function sanitize_settings( $input ) {
		$output = self::default_settings();
		$output['enabled']                 = ! empty( $input['enabled'] );
		$output['include_posts']           = ! empty( $input['include_posts'] );
		$output['include_pages']           = ! empty( $input['include_pages'] );
		$output['delay_backstop']          = max( 1, absint( $input['delay_backstop'] ?? $output['delay_backstop'] ) );
		$output['delay_max']               = max( 1, absint( $input['delay_max'] ?? $output['delay_max'] ) );
		$output['scroll_depth']            = max( 1, min( 100, absint( $input['scroll_depth'] ?? $output['scroll_depth'] ) ) );
		$output['meaningful_scroll_count'] = max( 1, absint( $input['meaningful_scroll_count'] ?? $output['meaningful_scroll_count'] ) );
		$output['content_selector']        = sanitize_text_field( $input['content_selector'] ?? '' );
		$output['cookie_duration_days']    = max( 1, absint( $input['cookie_duration_days'] ?? $output['cookie_duration_days'] ) );
		$output['privacy_policy_url']      = esc_url_raw( $input['privacy_policy_url'] ?? '' );
		$output['exclude_urls']            = sanitize_textarea_field( $input['exclude_urls'] ?? '' );
		$output['exclude_post_ids']         = sanitize_text_field( $input['exclude_post_ids'] ?? '' );
		$output['exclude_categories']      = sanitize_text_field( $input['exclude_categories'] ?? '' );
		return $output;
	}

	public static function default_settings() {
		return array(
			'enabled'                 => true,
			'include_posts'           => true,
			'include_pages'           => true,
			'delay_backstop'          => 12,
			'delay_max'               => 20,
			'scroll_depth'            => 30,
			'meaningful_scroll_count' => 2,
			'content_selector'        => '',
			'cookie_duration_days'    => 30,
			'privacy_policy_url'      => '',
			'exclude_urls'            => '',
			'exclude_post_ids'         => '',
			'exclude_categories'      => '',
		);
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, self::default_settings() );
	}

	public static function render_field( $args ) {
		$settings = self::get_settings();
		$field    = $args['field'];
		$value    = $settings[ $field ] ?? '';
		switch ( $field ) {
			case 'enabled':
			case 'include_posts':
			case 'include_pages':
				printf(
					'<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s />',
					esc_attr( self::OPTION_KEY ),
					esc_attr( $field ),
					checked( ! empty( $value ), true, false )
				);
				break;
			case 'exclude_urls':
				printf(
					'<textarea name="%1$s[%2$s]" rows="4" cols="50" class="large-text">%3$s</textarea>',
					esc_attr( self::OPTION_KEY ),
					esc_attr( $field ),
					esc_textarea( $value )
				);
				break;
			default:
				printf(
					'<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text" />',
					esc_attr( self::OPTION_KEY ),
					esc_attr( $field ),
					esc_attr( $value )
				);
		}
	}

	public static function register_menu() {
		add_options_page(
			__( 'Continue Reading Gate', 'tlw' ),
			__( 'Continue Reading Gate', 'tlw' ),
			'manage_options',
			'tlw-read-gate',
			array( __CLASS__, 'render_settings_page' )
		);

		add_submenu_page(
			'tlw-read-gate',
			__( 'Gate Leads', 'tlw' ),
			__( 'Gate Leads', 'tlw' ),
			'manage_options',
			'tlw-read-gate-leads',
			array( __CLASS__, 'render_leads_page' )
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Continue Reading Gate', 'tlw' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'tlw_read_gate_settings_group' );
				do_settings_sections( 'tlw_read_gate_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function admin_https_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_is_using_https() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Continue Reading Gate: HTTPS is not enabled. Submissions will be rejected when HTTPS is available but not used.', 'tlw' ) . '</p></div>';
		}
	}

	public static function render_leads_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['tlw_export_csv'] ) && check_admin_referer( 'tlw_export_csv' ) ) {
			self::export_csv();
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$email     = sanitize_text_field( wp_unslash( $_GET['email'] ?? '' ) );
		$page_url  = sanitize_text_field( wp_unslash( $_GET['page_url'] ?? '' ) );
		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );

		$where  = '1=1';
		$params = array();

		if ( $email ) {
			$where   .= ' AND email = %s';
			$params[] = $email;
		}
		if ( $page_url ) {
			$where   .= ' AND page_url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $page_url ) . '%';
		}
		if ( $date_from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$query = "SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC LIMIT 200";
		if ( $params ) {
			$query = $wpdb->prepare( $query, $params );
		}
		$rows = $wpdb->get_results( $query );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Continue Reading Gate Leads', 'tlw' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="tlw-read-gate-leads" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="email"><?php esc_html_e( 'Email', 'tlw' ); ?></label></th>
						<td><input type="text" id="email" name="email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="page_url"><?php esc_html_e( 'Page URL contains', 'tlw' ); ?></label></th>
						<td><input type="text" id="page_url" name="page_url" value="<?php echo esc_attr( $page_url ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="date_from"><?php esc_html_e( 'Date from', 'tlw' ); ?></label></th>
						<td><input type="date" id="date_from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="date_to"><?php esc_html_e( 'Date to', 'tlw' ); ?></label></th>
						<td><input type="date" id="date_to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Filter', 'tlw' ), 'secondary', '', false ); ?>
				<?php
				$export_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'           => 'tlw-read-gate-leads',
							'tlw_export_csv' => 1,
							'email'          => rawurlencode( $email ),
							'page_url'       => rawurlencode( $page_url ),
							'date_from'      => rawurlencode( $date_from ),
							'date_to'        => rawurlencode( $date_to ),
						),
						admin_url( 'options-general.php' )
					),
					'tlw_export_csv'
				);
				?>
				<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'tlw' ); ?></a>
			</form>

			<table class="widefat striped" style="margin-top:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'tlw' ); ?></th>
						<th><?php esc_html_e( 'Consent', 'tlw' ); ?></th>
						<th><?php esc_html_e( 'Page URL', 'tlw' ); ?></th>
						<th><?php esc_html_e( 'Page Title', 'tlw' ); ?></th>
						<th><?php esc_html_e( 'Created At', 'tlw' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $rows ) : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><?php echo esc_html( $row->consent ? 'Yes' : 'No' ); ?></td>
							<td><?php echo esc_url( $row->page_url ); ?></td>
							<td><?php echo esc_html( $row->page_title ); ?></td>
							<td><?php echo esc_html( $row->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No leads found.', 'tlw' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'tlw' ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$email     = sanitize_text_field( wp_unslash( $_GET['email'] ?? '' ) );
		$page_url  = sanitize_text_field( wp_unslash( $_GET['page_url'] ?? '' ) );
		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );

		$where  = '1=1';
		$params = array();
		if ( $email ) {
			$where   .= ' AND email = %s';
			$params[] = $email;
		}
		if ( $page_url ) {
			$where   .= ' AND page_url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $page_url ) . '%';
		}
		if ( $date_from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$query = "SELECT * FROM {$table_name} WHERE {$where} ORDER BY created_at DESC";
		if ( $params ) {
			$query = $wpdb->prepare( $query, $params );
		}
		$rows = $wpdb->get_results( $query );

		nocache_headers();
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="tlw-read-gate-leads.csv"' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Email', 'Consent', 'Page URL', 'Page Title', 'Created At' ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$output,
				array(
					$row->email,
					$row->consent ? 'Yes' : 'No',
					$row->page_url,
					$row->page_title,
					$row->created_at,
				)
			);
		}
		fclose( $output );
		exit;
	}

	public static function enqueue_assets() {
		if ( ! self::is_eligible() ) {
			return;
		}

		$settings = self::get_settings();
		wp_enqueue_style(
			'tlw-read-gate',
			plugins_url( 'assets/continue-reading-gate.css', __FILE__ ),
			array(),
			self::DB_VERSION
		);

		wp_enqueue_script(
			'tlw-read-gate',
			plugins_url( 'assets/continue-reading-gate.js', __FILE__ ),
			array(),
			self::DB_VERSION,
			true
		);

		wp_localize_script(
			'tlw-read-gate',
			'tlwReadGateSettings',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'tlw_gate_submit' ),
				'tokenNonce'             => wp_create_nonce( 'tlw_gate_token' ),
				'delayBackstop'          => $settings['delay_backstop'] * 1000,
				'delayMax'               => $settings['delay_max'] * 1000,
				'scrollDepth'            => $settings['scroll_depth'],
				'meaningfulScrollCount'  => $settings['meaningful_scroll_count'],
				'contentSelector'        => $settings['content_selector'],
				'cookieDurationDays'     => $settings['cookie_duration_days'],
				'privacyPolicyUrl'       => $settings['privacy_policy_url'],
				'previewMode'            => self::is_preview_mode(),
			)
		);
	}

	private static function is_preview_mode() {
		return current_user_can( 'manage_options' ) && isset( $_GET['tlw_gate_preview'] ) && '1' === $_GET['tlw_gate_preview'];
	}

	private static function is_eligible() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) && ! self::is_preview_mode() ) {
			return false;
		}
		if ( self::has_unlocked_cookie() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) && ! self::is_preview_mode() ) {
			return false;
		}
		if ( is_admin() || is_preview() || post_password_required() || self::is_login_screen() ) {
			return false;
		}
		if ( self::is_excluded_url() ) {
			return false;
		}
		if ( is_singular( 'post' ) && ! $settings['include_posts'] ) {
			return false;
		}
		if ( is_page() && ! $settings['include_pages'] ) {
			return false;
		}
		if ( ! is_singular() ) {
			return false;
		}

		$post_id = get_the_ID();
		if ( $post_id && ! empty( $settings['exclude_post_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $settings['exclude_post_ids'] ) ) );
			if ( in_array( $post_id, $ids, true ) ) {
				return false;
			}
		}

		if ( $post_id && ! empty( $settings['exclude_categories'] ) && is_singular( 'post' ) ) {
			$slugs = array_filter( array_map( 'sanitize_title', explode( ',', $settings['exclude_categories'] ) ) );
			foreach ( $slugs as $slug ) {
				if ( has_category( $slug, $post_id ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function is_excluded_url() {
		$settings = self::get_settings();
		$url      = ( is_ssl() ? 'https://' : 'http://' ) . wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) . wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		$url      = esc_url_raw( $url );

		if ( empty( $settings['exclude_urls'] ) ) {
			return false;
		}
		$patterns = preg_split( '/\r\n|\r|\n/', $settings['exclude_urls'] );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== strpos( $url, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_login_screen() {
		$page = $GLOBALS['pagenow'] ?? '';
		return in_array( $page, array( 'wp-login.php', 'wp-register.php' ), true );
	}

	private static function has_unlocked_cookie() {
		return ! empty( $_COOKIE[ self::COOKIE_UNLOCKED_NAME ] );
	}

	public static function render_modal() {
		if ( ! self::is_eligible() ) {
			return;
		}
		$settings = self::get_settings();
		?>
		<div id="tlw-read-gate-overlay" class="tlw-read-gate-overlay" role="dialog" aria-modal="true" aria-labelledby="tlw-read-gate-title" aria-hidden="true">
			<div class="tlw-read-gate-modal" role="document">
				<h2 id="tlw-read-gate-title"><?php esc_html_e( 'Continue reading', 'tlw' ); ?></h2>
				<p class="tlw-read-gate-body"><?php esc_html_e( 'Enter your email to continue reading this article.', 'tlw' ); ?></p>
				<div class="tlw-read-gate-error" role="status" aria-live="polite"></div>
				<form id="tlw-read-gate-form">
					<label for="tlw-read-gate-email"><?php esc_html_e( 'Email address', 'tlw' ); ?></label>
					<input type="email" id="tlw-read-gate-email" name="email" placeholder="<?php esc_attr_e( 'name@company.com', 'tlw' ); ?>" autocomplete="email" required />
					<div class="tlw-read-gate-honeypot">
						<label for="tlw-read-gate-company">Company</label>
						<input type="text" id="tlw-read-gate-company" name="company" tabindex="-1" autocomplete="off" />
					</div>
					<label class="tlw-read-gate-consent">
						<input type="checkbox" name="consent" value="1" />
						<span><?php esc_html_e( 'I agree to the TechLed Privacy Policy.', 'tlw' ); ?></span>
						<?php if ( ! empty( $settings['privacy_policy_url'] ) ) : ?>
							<a href="<?php echo esc_url( $settings['privacy_policy_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'tlw' ); ?></a>
						<?php endif; ?>
					</label>
					<button type="submit" class="tlw-read-gate-submit"><?php esc_html_e( 'Continue', 'tlw' ); ?></button>
					<p class="tlw-read-gate-helper"><?php esc_html_e( 'We use your email to share related content and updates. You can unsubscribe at any time.', 'tlw' ); ?></p>
				</form>
			</div>
		</div>
		<?php
	}

	public static function ajax_gate_token() {
		check_ajax_referer( 'tlw_gate_token', 'nonce' );
		$visitor_id = self::get_or_set_visitor_id();
		$token      = wp_generate_password( 20, false, false );
		set_transient( 'tlw_gate_token_' . $visitor_id, $token, MINUTE_IN_SECONDS * 10 );
		wp_send_json_success(
			array(
				'token' => $token,
			)
		);
	}

	public static function ajax_gate_submit() {
		check_ajax_referer( 'tlw_gate_submit', 'nonce' );
		if ( wp_is_using_https() && ! is_ssl() ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'tlw' ) ), 400 );
		}

		$visitor_id = self::get_or_set_visitor_id();
		$attempt_id = self::get_or_set_attempt_id();
		if ( self::is_rate_limited( $visitor_id, $attempt_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'tlw' ) ), 429 );
		}

		$email       = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$consent     = isset( $_POST['consent'] ) && '1' === $_POST['consent'];
		$page_url    = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
		$page_title  = sanitize_text_field( wp_unslash( $_POST['page_title'] ?? '' ) );
		$honeypot    = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
		$token       = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$elapsed_ms  = absint( wp_unslash( $_POST['elapsed'] ?? 0 ) );
		$interaction = absint( wp_unslash( $_POST['interaction'] ?? 0 ) );

		if ( ! empty( $honeypot ) ) {
			self::increment_rate_limit( $visitor_id, $attempt_id );
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'tlw' ) ), 400 );
		}

		if ( $elapsed_ms < 2500 || 1 !== $interaction ) {
			self::increment_rate_limit( $visitor_id, $attempt_id );
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'tlw' ) ), 400 );
		}

		if ( ! is_email( $email ) ) {
			self::increment_rate_limit( $visitor_id, $attempt_id );
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'tlw' ) ), 400 );
		}

		if ( ! $consent ) {
			self::increment_rate_limit( $visitor_id, $attempt_id );
			wp_send_json_error( array( 'message' => __( 'Please tick the box to continue.', 'tlw' ) ), 400 );
		}

		$stored_token = get_transient( 'tlw_gate_token_' . $visitor_id );
		if ( empty( $stored_token ) || ! hash_equals( $stored_token, $token ) ) {
			self::increment_rate_limit( $visitor_id, $attempt_id );
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'tlw' ) ), 400 );
		}

		if ( self::is_duplicate_submission( $email ) ) {
			self::set_unlocked_cookie();
			wp_send_json_success( array( 'message' => __( 'Thanks. You can keep reading.', 'tlw' ) ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$inserted   = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name} (email, consent, page_url, page_title, created_at) VALUES (%s, %d, %s, %s, %s)",
				$email,
				1,
				$page_url,
				$page_title,
				current_time( 'mysql' )
			)
		);

		if ( false === $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'tlw' ) ), 500 );
		}

		self::mark_duplicate( $email );
		self::set_unlocked_cookie();
		wp_send_json_success( array( 'message' => __( 'Thanks. You can keep reading.', 'tlw' ) ) );
	}

	private static function get_or_set_visitor_id() {
		$visitor_id = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_VISITOR_NAME ] ?? '' ) );
		if ( empty( $visitor_id ) ) {
			$visitor_id = wp_generate_uuid4();
			setcookie(
				self::COOKIE_VISITOR_NAME,
				$visitor_id,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::COOKIE_VISITOR_NAME ] = $visitor_id;
		}
		return $visitor_id;
	}

	private static function get_or_set_attempt_id() {
		$attempt_id = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_ATTEMPT_NAME ] ?? '' ) );
		if ( empty( $attempt_id ) ) {
			$attempt_id = wp_generate_uuid4();
			setcookie(
				self::COOKIE_ATTEMPT_NAME,
				$attempt_id,
				array(
					'expires'  => time() + HOUR_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::COOKIE_ATTEMPT_NAME ] = $attempt_id;
		}
		return $attempt_id;
	}

	private static function set_unlocked_cookie() {
		$settings = self::get_settings();
		$expires  = time() + ( DAY_IN_SECONDS * $settings['cookie_duration_days'] );
		setcookie(
			self::COOKIE_UNLOCKED_NAME,
			'1',
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_UNLOCKED_NAME ] = '1';
	}

	private static function is_rate_limited( $visitor_id, $attempt_id ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_key      = 'tlw_gate_rate_ip_' . md5( $ip );
		$visitor_key = 'tlw_gate_rate_vid_' . md5( $visitor_id );
		$attempt_key = 'tlw_gate_rate_attempt_' . md5( $attempt_id );
		$ip_count    = absint( get_transient( $ip_key ) );
		$visitor_count = absint( get_transient( $visitor_key ) );
		$attempt_count = absint( get_transient( $attempt_key ) );
		return ( $ip_count >= 20 || $visitor_count >= 8 || $attempt_count >= 8 );
	}

	private static function increment_rate_limit( $visitor_id, $attempt_id ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_key      = 'tlw_gate_rate_ip_' . md5( $ip );
		$visitor_key = 'tlw_gate_rate_vid_' . md5( $visitor_id );
		$attempt_key = 'tlw_gate_rate_attempt_' . md5( $attempt_id );

		$ip_count = absint( get_transient( $ip_key ) );
		set_transient( $ip_key, $ip_count + 1, HOUR_IN_SECONDS );

		$visitor_count = absint( get_transient( $visitor_key ) );
		set_transient( $visitor_key, $visitor_count + 1, HOUR_IN_SECONDS );

		$attempt_count = absint( get_transient( $attempt_key ) );
		set_transient( $attempt_key, $attempt_count + 1, HOUR_IN_SECONDS );
	}

	private static function is_duplicate_submission( $email ) {
		$key = 'tlw_gate_dup_' . md5( strtolower( $email ) );
		return (bool) get_transient( $key );
	}

	private static function mark_duplicate( $email ) {
		$key = 'tlw_gate_dup_' . md5( strtolower( $email ) );
		set_transient( $key, 1, HOUR_IN_SECONDS );
	}
}

register_activation_hook( __FILE__, array( 'TLW_Continue_Reading_Gate', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TLW_Continue_Reading_Gate', 'deactivate' ) );
TLW_Continue_Reading_Gate::init();
