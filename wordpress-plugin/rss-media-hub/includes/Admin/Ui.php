<?php
/**
 * Shared admin UI helpers.
 *
 * @package RSSMediaHub
 */

namespace RMH\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Small, escaped rendering helpers for admin screens.
 */
final class Ui {

	/**
	 * Admin page URL.
	 *
	 * @param string $page Page slug suffix (dashboard, feeds, queue, media, moderation, restrictions, settings, logs).
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'rmh-' . $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Format a UTC MySQL datetime for display in site time.
	 *
	 * @param string|null $mysql Datetime.
	 * @param bool        $relative Include relative time.
	 * @return string Escaped.
	 */
	public static function time( $mysql, $relative = true ) {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return '&mdash;';
		}
		$ts  = strtotime( $mysql . ' UTC' );
		$abs = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		if ( ! $relative ) {
			return esc_html( $abs );
		}
		$diff = human_time_diff( $ts, time() );
		/* translators: %s: time difference */
		$rel = $ts > time() ? sprintf( __( 'in %s', 'rss-media-hub' ), $diff ) : sprintf( __( '%s ago', 'rss-media-hub' ), $diff );
		return '<span title="' . esc_attr( $abs ) . '">' . esc_html( $rel ) . '</span>';
	}

	/**
	 * Status pill.
	 *
	 * @param string $status Value.
	 * @param string $label  Optional label.
	 * @return string
	 */
	public static function pill( $status, $label = '' ) {
		$map  = array(
			'ok'          => 'good',
			'active'      => 'good',
			'success'     => 'good',
			'published'   => 'good',
			'valid'       => 'good',
			'resolved'    => 'good',
			'new'         => 'info',
			'queued'      => 'info',
			'pending'     => 'info',
			'discovered'  => 'info',
			'unchecked'   => 'info',
			'open'        => 'info',
			'running'     => 'info',
			'warning'     => 'warn',
			'partial'     => 'warn',
			'paused'      => 'warn',
			'skipped'     => 'muted',
			'duplicate'   => 'muted',
			'dismissed'   => 'muted',
			'rejected'    => 'muted',
			'disabled'    => 'muted',
			'error'       => 'bad',
			'failed'      => 'bad',
			'invalid'     => 'bad',
			'unavailable' => 'bad',
			'blocked'     => 'bad',
			'removed'     => 'bad',
			'escalated'   => 'bad',
			'interrupted' => 'bad',
		);
		$tone = isset( $map[ $status ] ) ? $map[ $status ] : 'muted';
		return '<span class="rmh-pill rmh-pill--' . esc_attr( $tone ) . '">' . esc_html( '' !== $label ? $label : str_replace( '_', ' ', (string) $status ) ) . '</span>';
	}

	/**
	 * Pagination links.
	 *
	 * @param int $total    Total rows.
	 * @param int $per_page Per page.
	 * @param int $current  Current page.
	 */
	public static function pagination( $total, $per_page, $current ) {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">';
		/* translators: %s: number of items */
		echo esc_html( sprintf( _n( '%s item', '%s items', $total, 'rss-media-hub' ), number_format_i18n( $total ) ) );
		echo '</span>';
		if ( $pages > 1 ) {
			echo ' <span class="pagination-links">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'total'     => $pages,
						'current'   => $current,
						'prev_text' => '&lsaquo;',
						'next_text' => '&rsaquo;',
					)
				)
			);
			echo '</span>';
		}
		echo '</div></div>';
	}

	/**
	 * Current page number from the request.
	 *
	 * @return int
	 */
	public static function paged() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Read a sanitized GET param.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function get( $key ) {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * A POST form button for an admin-post action (nonce-protected).
	 *
	 * @param string $action  admin-post action.
	 * @param array  $fields  Hidden fields.
	 * @param string $label   Button label.
	 * @param string $css_class Button class.
	 * @param string $confirm Confirmation text (JS).
	 * @return string
	 */
	public static function action_button( $action, array $fields, $label, $css_class = 'button button-small', $confirm = '' ) {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rmh-inline-form"' . ( '' !== $confirm ? ' data-rmh-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		$html .= wp_nonce_field( $action, '_rmh_nonce', true, false );
		foreach ( $fields as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$html .= '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button></form>';
		return $html;
	}

	/**
	 * Select element.
	 *
	 * @param string $name     Name.
	 * @param array  $options  value => label.
	 * @param string $selected Selected.
	 * @param string $id       Id.
	 * @return string
	 */
	public static function select( $name, array $options, $selected, $id = '' ) {
		$html = '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		foreach ( $options as $v => $l ) {
			$html .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $selected, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * Truncated URL with host emphasis.
	 *
	 * @param string $url URL.
	 * @param int    $max Max chars.
	 * @return string
	 */
	public static function short_url( $url, $max = 60 ) {
		$url = \RMH\Security\Redactor::url( (string) $url );
		$txt = mb_strlen( $url ) > $max ? mb_substr( $url, 0, $max - 1 ) . '…' : $url;
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $url ) . '">' . esc_html( $txt ) . '</a>';
	}

	/**
	 * Page header.
	 *
	 * @param string $title  Title.
	 * @param string $action Optional action button HTML.
	 */
	public static function header( $title, $action = '' ) {
		echo '<div class="wrap rmh-admin"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $action ) {
			echo ' ' . $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping by callers.
		}
		echo '<hr class="wp-header-end">';
		Admin::render_notices();
	}

	/**
	 * Page footer.
	 */
	public static function footer() {
		echo '</div>';
	}
}
