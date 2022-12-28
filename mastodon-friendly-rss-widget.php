<?php

/**
 * Plugin Name: Mastodon-Friendly RSS Widget
 * Plugin URI: https://github.com/tobyink/php-mastodon-friendly-rss-widget
 * Description: Subclass of the built-in RSS Widget, but copes better with Mastodon feeds.
 * Version: 0.1
 * Requires at least: 6.1
 * Requires PHP: 8.0
 * Author: Toby Inkster
 * Author URI: https://toby.ink/
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

require_once( ABSPATH . '/wp-includes/widgets/class-wp-widget-rss.php' );

class WP_Widget_RSS_MastodonFriendly extends WP_Widget_RSS {
	public function __construct() {
		$widget_ops = array(
			'description'                 => __( 'Mastodon feed.' ),
			'customize_selective_refresh' => true,
			'show_instance_in_rest'       => true,
		);
		$control_ops = array(
			'width'  => 400,
			'height' => 200,
		);
		WP_Widget::__construct( 'rss_mastodon', __( 'RSS (Mastodon Friendly)' ), $widget_ops, $control_ops );
	}

	public function widget( $args, $instance ) {
		if ( isset( $instance['error'] ) && $instance['error'] ) {
			return;
		}

		$url = ! empty( $instance['url'] ) ? $instance['url'] : '';
		while ( ! empty( $url ) && stristr( $url, 'http' ) !== $url ) {
			$url = substr( $url, 1 );
		}

		if ( empty( $url ) ) {
			return;
		}

		// Self-URL destruction sequence.
		if ( in_array( untrailingslashit( $url ), array( site_url(), home_url() ), true ) ) {
			return;
		}

		$rss   = fetch_feed( $url );
		$title = $instance['title'];
		$desc  = '';
		$link  = '';

		if ( ! is_wp_error( $rss ) ) {
			$desc = esc_attr( strip_tags( html_entity_decode( $rss->get_description(), ENT_QUOTES, get_option( 'blog_charset' ) ) ) );
			if ( empty( $title ) ) {
				$title = strip_tags( $rss->get_title() );
			}
			$link = strip_tags( $rss->get_permalink() );
			while ( ! empty( $link ) && stristr( $link, 'http' ) !== $link ) {
				$link = substr( $link, 1 );
			}
		}

		if ( empty( $title ) ) {
			$title = ! empty( $desc ) ? $desc : __( 'Unknown Feed' );
		}

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		if ( $title ) {
			$feed_link = '';
			$feed_url  = strip_tags( $url );
			$feed_icon = includes_url( 'images/rss.png' );
			$feed_link = sprintf(
				'<a class="rsswidget rss-widget-feed" href="%1$s"><img class="rss-widget-icon" style="border:0" width="14" height="14" src="%2$s" alt="%3$s"%4$s /></a> ',
				esc_url( $feed_url ),
				esc_url( $feed_icon ),
				esc_attr__( 'RSS' ),
				( wp_lazy_loading_enabled( 'img', 'rss_widget_feed_icon' ) ? ' loading="lazy"' : '' )
			);

			/**
			 * Filters the classic RSS widget's feed icon link.
			 *
			 * Themes can remove the icon link by using `add_filter( 'rss_widget_feed_link', '__return_false' );`.
			 *
			 * @since 5.9.0
			 *
			 * @param string $feed_link HTML for link to RSS feed.
			 * @param array  $instance  Array of settings for the current widget.
			 */
			$feed_link = apply_filters( 'rss_widget_feed_link', $feed_link, $instance );

			$title = $feed_link . '<a class="rsswidget rss-widget-title" href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
		}

		echo $args['before_widget'];
		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		$format = current_theme_supports( 'html5', 'navigation-widgets' ) ? 'html5' : 'xhtml';

		/** This filter is documented in wp-includes/widgets/class-wp-nav-menu-widget.php */
		$format = apply_filters( 'navigation_widgets_format', $format );

		if ( 'html5' === $format ) {
			// The title may be filtered: Strip out HTML and make sure the aria-label is never empty.
			$title      = trim( strip_tags( $title ) );
			$aria_label = $title ? $title : __( 'RSS Feed' );
			echo '<nav aria-label="' . esc_attr( $aria_label ) . '">';
		}

		wp_widget_rss_mastodon_output( $rss, $instance );

		if ( 'html5' === $format ) {
			echo '</nav>';
		}

		echo $args['after_widget'];

		if ( ! is_wp_error( $rss ) ) {
			$rss->__destruct();
		}
		unset( $rss );
	}

	public function form( $instance ) {
		if ( empty( $instance ) ) {
			$instance = array(
				'title'        => '',
				'url'          => '',
				'items'        => 10,
				'error'        => false,
				'show_summary' => 0,
				'show_author'  => 0,
				'show_date'    => 0,
			);
		}
		$instance['number'] = $this->number;

		wp_widget_rss_mastodon_form( $instance );
	}
}

function wp_widget_rss_mastodon_output( $rss, $args = array() ) {
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
			echo '<p><strong>' . __( 'RSS Error:' ) . '</strong> ' . esc_html( $rss->get_error_message() ) . '</p>';
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
		echo '<ul><li>' . __( 'An error has occurred, which probably means the feed is down. Try again later.' ) . '</li></ul>';
		$rss->__destruct();
		unset( $rss );
		return;
	}

	echo '<ul>';
	foreach ( $rss->get_items( 0, $items ) as $item ) {
		$link = $item->get_link();
		while ( ! empty( $link ) && stristr( $link, 'http' ) !== $link ) {
			$link = substr( $link, 1 );
		}
		$link = esc_url( strip_tags( $link ) );

		$title = esc_html( trim( strip_tags( $item->get_title() ) ) );

		$desc = html_entity_decode( $item->get_description(), ENT_QUOTES, get_option( 'blog_charset' ) );
		$desc = esc_attr( wp_trim_words( $desc, 55, ' [&hellip;]' ) );

		$summary = '';
		if ( $show_summary ) {
			$summary = $desc;

			// Change existing [...] to [&hellip;].
			if ( '[...]' === substr( $summary, -5 ) ) {
				$summary = substr( $summary, 0, -5 ) . '[&hellip;]';
			}

			$summary = '<div class="rssSummary">' . make_clickable( esc_html( $summary ) ) . '</div>';
		}

		$date = '';
		if ( $show_date ) {
			$date = $item->get_date( 'U' );

			if ( $date ) {
				$date = ' <span class="rss-date">' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date ) . '</span>';
			}
		}

		if ( empty( $title ) ) {
			$title = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->get_date( 'U' ) );
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
	$rss->__destruct();
	unset( $rss );
}

function wp_widget_rss_mastodon_form( $args, $inputs = null ) {
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
		echo '<p class="widget-error"><strong>' . __( 'RSS Error:' ) . '</strong> ' . esc_html( $args['error'] ) . '</p>';
	}

	$esc_number = esc_attr( $args['number'] );
	if ( $inputs['url'] ) :
		?>
	<p><label for="rss_mastodon-url-<?php echo $esc_number; ?>"><?php _e( 'Enter the RSS feed URL here:' ); ?></label>
	<input class="widefat" id="rss_mastodon-url-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][url]" type="text" value="<?php echo esc_url( $args['url'] ); ?>" /></p>
<?php endif; if ( $inputs['title'] ) : ?>
	<p><label for="rss_mastodon-title-<?php echo $esc_number; ?>"><?php _e( 'Give the feed a title (optional):' ); ?></label>
	<input class="widefat" id="rss_mastodon-title-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][title]" type="text" value="<?php echo esc_attr( $args['title'] ); ?>" /></p>
<?php endif; if ( $inputs['items'] ) : ?>
	<p><label for="rss_mastodon-items-<?php echo $esc_number; ?>"><?php _e( 'How many items would you like to display?' ); ?></label>
	<select id="rss_mastodon-items-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][items]">
	<?php
	for ( $i = 1; $i <= 20; ++$i ) {
		echo "<option value='$i' " . selected( $args['items'], $i, false ) . ">$i</option>";
	}
	?>
	</select></p>
<?php endif; if ( $inputs['show_summary'] || $inputs['show_author'] || $inputs['show_date'] ) : ?>
	<p>
	<?php if ( $inputs['show_summary'] ) : ?>
		<input id="rss_mastodon-show-summary-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][show_summary]" type="checkbox" value="1" <?php checked( $args['show_summary'] ); ?> />
		<label for="rss_mastodon-show-summary-<?php echo $esc_number; ?>"><?php _e( 'Display item content?' ); ?></label><br />
	<?php endif; if ( $inputs['show_author'] ) : ?>
		<input id="rss_mastodon-show-author-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][show_author]" type="checkbox" value="1" <?php checked( $args['show_author'] ); ?> />
		<label for="rss_mastodon-show-author-<?php echo $esc_number; ?>"><?php _e( 'Display item author if available?' ); ?></label><br />
	<?php endif; if ( $inputs['show_date'] ) : ?>
		<input id="rss_mastodon-show-date-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][show_date]" type="checkbox" value="1" <?php checked( $args['show_date'] ); ?>/>
		<label for="rss_mastodon-show-date-<?php echo $esc_number; ?>"><?php _e( 'Display item date?' ); ?></label><br />
	<?php endif; ?>
	</p>
	<?php
	endif; // End of display options.
foreach ( array_keys( $default_inputs ) as $input ) :
	if ( 'hidden' === $inputs[ $input ] ) :
		$id = str_replace( '_', '-', $input );
		?>
<input type="hidden" id="rss_mastodon-<?php echo esc_attr( $id ); ?>-<?php echo $esc_number; ?>" name="widget-rss_mastodon[<?php echo $esc_number; ?>][<?php echo esc_attr( $input ); ?>]" value="<?php echo esc_attr( $args[ $input ] ); ?>" />
		<?php
	endif;
	endforeach;
}

add_action( 'widgets_init', function () {
	register_widget( 'WP_Widget_RSS_MastodonFriendly' );
} );

