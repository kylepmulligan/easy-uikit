<?php

/**
 * Github updater
 *
 * @since  1.1.1
 *
 */
class github_updater {

	private $file;
	private $plugin;
	private $basename;
	private $active;
	private $username;
	private $repository;
	private $authorize_token;
	private $github_response;

	private $plugin_settings;

	/**
	 * __constructor for the class
	 * @param [type] $file [description]
	 */
	public function __construct( $file ) {
		$this->file = $file;
		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );
		return $this;
	}

	/**
	 * [set_plugin_properties description]
	 */
	public function set_plugin_properties() {
		$this->plugin	= get_plugin_data( $this->file );
		$this->basename = plugin_basename( $this->file );
		$this->active	= is_plugin_active( $this->basename );
	}

	/**
	 * [set_username description]
	 * @param [type] $username [description]
	 */
	public function set_username( $username ) {
		$this->username = $username;
	}

	/**
	 * [set_settings description]
	 * @param [type] $settings [description]
	 */
	public function set_settings( $settings ) {

		// set some defaults in case someone forgets to set these
		$defaults = array(
			'requires'			=> '4.6',
			'tested'			=> '5.1.1',
			'rating'			=> '100.0',
			'num_ratings'		=> '10',
			'downloaded'		=> '10',
			'added'				=> '2019-04-06',
		);

		$settings = wp_parse_args( $settings , $defaults );

		$this->plugin_settings = $settings;
	}

	/**
	 * [set_repository description]
	 * @param [type] $repository [description]
	 */
	public function set_repository( $repository ) {
		$this->repository = $repository;
	}

	/**
	 * [authorize description]
	 * @param  [type] $token [description]
	 * @return [type]        [description]
	 */
	public function authorize( $token ) {
		$this->authorize_token = $token;
	}

	/**
	 * [get_repository_info description]
	 * @return [type] [description]
	 */
	private function get_repository_info() {

		// Do we have a response?
	    if ( is_null( $this->github_response ) ) {
	    	// Build URI
	        $request_uri = sprintf( 'https://api.github.com/repos/%s/%s/releases', $this->username, $this->repository );

	        // Is there an access token?
	        if( $this->authorize_token ) {
	        	// Append it
	            $request_uri = add_query_arg( 'access_token', $this->authorize_token, $request_uri );
	        }

	        // Get JSON and parse it
	        $response = json_decode( wp_remote_retrieve_body( wp_remote_get( $request_uri ) ), true );

	        // If it is an array
	        if( is_array( $response ) ) {
	        	// Get the first item
	            $response = current( $response );
	        }
	        // Is there an access token?
	        if( $this->authorize_token ) {
	        	// Update our zip url with token
	            $response['zipball_url'] = add_query_arg( 'access_token', $this->authorize_token, $response['zipball_url'] );
	        }
	        // Set it to our property
	        $this->github_response = $response;
	        return $response;
	    }

	}

	/**
	 * [initialize description]
	 * @return [type] [description]
	 */
	public function initialize() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
		add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3);
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
	}

	/**
	 * [modify_transient description]
	 * @param  [type] $transient [description]
	 * @return [type]            [description]
	 */
	public function modify_transient( $transient ) {

		// Check if transient has a checked property
		if ( property_exists( $transient, 'checked') ) {

		 	// Did Wordpress check for updates?
			if ( $checked = $transient->checked ) {

				// return early if our plugin hasn't been checked
				if( !isset( $checked[ $this->basename ] ) ) return $transient;

				// Get the repo info
				$this->get_repository_info();

				// Check if we're out of date
				$out_of_date = version_compare( $this->github_response['tag_name'], $checked[ $this->basename ], 'gt' );
				if( $out_of_date ) {

					// Get the ZIP
					$new_files = $this->github_response['zipball_url'];

					// Create valid slug
					$slug = current( explode('/', $this->basename ) );

					// setup our plugin info
					$plugin = array(
						'url' => $this->plugin["PluginURI"],
						'slug' => $slug,
						'package' => $new_files,
						'new_version' => $this->github_response['tag_name']
					);

					// Return it in response
					$transient->response[$this->basename] = (object) $plugin;
				}
			}
		}

		// Return filtered transient
		return $transient;
	}

	/**
	 * [plugin_popup description]
	 * @param  [type] $result [description]
	 * @param  [type] $action [description]
	 * @param  [type] $args   [description]
	 * @return [type]         [description]
	 */
	public function plugin_popup( $result, $action, $args ) {

		// If there is a slug
		if( ! empty( $args->slug ) ) {

			// And it's our slug
			if( $args->slug == current( explode( '/' , $this->basename ) ) ) {

				// Get our repo info
				$this->get_repository_info();
				// Set it to an array
				$plugin = array(
					'name'				=> $this->plugin["Name"],
					'slug'				=> $this->basename,
					'version'			=> $this->github_response['tag_name'],
					'author'			=> $this->plugin["AuthorName"],
					'author_profile'	=> $this->plugin["AuthorURI"],
					'last_updated'		=> $this->github_response['published_at'],
					'homepage'			=> $this->plugin["PluginURI"],
					'short_description' => $this->plugin["Description"],
					'sections'			=> array(
						'Description'	=> $this->plugin["Description"],
						'Updates'		=> $this->github_response['body'],
					),
					'download_link'		=> $this->github_response['zipball_url']
				);

				// merge with other settings that can be set
				$plugin = wp_parse_args( $plugin, $this->plugin_settings );

				// Return the data
				return (object) $plugin;
			}
		}

		// Otherwise return default
		return $result;
	}

	/**
	 * [after_install description]
	 * @param  [type] $response   [description]
	 * @param  [type] $hook_extra [description]
	 * @param  [type] $result     [description]
	 * @return [type]             [description]
	 */
	public function after_install( $response, $hook_extra, $result ) {

		// Get global FS object
		global $wp_filesystem;

		// Our plugin directory
		$install_directory = plugin_dir_path( $this->file );

		// Move files to the plugin dir
		$wp_filesystem->move( $result['destination'], $install_directory );

		// Set the destination for the rest of the stack
		$result['destination'] = $install_directory;

		// If it was active
		if ( $this->active ) {

			// Reactivate
			activate_plugin( $this->basename );
		}

		return $result;
	}
}
