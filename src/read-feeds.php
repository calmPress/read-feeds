<?php
/**
 * Main file for the plugin
 *
 * @package     read-feeds
 *
 * Plugin Name: calm Read Feeds
 * Plugin URI:  https://example.com/plugin-name
 * Description: Implementation of "RSS widget" and relevant API to fecth feeds.
 * Version:     1.0.0
 * Author:      calmPress
 * Author URI:  https://calmpress.org
 * Text Domain: calm_read_feeds
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

declare(strict_types=1);

namespace calmpress\readfeeds;

/**
 * Build SimplePie object based on RSS or Atom feed from URL.
 *
 * @since 2.8.0
 *
 * @param mixed $url URL of feed to retrieve. If an array of URLs, the feeds are merged
 *                   using SimplePie's multifeed feature.
 *                   See also {@link http://simplepie.org/wiki/faq/typical_multifeed_gotchas}.
 *
 * @return WP_Error|SimplePie WP_Error object on failure or SimplePie object on success
 */
function fetch_feed( $url ) {

	require_once __DIR__ . '/class-simplepie.php';
	require_once __DIR__ . '/class-wp-feed-cache.php';
	require_once __DIR__ . '/class-wp-feed-cache-transient.php';
	require_once __DIR__ . '/class-wp-simplepie-file.php';
	require_once __DIR__ . '/class-wp-simplepie-sanitize-kses.php';

	$feed = new \SimplePie();

	$feed->set_sanitize_class( 'WP_SimplePie_Sanitize_KSES' );
	// We must manually overwrite $feed->sanitize because SimplePie's
	// constructor sets it before we have a chance to set the sanitization class.
	$feed->sanitize = new \WP_SimplePie_Sanitize_KSES();

	$feed->set_cache_class( 'WP_Feed_Cache' );
	$feed->set_file_class( 'WP_SimplePie_File' );

	$feed->set_feed_url( $url );
	/** This filter is documented in wp-includes/class-wp-feed-cache-transient.php */
	$feed->set_cache_duration( apply_filters( 'wp_feed_cache_transient_lifetime', 12 * HOUR_IN_SECONDS, $url ) );
	/**
	 * Fires just before processing the SimplePie feed object.
	 *
	 * @since 3.0.0
	 *
	 * @param object $feed SimplePie feed object (passed by reference).
	 * @param mixed  $url  URL of feed to retrieve. If an array of URLs, the feeds are merged.
	 */
	do_action_ref_array( 'wp_feed_options', array( &$feed, $url ) );
	$feed->init();
	$feed->set_output_encoding( get_option( 'blog_charset' ) );

	if ( $feed->error() ) {
		return new \WP_Error( 'simplepie-error', $feed->error() );
	}

	return $feed;
}

/**
 * Display the RSS entries in a list.
 *
 * @since WordPress 2.5.0
 *
 * @param string|array|object $rss RSS url.
 * @param array               $args Widget arguments.
 */
function wp_widget_rss_output( $rss, $args = array() ) {
	if ( is_string( $rss ) ) {
		$rss = fetch_feed( $rss );
	} elseif ( is_array( $rss ) && isset( $rss['url'] ) ) {
		$args = $rss;
		$rss  = fetch_feed( $rss['url'] );
	} elseif ( ! is_object( $rss ) ) {
		return;
	}

	if ( is_wp_error( $rss ) ) {
		if ( is_admin() || current_user_can( 'manage_options' ) ) {
			echo '<p><strong>' . esc_html__( 'RSS Error:', 'calm_read_feeds' ) . '</strong> ' . esc_html( $rss->get_error_message() ) . '</p>';
		}
		return;
	}

	$default_args = array(
		'show_author'  => 0,
		'show_date'    => 0,
		'show_summary' => 0,
		'items'        => 0,
	);
	$args         = wp_parse_args( $args, $default_args );

	$items = (int) $args['items'];
	if ( $items < 1 || 20 < $items ) {
		$items = 10;
	}
	$show_summary = (int) $args['show_summary'];
	$show_author  = (int) $args['show_author'];
	$show_date    = (int) $args['show_date'];

	if ( ! $rss->get_item_quantity() ) {
		echo '<ul><li>' . esc_html__( 'An error has occurred, which probably means the feed is down. Try again later.', 'calm_read_feeds' ) . '</li></ul>';
		return;
	}

	echo '<ul>';
	foreach ( $rss->get_items( 0, $items ) as $item ) {
		$link = $item->get_link();
		while ( stristr( $link, 'http' ) !== $link ) {
			$link = substr( $link, 1 );
		}
		$link = esc_url( strip_tags( $link ) );

		$title = esc_html( trim( strip_tags( $item->get_title() ) ) );
		if ( empty( $title ) ) {
			$title = __( 'Untitled', 'calm_read_feeds' );
		}

		$desc = @html_entity_decode( $item->get_description(), ENT_QUOTES, get_option( 'blog_charset' ) );
		$desc = esc_attr( wp_trim_words( $desc, 55, ' [&hellip;]' ) );

		$summary = '';
		if ( $show_summary ) {
			$summary = $desc;

			// Change existing [...] to [&hellip;].
			if ( '[...]' === substr( $summary, -5 ) ) {
				$summary = substr( $summary, 0, -5 ) . '[&hellip;]';
			}

			$summary = '<div class="rssSummary">' . esc_html( $summary ) . '</div>';
		}

		$date = '';
		if ( $show_date ) {
			$date = $item->get_date( 'U' );

			if ( $date ) {
				$date = ' <span class="rss-date">' . date_i18n( get_option( 'date_format' ), $date ) . '</span>';
			}
		}

		$author = '';
		if ( $show_author ) {
			$author = $item->get_author();
			if ( is_object( $author ) ) {
				$author = $author->get_name();
				$author = ' <cite>' . esc_html( strip_tags( $author ) ) . '</cite>';
			}
		}

		if ( '' === $link ) {
			echo "<li>$title{$date}{$summary}{$author}</li>";
		} elseif ( $show_summary ) {
			echo "<li><a class='rsswidget' href='$link'>$title</a>{$date}{$summary}{$author}</li>";
		} else {
			echo "<li><a class='rsswidget' href='$link'>$title</a>{$date}{$author}</li>";
		}
	}
	echo '</ul>';
}

/**
 * Display RSS widget options form.
 *
 * The options for what fields are displayed for the RSS form are all booleans
 * and are as follows: 'url', 'title', 'items', 'show_summary', 'show_author',
 * 'show_date'.
 *
 * @since WordPress 2.5.0
 *
 * @param array|string $args   Values for input fields.
 * @param array        $inputs Override default display options.
 */
function wp_widget_rss_form( $args, $inputs = null ) {
	$default_inputs = array(
		'url'          => true,
		'title'        => true,
		'items'        => true,
		'show_summary' => true,
		'show_author'  => true,
		'show_date'    => true,
	);
	$inputs         = wp_parse_args( $inputs, $default_inputs );

	$args['title'] = isset( $args['title'] ) ? $args['title'] : '';
	$args['url']   = isset( $args['url'] ) ? $args['url'] : '';
	$args['items'] = isset( $args['items'] ) ? (int) $args['items'] : 0;

	if ( $args['items'] < 1 || 20 < $args['items'] ) {
		$args['items'] = 10;
	}

	$args['show_summary'] = isset( $args['show_summary'] ) ? (int) $args['show_summary'] : (int) $inputs['show_summary'];
	$args['show_author']  = isset( $args['show_author'] ) ? (int) $args['show_author'] : (int) $inputs['show_author'];
	$args['show_date']    = isset( $args['show_date'] ) ? (int) $args['show_date'] : (int) $inputs['show_date'];

	if ( ! empty( $args['error'] ) ) {
		echo '<p class="widget-error"><strong>' . esc_html__( 'RSS Error:', 'calm_read_feeds' ) . '</strong> ' . esc_html( $args['error'] ) . '</p>';
	}

	$esc_number = esc_attr( $args['number'] );
	if ( $inputs['url'] ) :
		?>
	<p><label for="rss-url-<?php echo esc_attr( $esc_number ); ?>"><?php esc_html_e( 'Enter the RSS feed URL here:' ); ?></label>
	<input class="widefat" id="rss-url-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][url]" type="text" value="<?php echo esc_url( $args['url'] ); ?>" /></p>
<?php endif; if ( $inputs['title'] ) : ?>
	<p><label for="rss-title-<?php echo esc_attr( $esc_number ); ?>"><?php esc_html_e( 'Give the feed a title (optional):' ); ?></label>
	<input class="widefat" id="rss-title-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][title]" type="text" value="<?php echo esc_attr( $args['title'] ); ?>" /></p>
<?php endif; if ( $inputs['items'] ) : ?>
	<p><label for="rss-items-<?php echo esc_attr( $esc_number ); ?>"><?php esc_html_e( 'How many items would you like to display?' ); ?></label>
	<select id="rss-items-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][items]">
	<?php
	for ( $i = 1; $i <= 20; ++$i ) {
		echo "<option value='" . esc_attr( $i ) . "' " . selected( $args['items'], $i, false ) . '>' . esc_html( $i ) . '</option>';
	}
	?>
	</select></p>
<?php endif; if ( $inputs['show_summary'] ) : ?>
	<p><input id="rss-show-summary-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][show_summary]" type="checkbox" value="1" <?php checked( $args['show_summary'] ); ?> />
	<label for="rss-show-summary-<?php echo esc_attr( $esc_number ); ?>"><?php esc_html_e( 'Display item content?' ); ?></label></p>
<?php endif; if ( $inputs['show_author'] ) : ?>
	<p><input id="rss-show-author-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][show_author]" type="checkbox" value="1" <?php checked( $args['show_author'] ); ?> />
	<label for="rss-show-author-<?php echo esc_attr( $esc_number ); ?>"><?php esc_html_e( 'Display item author if available?' ); ?></label></p>
<?php endif; if ( $inputs['show_date'] ) : ?>
	<p><input id="rss-show-date-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][show_date]" type="checkbox" value="1" <?php checked( $args['show_date'] ); ?>/>
	<label for="rss-show-date-<?php echo esc_attr( $esc_number ); ?>"><?php esc_html_e( 'Display item date?' ); ?></label></p>
	<?php
	endif;
foreach ( array_keys( $default_inputs ) as $input ) :
	if ( 'hidden' === $inputs[ $input ] ) :
		$id = str_replace( '_', '-', $input );
		?>
<input type="hidden" id="rss-<?php echo esc_attr( $id ); ?>-<?php echo esc_attr( $esc_number ); ?>" name="widget-rss[<?php echo esc_attr( $esc_number ); ?>][<?php echo esc_attr( $input ); ?>]" value="<?php echo esc_attr( $args[ $input ] ); ?>" />
		<?php
	endif;
	endforeach;
}

/**
 * Process RSS feed widget data and optionally retrieve feed items.
 *
 * The feed widget can not have more than 20 items or it will reset back to the
 * default, which is 10.
 *
 * The resulting array has the feed title, feed url, feed link (from channel),
 * feed items, error (if any), and whether to show summary, author, and date.
 * All respectively in the order of the array elements.
 *
 * @since WordPress 2.5.0
 *
 * @param array $widget_rss RSS widget feed data. Expects unescaped data.
 * @param bool  $check_feed Optional, default is true. Whether to check feed for errors.
 * @return array
 */
function wp_widget_rss_process( $widget_rss, $check_feed = true ) {
	$items = (int) $widget_rss['items'];
	if ( $items < 1 || 20 < $items ) {
		$items = 10;
	}
	$url          = esc_url_raw( strip_tags( $widget_rss['url'] ) );
	$title        = isset( $widget_rss['title'] ) ? trim( strip_tags( $widget_rss['title'] ) ) : '';
	$show_summary = isset( $widget_rss['show_summary'] ) ? (int) $widget_rss['show_summary'] : 0;
	$show_author  = isset( $widget_rss['show_author'] ) ? (int) $widget_rss['show_author'] : 0;
	$show_date    = isset( $widget_rss['show_date'] ) ? (int) $widget_rss['show_date'] : 0;

	if ( $check_feed ) {
		$rss   = fetch_feed( $url );
		$error = false;
		$link  = '';
		if ( is_wp_error( $rss ) ) {
			$error = $rss->get_error_message();
		} else {
			$link = esc_url( strip_tags( $rss->get_permalink() ) );
			while ( stristr( $link, 'http' ) !== $link ) {
				$link = substr( $link, 1 );
			}
		}
	}

	return compact( 'title', 'url', 'link', 'items', 'error', 'show_summary', 'show_author', 'show_date' );
}

add_action( 'widgets_init', function() {
	require_once __DIR__ . '/widgets/class-wp-widget-rss.php';
	register_widget( 'WP_Widget_RSS' );
} );
