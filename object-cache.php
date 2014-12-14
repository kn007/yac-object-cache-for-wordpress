<?php

/*
  +----------------------------------------------------------------------+
  | Author:  kn007 <kn007 at 126.com>  http://kn007.net                  |
  +----------------------------------------------------------------------+
*/

!defined( 'ABSPATH' ) and exit;

if ( version_compare( '5.2.4', phpversion(), '>=' ) ) 
	wp_die( 'The Yac object cache backend requires PHP 5.2 or higher. You are running ' . phpversion() . '. Please remove the <code>object-cache.php</code> file from your content directory.' );

//!class_exists( 'Yac' ) and exit;
if ( !extension_loaded( 'yac' ) ) {
	wp_using_ext_object_cache( false );
	die( '<strong>ERROR:</strong> Could not find Yac extension for this project.' );
}

if ( function_exists( 'wp_cache_add' ) ) {
	die( '<strong>ERROR:</strong> This is <em>not</em> a plugin, and it should not be activated as one.<br /><br />Instead, <code>' . str_replace( $_SERVER['DOCUMENT_ROOT'], '', __FILE__ ) . '</code> must be moved to <code>' . str_replace( $_SERVER['DOCUMENT_ROOT'], '', trailingslashit( WP_CONTENT_DIR ) ) . 'object-cache.php</code>' );
} else {
	if ( !defined( 'WP_CACHE_KEY_SALT' ) )
		define( 'WP_CACHE_KEY_SALT', 'wp_' );
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_get( $key, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group );
}

/* Todo
function wp_cache_get_multi( $keys, $groups = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multi( $keys, $groups );
}
*/

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	if ( defined( 'WP_INSTALLING' ) == false )
		return $wp_object_cache->set( $key, $data, $group, $expire );
	else
		return $wp_object_cache->delete( $key, $group );
}

/* Todo
function wp_cache_set_multi( $keys, $groups = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multi( $keys, $groups, $expire );
}
*/

function wp_cache_delete( $key, $group = '', $delay = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group, $delay );
}

/* Todo
function wp_cache_delete_multi( $keys, $groups = '', $delay = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multi( $keys, $group, $delay);
}
*/

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group, $expire );
}

function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

function wp_cache_init() {
	global $wp_object_cache;
	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_status() {
	global $wp_object_cache;
	return $wp_object_cache->status();
}

function wp_cache_global_status() {
	global $wp_object_cache;
	return $wp_object_cache->global_status();
}

function wp_cache_close() {
	return true;
}

function wp_cache_reset() {
	_deprecated_function( __FUNCTION__, '3.5', 'wp_cache_switch_to_blog()' );
	return false;
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	return $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

class WP_Object_Cache {
	private $yac;
	public $global_groups = array();
	public $no_yac_groups = array();
	protected $cache = array();
	public $global_prefix = '';
	public $blog_prefix = '';
	public $default_expire = 0;
	private $multi_site = false;

	public function __construct() {
		global $blog_id;
		$this->yac = new Yac();
		$this->multi_site    = is_multisite();
		$this->blog_prefix   = $this->multi_site ? (int) $blog_id : 1;
	}

	public function build_key( $key, $group = 'default' ) {
		if ( empty( $group ) ) 
			$group = 'default';

		if ( false !== array_search( $group, $this->global_groups ) ) {
			$prefix = $this->global_prefix;
		} else {
			$prefix = $this->blog_prefix;
		}

		return md5(preg_replace( '/\s+/', '', WP_CACHE_KEY_SALT . "$prefix$group:$key" ));
	}

	public function add_to_internal_cache( $derived_key, $value ) {
		if ( is_object( $value ) ) 
			$value = clone $value;

		$this->cache[ $derived_key ] = $value;
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( wp_suspend_cache_addition() ) 
			return false;

		$key = $this->build_key( $key, $group );

		if ( in_array( $group, $this->no_yac_groups ) ) {
			if ( isset( $this->cache[$key] ) )
				return false;

			$this->add_to_internal_cache( $key, $value );

			return true;
		}

		$val = $this->get($key, $group);

		if ($val) 
			return false;

		return $this->yac->set( $key, $data, $group, $expire );
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$val = $this->get($key, $group);

		if ($val) {
			if (!is_numeric($val)) {
				$val = $offset;
			} else {
				$val += $offset;
			}

			if ($val < 0) {
				$val = 0;
			}

			$this->set($key, $val, $group);
			return $val;
		}

		return false;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		$val = $this->get($key, $group);

		if ($val) {
			if (!is_numeric($val)) {
				$val = 0;
			} else {
				$val -= $offset;
			}

			if ($val < 0) {
				$val = 0;
			}

			$this->set($key, $val, $group);
			return $val;
		}

		return false;
	}

	public function get( $key, $group = 'default' ) {
		$key = $this->build_key( $key, $group );

        return $this->yac->get( $key );
	}

	public function get_multi( $keys, $groups = 'default' ) {
		return false;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$key = $this->build_key( $key, $group );
		$expire = $expire > 0 ? intval($expire) : 0;

        return $this->yac->set( $key, $value, $ttl );
	}

	public function set_multi( $keys, $groups = 'default', $expire = 0 ) {
		return false;
	}

	public function delete( $key, $group = 'default', $delay = 0 ) {
		$key = $this->build_key( $key, $group );
		$delay = $delay > 0 ? intval($delay) : 0;

        return $this->yac->delete( $key, $delay );
	}

	public function delete_multi( $keys, $groups = 'default', $delay = 0 ) {
		return false;
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		return $this->set( $key, $data, $group, $expire );
	}

	public function flush() {
		return $this->yac->flush();
	}

	public function status() {
		return $this->global_status();
	}

	public function global_status() {
		return $this->yac->info();
	}

	public function switch_to_blog( $blog_id ) {
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = $this->multi_site ? $blog_id : 1;
	}

	public function add_global_groups( $groups ) {
		if ( !is_array( $groups ) )
			$groups = (array) $groups;

		$this->global_groups = array_merge( $this->global_groups, $groups );
		$this->global_groups = array_unique( $this->global_groups );
	}

	public function add_non_persistent_groups( $groups ) {
		if ( !is_array( $groups ) )
			$groups = (array) $groups;

		$this->no_yac_groups = array_merge( $this->no_yac_groups, $groups );
		$this->no_yac_groups = array_unique( $this->no_yac_groups );		
	}
}