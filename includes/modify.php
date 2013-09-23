<?php

class CapsmanHandler
{
	var $cm;

	function __construct( $manager_obj, $post ) {
		global $wp_roles;
		
		// Create a new role.
		if ( ! empty($post['CreateRole']) ) {
			if ( $newrole = $this->createRole($post['create-name']) ) {
				ak_admin_notify(__('New role created.', $this->ID));
				$this->current = $newrole;
			} else {
				if ( empty($post['create-name']) && ( ! defined('WPLANG') || ! WPLANG ) )
					ak_admin_error( 'Error: No role name specified.', $this->ID );
				else
					ak_admin_error(__('Error: Failed creating the new role.', $this->ID));
			}

		// Copy current role to a new one.
		} elseif ( ! empty($post['CopyRole']) ) {
			$current = get_role($post['current']);
			if ( $newrole = $this->createRole($post['copy-name'], $current->capabilities) ) {
				ak_admin_notify(__('New role created.', $this->ID));
				$this->current = $newrole;
			} else {
				if ( empty($post['copy-name']) && ( ! defined('WPLANG') || ! WPLANG ) )
					ak_admin_error( 'Error: No role name specified.', $this->ID );
				else
					ak_admin_error(__('Error: Failed creating the new role.', $this->ID));
			}

		// Save role changes. Already saved at start with self::saveRoleCapabilities().
		} elseif ( ! empty($post['SaveRole']) ) {
			if ( MULTISITE ) {
				global $wp_roles;
				if ( method_exists( $wp_roles, 'reinit' ) )
					$wp_roles->reinit();
			}
			
			$this->saveRoleCapabilities($post['current'], $post['caps'], $post['level']);
			
			if ( defined( 'PP_ACTIVE' ) ) {  // log customized role caps for subsequent restoration
				// for bbPress < 2.2, need to log customization of roles following bbPress activation
				$plugins = ( function_exists( 'bbp_get_version' ) && version_compare( bbp_get_version(), '2.2', '<' ) ) ? array( 'bbpress.php' ) : array();	// back compat

				if ( ! $customized_roles = get_option( 'pp_customized_roles' ) )
					$customized_roles = array();
				
				$customized_roles[$post['role']] = (object) array( 'caps' => $post['caps'], 'plugins' => $plugins );
				update_option( 'pp_customized_roles', $customized_roles );
				
				global $wpdb;
				$wpdb->query( "UPDATE $wpdb->options SET autoload = 'no' WHERE option_name = 'pp_customized_roles'" );
			}
		// Create New Capability and adds it to current role.
		} elseif ( ! empty($post['AddCap']) ) {
			if ( MULTISITE ) {
				global $wp_roles;
				if ( method_exists( $wp_roles, 'reinit' ) )
					$wp_roles->reinit();
			}
			
			$role = get_role($post['current']);
			$role->name = $post['current'];		// bbPress workaround

			if ( $newname = $this->createNewName($post['capability-name']) ) {
				$role->add_cap($newname['name']);
				$this->message = __('New capability added to role.');

				// for bbPress < 2.2, need to log customization of roles following bbPress activation
				$plugins = ( function_exists( 'bbp_get_version' ) && version_compare( bbp_get_version(), '2.2', '<' ) ) ? array( 'bbpress.php' ) : array();	// back compat
				
				if ( ! $customized_roles = get_option( 'pp_customized_roles' ) )
					$customized_roles = array();

				$customized_roles[$post['role']] = (object) array( 'caps' => array_merge( $role->capabilities, array( $newname['name'] => 1 ) ), 'plugins' => $plugins );
				update_option( 'pp_customized_roles', $customized_roles );
				
				global $wpdb;
				$wpdb->query( "UPDATE $wpdb->options SET autoload = 'no' WHERE option_name = 'pp_customized_roles'" );
			} else {
				$this->message = __('Incorrect capability name.');
			}
			
		} elseif ( ! empty($post['update_filtered_types']) ) {
			if ( cme_update_pp_usage() ) {
				ak_admin_notify(__('Capability settings saved.', $this->ID));
			} else {
				ak_admin_error(__('Error saving capability settings.', $this->ID));
			}
		} else {
		    // TODO: Implement exceptions. This must be a fatal error.
		    ak_admin_error(__('Bad form received.', $this->ID));
		}

		if ( ! empty($newrole) && defined('PP_ACTIVE') ) {
			if ( ( ! empty($post['CreateRole']) && ! empty( $_REQUEST['new_role_pp_only'] ) ) || ( ! empty($post['CopyRole']) && ! empty( $_REQUEST['copy_role_pp_only'] ) ) ) {
				$pp_only = (array) pp_get_option( 'supplemental_role_defs' );
				$pp_only[]= $newrole;
				pp_update_option( 'supplemental_role_defs', $pp_only );
				_cme_pp_default_pattern_role( $newrole );
				pp_refresh_options();
			}
		}
	}

	
	/**
	 * Creates a new role/capability name from user input name.
	 * Name rules are:
	 * 		- 2-40 charachers lenght.
	 * 		- Only letters, digits, spaces and underscores.
	 * 		- Must to start with a letter.
	 *
	 * @param string $name	Name from user input.
	 * @return array|false An array with the name and display_name, or false if not valid $name.
	 */
	private function createNewName( $name ) {
		// Allow max 40 characters, letters, digits and spaces
		$name = trim(substr($name, 0, 40));
		$pattern = '/^[a-zA-Z][a-zA-Z0-9 _]+$/';

		if ( preg_match($pattern, $name) ) {
			$roles = ak_get_roles();

			$name = strtolower($name);
			$name = str_replace(' ', '_', $name);
			if ( in_array($name, $roles) || array_key_exists($name, $this->capabilities) ) {
				return false;	// Already a role or capability with this name.
			}

			$display = explode('_', $name);
			$display = array_map('ucfirst', $display);
			$display = implode(' ', $display);

			return compact('name', 'display');
		} else {
			return false;
		}
	}

	/**
	 * Creates a new role.
	 *
	 * @param string $name	Role name to create.
	 * @param array $caps	Role capabilities.
	 * @return string|false	Returns the name of the new role created or false if failed.
	 */
	private function createRole( $name, $caps = array() ) {
		if ( ! is_array($caps) )
			$caps = array();

		$role = $this->createNewName($name);
		if ( ! is_array($role) ) {
			return false;
		}

		$new_role = add_role($role['name'], $role['display'], $caps);
		if ( is_object($new_role) ) {
			return $role['name'];
		} else {
			return false;
		}
	}

	 /**
	  * Saves capability changes to roles.
	  *
	  * @param string $role_name Role name to change its capabilities
	  * @param array $caps New capabilities for the role.
	  * @return void
	  */
	private function saveRoleCapabilities( $role_name, $caps, $level ) {
		$this->generateNames();
		$role = get_role($role_name);
		
		// workaround to ensure db storage of customizations to bbp dynamic roles
		$role->name = $role_name;
		
		$stored_role_caps = ( ! empty($role->capabilities) && is_array($role->capabilities) ) ? array_intersect( $role->capabilities, array(true, 1) ) : array();
		
		$old_caps = array_intersect_key( $stored_role_caps, $this->capabilities);
		$new_caps = ( is_array($caps) ) ? array_map('intval', $caps) : array();
		$new_caps = array_merge($new_caps, ak_level2caps($level));

		// Find caps to add and remove
		$add_caps = array_diff_key($new_caps, $old_caps);
		$del_caps = array_diff_key($old_caps, $new_caps);

		if ( ! $is_administrator = current_user_can('administrator') ) {
			unset($add_caps['manage_capabilities']);
			unset($del_caps['manage_capabilities']);
		}

		if ( 'administrator' == $role_name && isset($del_caps['manage_capabilities']) ) {
			unset($del_caps['manage_capabilities']);
			ak_admin_error(__('You cannot remove Manage Capabilities from Administrators', $this->ID));
		}
		// Add new capabilities to role
		foreach ( $add_caps as $cap => $grant ) {
			if ( $is_administrator || current_user_can($cap) )
				$role->add_cap($cap);
		}

		// Remove capabilities from role
		foreach ( $del_caps as $cap => $grant) {
			if ( $is_administrator || current_user_can($cap) )
				$role->remove_cap($cap);
		}
	}
	


	/**
	 * Deletes a role.
	 * The role comes from the $_GET['role'] var and the nonce has already been checked.
	 * Default WordPress role cannot be deleted and if trying to do it, throws an error.
	 * Users with the deleted role, are moved to the WordPress default role.
	 *
	 * @return void
	 */
	private function adminDeleteRole ()
	{
		global $wpdb;

		$this->current = $_GET['role'];
		$default = get_option('default_role');
		if (  $default == $this->current ) {
			ak_admin_error(sprintf(__('Cannot delete default role. You <a href="%s">have to change it first</a>.', $this->ID), 'options-general.php'));
			return;
		}

		$query = "SELECT ID FROM {$wpdb->usermeta} INNER JOIN {$wpdb->users} "
			. "ON {$wpdb->usermeta}.user_id = {$wpdb->users}.ID "
			. "WHERE meta_key='{$wpdb->prefix}capabilities' AND meta_value LIKE '%{$this->current}%';";

		$users = $wpdb->get_results($query);
		$count = count($users);

		foreach ( $users as $u ) {
			$user = new WP_User($u->ID);
			if ( $user->has_cap($this->current) ) {		// Check again the user has the deleting role
				$user->set_role($default);
			}
		}

		remove_role($this->current);
		unset($this->roles[$this->current]);

		if ( $customized_roles = get_option( 'pp_customized_roles' ) ) {
			if ( isset( $customized_roles[$this->current] ) ) {
				unset( $customized_roles[$this->current] );
				update_option( 'pp_customized_roles', $customized_roles );
			}
		}
		
		ak_admin_notify(sprintf(__('Role has been deleted. %1$d users moved to default role %2$s.', $this->ID), $count, $this->roles[$default]));
		$this->current = $default;
	}
}

?>