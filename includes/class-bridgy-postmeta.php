<?php
// Adds Post Meta Box for Bridgy Publish

add_action( 'init' , array('bridgy_postmeta', 'init') );

// The bridgy_postmeta class sets up a post meta box to publish using Bridgy
class bridgy_postmeta {

	/**
	 *
	 */
	public static function init() {
		$options = get_option('bridgy_options');

		add_action('load-post.php', array('bridgy_postmeta' , 'bridgybox_setup' ) );
		add_action('load-post-new.php', array('bridgy_postmeta', 'bridgybox_setup') );

		// save checkboxes
		// also triggers the filter on the checkboxes for auto-magic
		add_action('save_post', array('bridgy_postmeta', 'save_post'), 8, 2 );

		// the actual worker
		add_action('transition_post_status', array('bridgy_postmeta', 'transition_post_status') ,12,5);

		// insert required <a href=>-s for Bridgy to execut the publish
		// without this, bridgy will say "Couldn't find link to brid.gy/publish/..."
		// and you'll spend a lot of time debugging the issue
		add_filter('the_content', array('bridgy_postmeta', 'the_content'), 2 );

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
		$type = 'post';
		if ( isset( $_POST['post_type'] ) && 'page' == $_POST['post_type'] )
			$type = 'page';

		if ( ! current_user_can( "edit_{$type}", $post_id ) ) {
			return;
		}

		$current = get_post_meta ( $post_id,'_bridgy_options', true );
		$bridgy_checkboxes = static::bridgy_checkboxes();
		$bridgy = array();
		// OK, its safe for us to save the data now.
		foreach ($bridgy_checkboxes as $key => $value) {
			if (isset( $_POST['bridgy_'.$key]) ) {
				$bridgy[$key]= 'yes';
			}
		}

		$bridgy = apply_filters ('bridgy_publish_urls', $bridgy, $post_id);

		if(!empty($bridgy) && $bridgy != $current ) {
			update_post_meta( $post_id,'_bridgy_options', $bridgy, $current);
		}

		return $bridgy;
	}

	/**
	 *
	 */
	public static function transition_post_status($new, $old, $post) {


		if ($new != 'publish')
			return false;

		$bridgy = static::save_post($post->ID,$post);

		$options = get_option('bridgy_options');

		if ($options['shortlinks'] == 1 )
			$url =  wp_get_shortlink($post->ID);
		else
			$url = get_permalink($post->ID);


		if ( ! empty($bridgy) ) {
			foreach ($bridgy as $key => $value) {
				$response = send_webmention($url, "https://brid.gy/publish/{$key}", $post->ID);
				$response_code = wp_remote_retrieve_response_code( $response );
				$json = json_decode($response['body']);

				if ($response_code == 200) {
					static::debug('Bridgy did the magic and responded: ' . $json->url, 5);
					static::add_syndication($post->ID, $json->url);
				}
				else {
					static::debug('Bridgy said something is wrong: ' . $json->error, 4);
				}
			}
		}
	}

	/**
	 * content filter: add neccessary <a> for bridgy, otherwise bridgy refuses
	 * to take care of this post
	 * also add parameters for bridgy, if those are enabled
	 *
	 * @param string $content - post content to extend
	 *
	 * @return string $content - extended content string
	 */
	public static function the_content($content) {
		$bridgy = get_post_meta(get_the_ID(), '_bridgy_options', true);

		if (empty($bridgy))
			return $content;

		$options = get_option('bridgy_options');
		$classes = $publish = array();

		if ($options['omitlink']==1)
			$classes[] = 'u-bridgy-omit-link';

		if ($options['ignoreformatting']==1)
			$classes[] = 'u-bridgy-ignore-formatting';

		$class = join(' ', $classes);

		foreach ($bridgy as $key => $value) {
			$publish[] = '<a class="' . $class . '" href="https://www.brid.gy/publish/' . $key . '"></a>';
		}

		return $content . "\n" . join ("\n", $publish);
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

		foreach ($urls as $url) {
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
	 *
	 * @output log to syslog | wp_die on high level
	 * @return false on not taking action, true on log sent
	 */
	protected static function debug( $message, $level = LOG_NOTICE ) {

		if ( empty( $message ) )
			return false;

		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);

		$levels = array (
			LOG_EMERG => 0, // system is unusable
			LOG_ALERT => 1, // Alert 	action must be taken immediately
			LOG_CRIT => 2, // Critical 	critical conditions
			LOG_ERR => 3, // Error 	error conditions
			LOG_WARNING => 4, // Warning 	warning conditions
			LOG_NOTICE => 5, // Notice 	normal but significant condition
			LOG_INFO => 6, // Informational 	informational messages
			LOG_DEBUG => 7, // Debug 	debug-level messages
		);

		// number for number based comparison
		// should work with the defines only, this is just a make-it-sure step
		$level_ = $levels [ $level ];

		// in case WordPress debug log has a minimum level
		if ( defined ( 'WP_DEBUG_LEVEL' ) ) {
			$wp_level = $levels [ WP_DEBUG_LEVEL ];
			if ( $level_ > $wp_level ) {
				return false;
			}
		}

		// ERR, CRIT, ALERT and EMERG
		if ( 3 >= $level_ ) {
			wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
			exit;
		}

		$trace = debug_backtrace();
		$caller = $trace[1];
		$parent = $caller['function'];

		if (isset($caller['class']))
			$parent = $caller['class'] . '::' . $parent;

		return error_log( "{$parent}: {$message}" );
	}
} // End Class

?>
