<?php
// Adds Post Meta Box for Bridgy Publish

add_action( 'init' , array('bridgy_postmeta', 'init') );

// The bridgy_postmeta class sets up a post meta box to publish using Bridgy
class bridgy_postmeta {

	/**
	 *
	 */
	public static function init() {
		// Add meta box to new post/post pages only
		add_action('load-post.php', array('bridgy_postmeta' , 'bridgybox_setup' ) );
		add_action('load-post-new.php', array('bridgy_postmeta', 'bridgybox_setup') );
		add_action('save_post', array('bridgy_postmeta', 'save_post'), 8, 2 );
		add_action('transition_post_status', array('bridgy_postmeta', 'transition_post_status') ,12,5);

		// auto-add syndication links to post
		add_filter('the_content', array('bridgy_postmeta', 'the_content') );

		add_filter('syn_add_links', array('bridgy_postmeta', 'syn_add_links') );
	}

	/**
	 * Meta box setup function.
	 *
	 */
	public static function bridgybox_setup() {
		// Add meta boxes on the 'add_meta_boxes' hook.
		add_action( 'add_meta_boxes', array('bridgy_postmeta', 'add_postmeta_boxes') );
	}

	/**
	 * Create one or more meta boxes to be displayed on the post editor screen.
	 */
	public static function add_postmeta_boxes() {
		add_meta_box(
			'bridgybox-meta', // Unique ID
			esc_html__( 'Bridgy Publish To', 'Bridgy Publish' ), // Title
			array('bridgy_postmeta', 'metabox'), // Callback function
			'post', // Admin page (or post type)
			'side', // Context
			'default' // Priority
		);
	}

	/**
	 *
	 */
	public static function bridgy_checkboxes() {
		$options = get_option('bridgy_options');
		$bridgy_checkboxes = array(
						'twitter' => _x( "Twitter", 'Bridgy Publish' ),
						'facebook' => _x( "Facebook", 'Bridgy Publish' ),
						'instagram' => _x( "Instagram", 'Bridgy Publish' ),
						'flickr' => _x( "Flickr", 'Bridgy Publish' ),
						);
		if($options) {
			foreach ($options as $key => $value) {
				if($options[$key]==0) {
					unset($bridgy_checkboxes[$key]);
				}
			}
		}
		return $bridgy_checkboxes;
	}

	/**
	 *
	 */
	public static function metabox( $object, $box ) {
		wp_nonce_field( 'bridgy_metabox', 'bridgy_metabox_nonce' );
		$bridgy_checkboxes = static::bridgy_checkboxes();
		$bridgy = get_post_meta(get_the_ID(), '_bridgy_options', true);
		echo '<ul>';
		foreach ($bridgy_checkboxes as $key => $value) {
			echo '<li>';
			echo '<input type="checkbox" name="bridgy_' . $key . '"';
			if (isset($bridgy[$key]) ) {
				echo ' value="yes" ' . checked( $bridgy[$key], 'yes' ) . '"';
			}
			echo ' />';
	  echo '<label for="bridgy_' . $key . '">' . $value . '</label>';
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Save the meta box's post metadata.
	 */
	public static function save_post( $post_id, $post ) {
		/*
		 * We need to verify this came from our screen and with proper authorization,
		 * because the save_post action can be triggered at other times.
		 */
		// Check if our nonce is set.
		if ( ! isset( $_POST['bridgy_metabox_nonce'] ) ) {
			return;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( $_POST['bridgy_metabox_nonce'], 'bridgy_metabox' ) ) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check the user's permissions.
		if ( isset( $_POST['post_type'] ) && 'page' == $_POST['post_type'] ) {
			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return;
			}
		}
		else {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}
		}

		$bridgy_changed = false;
		$current = get_post_meta ( $post_id,'_bridgy_options', true );
		$bridgy_checkboxes = static::bridgy_checkboxes();
		$bridgy=array();
		// OK, its safe for us to save the data now.
		foreach ($bridgy_checkboxes as $key => $value) {
			if (isset( $_POST['bridgy_'.$key]) ) {
				$bridgy[$key]= 'yes';
			}
		}

		if(!empty($bridgy) && $bridgy != $current ) {
			update_post_meta( $post_id,'_bridgy_options', $bridgy, $current);
			$bridgy_changed = true;
		}

		return $bridgy_changed;
	}

	/**
	 *
	 */
	public static function transition_post_status($new, $old, $post) {

		if ($new == 'publish') {
			$changed = static::save_post($post->ID,$post);
		}

		$bridgy = get_post_meta($post->ID, '_bridgy_options', true);
		$syn = get_post_meta($post->ID, 'bridgy_syndication', true);
		$options = get_option('bridgy_options');

		if ($options['shortlinks'] == 1 ) {
			$url = wp_get_shortlink($post->ID);
		}
		else {
			$url = get_permalink($post->ID);
		}

		if (!$syn) {
				$syn="";
		}

		$bridgy = apply_filters ('bridgy_publish_urls', $bridgy, $post->ID);

		if ( ! empty($bridgy) ) {
			foreach ($bridgy as $key => $value) {
				$response = send_webmention($url, 'https://www.brid.gy/publish/' . $key);
				$response_code = wp_remote_retrieve_response_code( $response );
				$json = json_decode($response['body']);
				if ($response_code==200) {
					$syn = "\n" . $json->url;
				}
				if (($response_code==400)||($response_code==500)) {
					static::debug($json->error);
				}
				static::debug('Help: ' . $syn);
			}

			if (!empty($syn)) {
				update_post_meta($post->ID, 'bridgy_syndication', $syn);
			}
			else {
				delete_post_meta($post->ID, 'bridgy_syndication');
			}
		}
	}

	/**
	 *
	 */
	public static function the_content($content) {
		$bridgy = get_post_meta(get_the_ID(), '_bridgy_options', true);

		if (empty($bridgy))
			return $content;


		$options = get_option('bridgy_options');

		if (isset($options['nodisplay']) && $options['nodisplay']==1) {
			return $content;
		}

		$publish = "";
		$classes = array();

		if (isset($options['omitlink']) && $options['omitlink']==1) {
			$classes[] = 'u-bridgy-omit-link';
		}

		if (isset($options['ignoreformatting']) && $options['ignoreformatting']==1) {
			$classes[] = 'u-bridgy-ignore-formatting';
		}

		$class = implode(' ', $classes);
		foreach ($bridgy as $key => $value) {
			$publish .= '<a class="' . $class . '" href="https://www.brid.gy/publish/' . $key . '"></a>';
		}
		return $content . $publish;
	}

	/**
	 *
	 */
	public static function syn_add_links($urls) {

		$bridgy = get_post_meta(get_the_ID(), 'bridgy_syndication');

		if(is_array($bridgy)) {
			if (method_exists('syn_meta', 'clean_urls')) {
				$urls = array_merge($urls, syn_meta::clean_urls($bridgy) );
			}
			else {
				$url = static::add_syndication($urls);
			}
		}

		return $urls;
	}


	/**
	 *
	 */
	public static function add_syndication ( $postid, $urls ) {
		if (empty($urls))
			return;

		if (!is_array($urls))
			$urls = array ($urls);

		$curr = $orig = get_post_meta ( $postid, 'syndication_urls', true );
		if ($curr && strstr($curr, "\n" ))
			$curr = split("\n", $curr);
		else
			$curr = array ($curr);

		foreach ($curr as $key => $curl )
			$curr[$key] = rtrim(trim($curl), '/');

		foreach ($url as $url) {

			$url = rtrim(trim($url), '/');
			if (!in_array($url, $curr))
				array_push($curr, $url);
		}

		$curr = apply_filters ('syndication_urls', $curr, $postid);
		update_post_meta ( $postid, 'syndication_urls', join("\n", $curr), $orig );
		return $curr;
	}

	/**
	 *
	 * debug messages; will only work if WP_DEBUG is on
	 * or if the level is LOG_ERR, but that will kill the process
	 *
	 * @param string $message
	 * @param int $level
	 */
	public static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . " => " . $message );
	}
} // End Class

?>
