<?php
/**
 * Galerie functions.
 *
 * @package Galerie\inc
 *
 * @since 1.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gets the plugin's version.
 *
 * @since 1.0.0
 *
 * @return string The plugin's version.
 */
function galerie_version() {
	return galerie()->version;
}

/**
 * Gets the plugin's db version.
 *
 * @since 1.0.0
 *
 * @return string The plugin's db version.
 */
function galerie_db_version() {
	return get_network_option( 0, '_galerie_version', 0 );
}

/**
 * Gets the plugin's assets folder URL.
 *
 * @since 1.0.0
 *
 * @return string The plugin's assets folder URL.
 */
function galerie_assets_url() {
	return galerie()->assets_url;
}

/**
 * Gets the plugin's assets folder path.
 *
 * @since 1.0.0
 *
 * @return string The assets folder path.
 */
function galerie_assets_dir() {
	return galerie()->assets_dir;
}

/**
 * Gets the plugin's JS folder URL.
 *
 * @since 1.0.0
 *
 * @return The plugin's JS folder URL.
 */
function galerie_js_url() {
	return galerie()->js_url;
}

/**
 * Get the JS/CSS minified suffix.
 *
 * @since  1.0.0
 *
 * @return string the JS/CSS minified suffix.
 */
function galerie_min_suffix() {
	$min = '.min';

	if ( defined( 'SCRIPT_DEBUG' ) && true === SCRIPT_DEBUG )  {
		$min = '';
	}

	/**
	 * Filter here to edit the minified suffix.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $min The minified suffix.
	 */
	return apply_filters( 'galerie_min_suffix', $min );
}

/**
 * Gets the Repositories' dir.
 *
 * @since 1.0.0
 *
 * @return string Path to the repositories dir.
 */
function galerie_plugins_dir() {
	/**
	 * Use this filter to move somewhere else the Repositories dir.
	 *
	 * @since 1.0.0
	 *
	 * @param string $repositories_dir Path to the repositories dir
	 */
	return apply_filters( 'galerie_plugins_dir', galerie()->repositories_dir );
}
/**
 * Loads translation.
 *
 * @since 1.0.0
 */
function galerie_load_textdomain() {
	$galerie = galerie();
	load_plugin_textdomain( $galerie->domain, false, trailingslashit( basename( $galerie->dir ) ) . 'languages' );
}

/**
 * Adds the Galerie cache group.
 *
 * @since 1.0.0
 */
function galerie_setup_cache_group() {
	wp_cache_add_global_groups( 'galerie' );
}

/**
 * Gets all registered repositories or a specific one.
 *
 * @since 1.0.0
 *
 * @param  string $slug An empty string to get all repositories or
 *                      the repository slug to get a specific repository.
 * @return array|object The list of repository objects or a single repository object.
 */
function galerie_get_repositories( $slug = '' ) {
	$repositories = wp_cache_get( 'repositories', 'galerie' );

	if ( ! $repositories ) {
		$json            = file_get_contents( galerie_assets_dir() . 'galerie.min.json' );
		$repositories    = json_decode( $json );

		// Cache repositories
		wp_cache_add( 'repositories', $repositories, 'galerie' );
	}

	if ( $slug ) {
		$single = false;

		foreach ( $repositories as $repository ) {
			if ( ! isset( $repository->releases ) ) {
				continue;
			}

			if ( $slug === galerie_get_repository_slug( $repository->releases ) ) {
				$single = $repository;
				break;
			}
		}

		return $single;
	}

	return $repositories;
}

/**
 * Gets a specific plugin's JSON data.
 *
 * @since 1.0.0
 *
 * @param  string $plugin Name of the plugin.
 * @return string         JSON data.
 */
function galerie_get_repository_json( $plugin = '' ) {
	if ( ! $plugin ) {
		return false;
	}

	// Specific to unit tests
	if ( defined( 'PR_TESTING_ASSETS') && PR_TESTING_ASSETS ) {
		$json = sprintf( '%1$s/%2$s.json', galerie_plugins_dir(), sanitize_file_name( $plugin ) );
		if ( ! file_exists( $json ) ) {
			return false;
		}

		$data = file_get_contents( $json );
		return json_decode( $data );
	}

	return galerie_get_repositories( $plugin );
}

/**
 * Gets the repository's slug of a given path.
 *
 * @since 1.0.0
 *
 * @param  string $path Path to the repository.
 * @return string       The repository's slug.
 */
function galerie_get_repository_slug( $path = '' ) {
	if ( ! $path ) {
		return false;
	}

	return wp_basename( dirname( $path ) );
}

/**
 * Checks with the Github releases of the Repository if there a new stable version available.
 *
 * @since 1.0.0
 *
 * @param  string $atom_url The Repository's feed URL.
 * @param  array  $plugin   The plugin's data.
 * @return object           The stable release data.
 */
function galerie_get_plugin_latest_stable_release( $atom_url = '', $plugin = array() ) {
	$tag_data = new stdClass;
	$tag_data->is_update = false;

	if ( ! $atom_url  ) {
		// For Unit Testing purpose only. Do not use this constant in your code.
		if ( defined( 'PR_TESTING_ASSETS' ) && isset( $plugin['slug'] ) &&  'galerie' === $plugin['slug'] ) {
			$atom_url = trailingslashit( galerie()->dir ) . 'tests/phpunit/assets/releases';
		} else {
			return $tag_data;
		}
	}

	$atom_url = rtrim( $atom_url, '.atom' ) . '.atom';
	$atom = new Galerie_Atom( $atom_url );

	if ( ! isset( $atom->feed ) || ! isset( $atom->feed->entries ) ) {
		return $tag_data;
	}

	foreach ( $atom->feed->entries as $release ) {
		if ( ! isset( $release->id ) ) {
			continue;
		}

		$id     = explode( '/', $release->id );
		$tag    = $id[ count( $id ) - 1 ];
		$stable = str_replace( '.', '', $tag );

		if ( ! is_numeric( $stable ) ) {
			continue;
		}

		$response = array(
			'id'          => $release->id,
			'slug'        => '',
			'plugin'      => '',
			'new_version' => $tag,
			'url'         => '',
			'package'     => '',
		);

		if ( ! empty( $plugin['Version'] ) ) {
			if ( version_compare( $tag, $plugin['Version'], '<=' ) ) {
				continue;
			}

			$response = wp_parse_args( array(
				'id'          => rtrim( str_replace( array( 'https://', 'http://' ), '', $plugin['GitHub Plugin URI'] ), '/' ),
				'slug'        => $plugin['slug'],
				'plugin'      => $plugin['plugin'],
				'url'         => $plugin['GitHub Plugin URI'],
				'package'     => sprintf( '%1$sreleases/download/%2$s/%3$s',
					trailingslashit( $plugin['GitHub Plugin URI'] ),
					$tag,
					sanitize_file_name( $plugin['slug'] . '.zip' )
				),
			), $response );

			if ( ! empty( $release->content ) ) {
				$tag_data->full_upgrade_notice = end( $release->content );
			}

			if ( 'latest' === $plugin['Version'] ) {
				$response['download_link'] = $response['package'];
				$response['version']       = $response['new_version'];
				$response['name']          = $response['slug'];
				$tag_data->is_install = true;
			} else {
				$tag_data->is_update = true;
			}
		}

		foreach ( $response as $k => $v ) {
			$tag_data->{$k} = $v;
		}

		break;
	}

	return $tag_data;
}

/**
 * Adds a new Plugin's header tag to ease repositories identification
 * within the regular plugins.
 *
 * @since 1.0.0
 *
 * @param  array  $headers  The current Plugin's header tag.
 * @return array            The repositories header tag.
 */
function galerie_extra_header( $headers = array() ) {
	if (  ! isset( $headers['GitHub Plugin URI'] ) ) {
		$headers['GitHub Plugin URI'] = 'GitHub Plugin URI';
	}

	return $headers;
}

/**
 * Gets all installed repositories.
 *
 * @since 1.0.0
 *
 * @return array The repositories list.
 */
function galerie_get_installed_repositories() {
	$plugins = get_plugins();

	return array_diff_key( $plugins, wp_list_filter( $plugins, array( 'GitHub Plugin URI' => '' ) ) );
}

/**
 * Manage repositories Upgrades by overriding the update_plugins transient.
 *
 * @since 1.0.0
 *
 * @param  object $option The update_plugins transient value.
 * @return object         The update_plugins transient value.
 */
function galerie_update_repositories( $option = null ) {
	// Only do it when a WordPress.org request happened.
	if ( ! did_action( 'http_api_debug' ) ) {
		return $option;
	}

	$repositories = galerie_get_installed_repositories();

	$repositories_data = array();
	foreach ( $repositories as $kr => $dp ) {
		$repository_name = trim( dirname( $kr ), '/' );
		$json = galerie_get_repository_json( $repository_name );

		if ( ! $json || ! isset( $json->releases ) ) {
			continue;
		}

		$response = galerie_get_plugin_latest_stable_release( $json->releases, array_merge( $dp, array(
			'plugin' => $kr,
			'slug'   => $repository_name,
		) ) );

		$repositories_data[ $kr ] = $response;
	}

	$updated_repositories = wp_list_filter( $repositories_data, array( 'is_update' => true ) );

	if ( ! $updated_repositories ) {
		return $option;
	}

	if ( isset( $option->response ) ) {
		$option->response = array_merge( $option->response, $updated_repositories );
	} else {
		$option->response = $repositories_data;
	}

	// Prevent infinite loops.
	remove_filter( 'set_site_transient_update_plugins', 'galerie_update_repositories' );

	set_site_transient( 'update_plugins', $option );
	return $option;
}

/**
 * Sanitize repositiories headers the way it's done for Plugins.
 *
 * @since 1.0.0
 *
 * @param  string $text The text to sanitize.
 * @return string       The sanitized text.
 */
function galerie_sanitize_repository_text( $text = '' ) {
	return wp_kses( $text, array(
		'a' => array( 'href' => array(),'title' => array(), 'target' => array() ),
		'abbr' => array( 'title' => array() ),'acronym' => array( 'title' => array() ),
		'code' => array(), 'pre' => array(), 'em' => array(),'strong' => array(),
		'ul' => array(), 'ol' => array(), 'li' => array(), 'p' => array(), 'br' => array()
	) );
}

/**
 * Sanitize the repository's content.
 *
 * @since 1.0.0
 *
 * @param  string $content The content to sanitized.
 * @return string          The sanitized content.
 */
function galerie_sanitize_repository_content( $content = '' ) {
	return wp_kses( $content, array_intersect_key( $GLOBALS['allowedposttags'], array(
		'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true,
		'ul' => true, 'ol' => true, 'li' => true, 'table' => true, 'tr' => true, 'td' => true,
		'thead' => true, 'tbody' => true, 'tfoot' => true, 'blockquote' => true, 'a' => true, 'img' => true,
		'pre' => true, 'code' => true, 'p' => true, 'strong' => true, 'bold' => true, 'em' => true, 'i' => true,
	) ) );
}
