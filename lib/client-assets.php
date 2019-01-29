<?php
/**
 * Functions to register client-side assets (scripts and stylesheets) for the
 * Gutenberg editor plugin.
 *
 * @package gutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Silence is golden.' );
}

/**
 * Retrieves the root plugin path.
 *
 * @return string Root path to the gutenberg plugin.
 *
 * @since 0.1.0
 */
function gutenberg_dir_path() {
	return plugin_dir_path( dirname( __FILE__ ) );
}

/**
 * Retrieves a URL to a file in the gutenberg plugin.
 *
 * @param  string $path Relative path of the desired file.
 *
 * @return string       Fully qualified URL pointing to the desired file.
 *
 * @since 0.1.0
 */
function gutenberg_url( $path ) {
	return plugins_url( $path, dirname( __FILE__ ) );
}

/**
 * Returns contents of an inline script used in appending polyfill scripts for
 * browsers which fail the provided tests. The provided array is a mapping from
 * a condition to verify feature support to its polyfill script handle.
 *
 * @param array $tests Features to detect.
 * @return string Conditional polyfill inline script.
 */
function gutenberg_get_script_polyfill( $tests ) {
	_deprecated_function( __FUNCTION__, '5.0.0', 'wp_get_script_polyfill' );

	global $wp_scripts;
	return wp_get_script_polyfill( $wp_scripts, $tests );
}

if ( ! function_exists( 'register_tinymce_scripts' ) ) {
	/**
	 * Registers the main TinyMCE scripts.
	 *
	 * @deprecated 5.0.0 wp_register_tinymce_scripts
	 */
	function register_tinymce_scripts() {
		_deprecated_function( __FUNCTION__, '5.0.0', 'wp_register_tinymce_scripts' );

		global $wp_scripts;
		return wp_register_tinymce_scripts( $wp_scripts );
	}
}

/**
 * Registers a script according to `wp_register_script`. Honors this request by
 * deregistering any script by the same handler before registration.
 *
 * @since 4.1.0
 *
 * @param string           $handle    Name of the script. Should be unique.
 * @param string           $src       Full URL of the script, or path of the script relative to the WordPress root directory.
 * @param array            $deps      Optional. An array of registered script handles this script depends on. Default empty array.
 * @param string|bool|null $ver       Optional. String specifying script version number, if it has one, which is added to the URL
 *                                    as a query string for cache busting purposes. If version is set to false, a version
 *                                    number is automatically added equal to current installed WordPress version.
 *                                    If set to null, no version is added.
 * @param bool             $in_footer Optional. Whether to enqueue the script before </body> instead of in the <head>.
 *                                    Default 'false'.
 */
function gutenberg_override_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
	global $wp_scripts;

	$script = $wp_scripts->query( $handle, 'registered' );
	if ( $script ) {
		$script->src  = $src;
		$script->deps = $deps;
		$script->ver  = $ver;
		unset( $script->extra['group'] );
		if ( $in_footer ) {
			$script->add_data( 'group', 1 );
		}
	} else {
		wp_register_script( $handle, $src, $deps, $ver, $in_footer );
	}
}

/**
 * Registers a style according to `wp_register_style`. Honors this request by
 * deregistering any style by the same handler before registration.
 *
 * @since 4.1.0
 *
 * @param string           $handle Name of the stylesheet. Should be unique.
 * @param string           $src    Full URL of the stylesheet, or path of the stylesheet relative to the WordPress root directory.
 * @param array            $deps   Optional. An array of registered stylesheet handles this stylesheet depends on. Default empty array.
 * @param string|bool|null $ver    Optional. String specifying stylesheet version number, if it has one, which is added to the URL
 *                                 as a query string for cache busting purposes. If version is set to false, a version
 *                                 number is automatically added equal to current installed WordPress version.
 *                                 If set to null, no version is added.
 * @param string           $media  Optional. The media for which this stylesheet has been defined.
 *                                 Default 'all'. Accepts media types like 'all', 'print' and 'screen', or media queries like
 *                                 '(orientation: portrait)' and '(max-width: 640px)'.
 */
function gutenberg_override_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
	wp_deregister_style( $handle );
	wp_register_style( $handle, $src, $deps, $ver, $media );
}

/**
 * Registers all the WordPress packages scripts that are in the standardized
 * `build/` location.
 *
 * @since 4.5.0
 */
function gutenberg_register_packages_scripts() {
	$packages_dependencies = include dirname( __FILE__ ) . '/packages-dependencies.php';

	foreach ( $packages_dependencies as $handle => $dependencies ) {
		// Remove `wp-` prefix from the handle to get the package's name.
		$package_name = strpos( $handle, 'wp-' ) === 0 ? substr( $handle, 3 ) : $handle;
		$path         = "build/$package_name/index.js";
		gutenberg_override_script(
			$handle,
			gutenberg_url( $path ),
			array_merge( $dependencies, array( 'wp-polyfill' ) ),
			filemtime( gutenberg_dir_path() . $path ),
			true
		);
	}
}

/**
 * Registers common scripts and styles to be used as dependencies of the editor
 * and plugins.
 *
 * @since 0.1.0
 */
function gutenberg_register_scripts_and_styles() {
	global $wp_scripts;

	gutenberg_register_vendor_scripts();
	gutenberg_register_packages_scripts();

	// Transforms heartbeat jQuery events into equivalent hook actions. This
	// avoids a dependency on jQuery for listening to the event. This should be
	// removed as soon as it's provided by WordPress in a version satisfied by
	// the minimum version of the Gutenberg plugin.
	//
	// See: [TRAC TICKET] .
	$heartbeat_script = $wp_scripts->query( 'heartbeat', 'registered' );
	if ( $heartbeat_script && ! in_array( 'wp-hooks', $heartbeat_script->deps ) ) {
		$heartbeat_script->deps[] = 'wp-hooks';
	}

	$heartbeat_hooks = <<<JS
( function() {
	jQuery( document ).on( [
		'heartbeat-send',
		'heartbeat-tick',
		'heartbeat-error',
		'heartbeat-connection-lost',
		'heartbeat-connection-restored',
		'heartbeat-nonces-expired',
	].join( ' ' ), function( event ) {
		var actionName = event.type.replace( /-/g, '.' ),
			args;

		// Omit the event argument in applying arguments to the hook callback.
		// The remaining arguments are passed to the hook.
		args = Array.prototype.slice.call( arguments, 1 );

		wp.hooks.doAction.apply( null, [ actionName ].concat( args ) );
	} );
} )();
JS;
	wp_add_inline_script( 'heartbeat', $heartbeat_hooks );

	// Temporary compatibility to provide `_wpLoadGutenbergEditor` as an alias
	// of `_wpLoadBlockEditor`.
	//
	// TODO: Hello future maintainer. In removing this deprecation, ensure also
	// to check whether `wp-editor` dependencies in `package-dependencies.php`
	// still require `wp-deprecated`.
	$gutenberg_load_compat = <<<JS
Object.defineProperty( window, '_wpLoadGutenbergEditor', {
	get: function() {
		wp.deprecated( '`window._wpLoadGutenbergEditor`', {
			plugin: 'Gutenberg',
			version: '5.2',
			alternative: '`window._wpLoadBlockEditor`',
			hint: 'This is a private API, not intended for public use. It may be removed in the future.'
		} );
		return window._wpLoadBlockEditor;
	}
} );
JS;
	wp_add_inline_script( 'wp-editor', $gutenberg_load_compat );

	// Editor Styles.
	// This empty stylesheet is defined to ensure backward compatibility.
	gutenberg_override_style( 'wp-blocks', false );

	gutenberg_override_style(
		'wp-editor',
		gutenberg_url( 'build/editor/style.css' ),
		array( 'wp-components', 'wp-editor-font', 'wp-nux' ),
		filemtime( gutenberg_dir_path() . 'build/editor/style.css' )
	);
	wp_style_add_data( 'wp-editor', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-edit-post',
		gutenberg_url( 'build/edit-post/style.css' ),
		array( 'wp-components', 'wp-editor', 'wp-edit-blocks', 'wp-block-library', 'wp-nux' ),
		filemtime( gutenberg_dir_path() . 'build/edit-post/style.css' )
	);
	wp_style_add_data( 'wp-edit-post', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-components',
		gutenberg_url( 'build/components/style.css' ),
		array(),
		filemtime( gutenberg_dir_path() . 'build/components/style.css' )
	);
	wp_style_add_data( 'wp-components', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-block-library',
		gutenberg_url( 'build/block-library/style.css' ),
		current_theme_supports( 'wp-block-styles' ) ? array( 'wp-block-library-theme' ) : array(),
		filemtime( gutenberg_dir_path() . 'build/block-library/style.css' )
	);
	wp_style_add_data( 'wp-block-library', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-format-library',
		gutenberg_url( 'build/format-library/style.css' ),
		array(),
		filemtime( gutenberg_dir_path() . 'build/format-library/style.css' )
	);
	wp_style_add_data( 'wp-format-library', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-edit-blocks',
		gutenberg_url( 'build/block-library/editor.css' ),
		array(
			'wp-components',
			'wp-editor',
			'wp-block-library',
			// Always include visual styles so the editor never appears broken.
			'wp-block-library-theme',
		),
		filemtime( gutenberg_dir_path() . 'build/block-library/editor.css' )
	);
	wp_style_add_data( 'wp-edit-blocks', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-nux',
		gutenberg_url( 'build/nux/style.css' ),
		array( 'wp-components' ),
		filemtime( gutenberg_dir_path() . 'build/nux/style.css' )
	);
	wp_style_add_data( 'wp-nux', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-block-library-theme',
		gutenberg_url( 'build/block-library/theme.css' ),
		array(),
		filemtime( gutenberg_dir_path() . 'build/block-library/theme.css' )
	);
	wp_style_add_data( 'wp-block-library-theme', 'rtl', 'replace' );

	gutenberg_override_style(
		'wp-list-reusable-blocks',
		gutenberg_url( 'build/list-reusable-blocks/style.css' ),
		array( 'wp-components' ),
		filemtime( gutenberg_dir_path() . 'build/list-reusable-blocks/style.css' )
	);
	wp_style_add_data( 'wp-list-reusable-block', 'rtl', 'replace' );

	if ( defined( 'GUTENBERG_LIVE_RELOAD' ) && GUTENBERG_LIVE_RELOAD ) {
		$live_reload_url = ( GUTENBERG_LIVE_RELOAD === true ) ? 'http://localhost:35729/livereload.js' : GUTENBERG_LIVE_RELOAD;

		wp_enqueue_script(
			'gutenberg-live-reload',
			$live_reload_url
		);
	}
}
add_action( 'wp_enqueue_scripts', 'gutenberg_register_scripts_and_styles', 5 );
add_action( 'admin_enqueue_scripts', 'gutenberg_register_scripts_and_styles', 5 );

/**
 * Append result of internal request to REST API for purpose of preloading
 * data to be attached to the page. Expected to be called in the context of
 * `array_reduce`.
 *
 * @deprecated 5.0.0 rest_preload_api_request
 *
 * @param  array  $memo Reduce accumulator.
 * @param  string $path REST API path to preload.
 * @return array        Modified reduce accumulator.
 */
function gutenberg_preload_api_request( $memo, $path ) {
	_deprecated_function( __FUNCTION__, '5.0.0', 'rest_preload_api_request' );

	return rest_preload_api_request( $memo, $path );
}

/**
 * Registers vendor JavaScript files to be used as dependencies of the editor
 * and plugins.
 *
 * This function is called from a script during the plugin build process, so it
 * should not call any WordPress PHP functions.
 *
 * @since 0.1.0
 */
function gutenberg_register_vendor_scripts() {
	$suffix = SCRIPT_DEBUG ? '' : '.min';

	// Vendor Scripts.
	$react_suffix = ( SCRIPT_DEBUG ? '.development' : '.production' ) . $suffix;

	gutenberg_register_vendor_script(
		'react',
		'https://unpkg.com/react@16.6.3/umd/react' . $react_suffix . '.js',
		array( 'wp-polyfill' )
	);
	gutenberg_register_vendor_script(
		'react-dom',
		'https://unpkg.com/react-dom@16.6.3/umd/react-dom' . $react_suffix . '.js',
		array( 'react' )
	);
	$moment_script = SCRIPT_DEBUG ? 'moment.js' : 'min/moment.min.js';
	gutenberg_register_vendor_script(
		'moment',
		'https://unpkg.com/moment@2.22.1/' . $moment_script,
		array()
	);
	gutenberg_register_vendor_script(
		'lodash',
		'https://unpkg.com/lodash@4.17.5/lodash' . $suffix . '.js'
	);
	gutenberg_register_vendor_script(
		'wp-polyfill-fetch',
		'https://unpkg.com/whatwg-fetch@3.0.0/dist/fetch.umd.js'
	);
	gutenberg_register_vendor_script(
		'wp-polyfill-formdata',
		'https://unpkg.com/formdata-polyfill@3.0.9/formdata.min.js'
	);
	gutenberg_register_vendor_script(
		'wp-polyfill-node-contains',
		'https://unpkg.com/polyfill-library@3.26.0-0/polyfills/Node/prototype/contains/polyfill.js'
	);
	gutenberg_register_vendor_script(
		'wp-polyfill-element-closest',
		'https://unpkg.com/element-closest@2.0.2/element-closest.js'
	);
	gutenberg_register_vendor_script(
		'wp-polyfill',
		'https://cdnjs.cloudflare.com/ajax/libs/babel-polyfill/7.0.0/polyfill' . $suffix . '.js'
	);
}

/**
 * Retrieves a unique and reasonably short and human-friendly filename for a
 * vendor script based on a URL and the script handle.
 *
 * @param  string $handle The name of the script.
 * @param  string $src    Full URL of the external script.
 *
 * @return string         Script filename suitable for local caching.
 *
 * @since 0.1.0
 */
function gutenberg_vendor_script_filename( $handle, $src ) {
	$filename = basename( $src );
	$match    = preg_match(
		'/^'
		. '(?P<ignore>.*?)'
		. '(?P<suffix>\.min)?'
		. '(?P<extension>\.js)'
		. '(?P<extra>.*)'
		. '$/',
		$filename,
		$filename_pieces
	);

	$prefix = $handle;
	$suffix = $match ? $filename_pieces['suffix'] : '';
	$hash   = substr( md5( $src ), 0, 8 );

	return "${prefix}${suffix}.${hash}.js";
}

/**
 * Registers a vendor script from a URL, preferring a locally cached version if
 * possible, or downloading it if the cached version is unavailable or
 * outdated.
 *
 * @param  string $handle Name of the script.
 * @param  string $src    Full URL of the external script.
 * @param  array  $deps   Optional. An array of registered script handles this
 *                        script depends on.
 *
 * @since 0.1.0
 */
function gutenberg_register_vendor_script( $handle, $src, $deps = array() ) {
	if ( defined( 'GUTENBERG_LOAD_VENDOR_SCRIPTS' ) && ! GUTENBERG_LOAD_VENDOR_SCRIPTS ) {
		return;
	}

	$filename = gutenberg_vendor_script_filename( $handle, $src );

	if ( defined( 'GUTENBERG_LIST_VENDOR_ASSETS' ) && GUTENBERG_LIST_VENDOR_ASSETS ) {
		echo "$src|$filename\n";
		return;
	}

	$full_path = gutenberg_dir_path() . 'vendor/' . $filename;

	$needs_fetch = (
		defined( 'GUTENBERG_DEVELOPMENT_MODE' ) && GUTENBERG_DEVELOPMENT_MODE && (
			! file_exists( $full_path ) ||
			time() - filemtime( $full_path ) >= DAY_IN_SECONDS
		)
	);

	if ( $needs_fetch ) {
		// Determine whether we can write to this file.  If not, don't waste
		// time doing a network request.
		// @codingStandardsIgnoreStart
		$f = @fopen( $full_path, 'a' );
		// @codingStandardsIgnoreEnd
		if ( ! $f ) {
			// Failed to open the file for writing, probably due to server
			// permissions.  Enqueue the script directly from the URL instead.
			gutenberg_override_script( $handle, $src, $deps, null );
			return;
		}
		fclose( $f );
		$response = wp_remote_get( $src );
		if ( wp_remote_retrieve_response_code( $response ) === 200 ) {
			$f = fopen( $full_path, 'w' );
			fwrite( $f, wp_remote_retrieve_body( $response ) );
			fclose( $f );
		} elseif ( ! filesize( $full_path ) ) {
			// The request failed. If the file is already cached, continue to
			// use this file. If not, then unlink the 0 byte file, and enqueue
			// the script directly from the URL.
			gutenberg_override_script( $handle, $src, $deps, null );
			unlink( $full_path );
			return;
		}
	}
	gutenberg_override_script(
		$handle,
		gutenberg_url( 'vendor/' . $filename ),
		$deps,
		null
	);
}

/**
 * Prepares server-registered blocks for JavaScript, returning an associative
 * array of registered block data keyed by block name. Data includes properties
 * of a block relevant for client registration.
 *
 * @deprecated 5.0.0 get_block_editor_server_block_settings
 *
 * @return array An associative array of registered block data.
 */
function gutenberg_prepare_blocks_for_js() {
	_deprecated_function( __FUNCTION__, '5.0.0', 'get_block_editor_server_block_settings' );

	return get_block_editor_server_block_settings();
}

/**
 * Handles the enqueueing of block scripts and styles that are common to both
 * the editor and the front-end.
 *
 * Note: This function must remain *before*
 * `gutenberg_editor_scripts_and_styles` so that editor-specific stylesheets
 * are loaded last.
 *
 * @since 0.4.0
 * @deprecated 5.0.0 wp_common_block_scripts_and_styles
 */
function gutenberg_common_scripts_and_styles() {
	_deprecated_function( __FUNCTION__, '5.0.0', 'wp_common_block_scripts_and_styles' );

	wp_common_block_scripts_and_styles();
}

/**
 * Enqueues registered block scripts and styles, depending on current rendered
 * context (only enqueuing editor scripts while in context of the editor).
 *
 * @since 2.0.0
 * @deprecated 5.0.0 wp_enqueue_registered_block_scripts_and_styles
 */
function gutenberg_enqueue_registered_block_scripts_and_styles() {
	_deprecated_function( __FUNCTION__, '5.0.0', 'wp_enqueue_registered_block_scripts_and_styles' );

	wp_enqueue_registered_block_scripts_and_styles();
}

/**
 * Assigns a default editor template with a default block by post format, if
 * not otherwise assigned for a new post of type "post".
 *
 * @deprecated 5.0.0
 *
 * @param array $settings Default editor settings.
 *
 * @return array Filtered block editor settings.
 */
function gutenberg_default_post_format_template( $settings ) {
	_deprecated_function( __FUNCTION__, '5.0.0' );

	return $settings;
}

/**
 * Retrieve a stored autosave that is newer than the post save.
 *
 * Deletes autosaves that are older than the post save.
 *
 * @deprecated 5.0.0
 *
 * @return WP_Post|boolean The post autosave. False if none found.
 */
function gutenberg_get_autosave_newer_than_post_save() {
	_deprecated_function( __FUNCTION__, '5.0.0' );

	return false;
}

/**
 * Returns all the block categories.
 *
 * @since 2.2.0
 * @deprecated 5.0.0 get_block_categories
 *
 * @param  WP_Post $post Post object.
 * @return Object[] Block categories.
 */
function gutenberg_get_block_categories( $post ) {
	_deprecated_function( __FUNCTION__, '5.0.0', 'get_block_categories' );

	return get_block_categories( $post );
}

/**
 * Loads Gutenberg Locale Data.
 */
function gutenberg_load_locale_data() {
	// Prepare Jed locale data.
	$locale_data = gutenberg_get_jed_locale_data( 'gutenberg' );
	wp_add_inline_script(
		'wp-i18n',
		'wp.i18n.setLocaleData( ' . json_encode( $locale_data ) . ' );'
	);
}

/**
 * Retrieve The available image sizes for a post
 *
 * @deprecated 5.0.0
 *
 * @return array
 */
function gutenberg_get_available_image_sizes() {
	_deprecated_function( __FUNCTION__, '5.0.0' );

	return array();
}

/**
 * Extends block editor settings to account for the legacy `gutenberg` theme
 * support, currently on track for deprecation. This function is temporary, and
 * will be removed at a point at which the theme support has been removed.
 *
 * @param array $settings Default editor settings.
 *
 * @return array Filtered editor settings.
 */
function _gutenberg_extend_legacy_align_wide_editor_setting( $settings ) {
	$gutenberg_theme_support = get_theme_support( 'gutenberg' );
	if ( empty( $gutenberg_theme_support ) ) {
		return $settings;
	}

	wp_enqueue_script( 'wp-deprecated' );
	wp_add_inline_script( 'wp-deprecated', 'wp.deprecated( "`gutenberg` theme support", { plugin: "Gutenberg", version: "5.2", alternative: "`align-wide` theme support" } );' );

	$settings['alignWide'] = (
		! empty( $settings['alignWide'] ) ||
		! empty( $gutenberg_theme_support[0]['wide-images'] )
	);

	return $settings;
}
add_filter( 'block_editor_settings', '_gutenberg_extend_legacy_align_wide_editor_setting' );

/**
 * Scripts & Styles.
 *
 * Enqueues the needed scripts and styles when visiting the top-level page of
 * the Gutenberg editor.
 *
 * @since 0.1.0
 *
 * @param string $hook Screen name.
 */
function gutenberg_editor_scripts_and_styles( $hook ) {
	// Ignore Classic Editor's `rich_editing` user option, aka "Disable visual
	// editor". Forcing this to be true guarantees that TinyMCE and its plugins
	// are available in Gutenberg. Fixes
	// https://github.com/WordPress/gutenberg/issues/5667.
	$user_can_richedit = user_can_richedit();
	add_filter( 'user_can_richedit', '__return_true' );

	gutenberg_load_locale_data();

	// Editor Styles.
	$styles = array(
		array(
			'css' => file_get_contents(
				gutenberg_dir_path() . 'build/editor/editor-styles.css'
			),
		),
	);

	$editor_settings = array(
		'styles'             => $styles,
		'richEditingEnabled' => $user_can_richedit,
	);
}

/**
 * Enqueue the reusable blocks listing page's script
 *
 * @deprecated 5.0.0
 */
function gutenberg_load_list_reusable_blocks() {
	_deprecated_function( __FUNCTION__, '5.0.0' );
}
