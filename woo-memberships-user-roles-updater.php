<?php
/**
 * Plugin Name: User Role updater for WooCommerce Memberships.
 * Version: 1.0.0
 * Author: Brajesh Singh
 * Description: Set/reset roles when membership changes.
 */
/**
 * Class WC_Membership_Role_Helper
 */
class WCMC_Membership_Role_Update_Helper {

	/**
	 * Setup hooks.
	 */
	public function setup() {

		// WooMembership does not fire 'wc_memberships_user_membership_status_changed'
		// for the first time transition from auto-draft to wcm-active.
		// we need to do processiing on it too.
		add_action( 'transition_post_status',                array( $this, 'on_membership_add' ), 10, 3 );
		
		// handle Membership status changes.
		add_action( 'wc_memberships_user_membership_status_changed', array(
			$this,
			'on_membership_status_change',
		), 10, 3 );
		// user membership deleted.
		add_action( 'before_delete_post', array( $this, 'on_membership_delete' ) );

		//add_action( 'wc_memberships_user_membership_saved', array( $this, 'membership_saved' ),10,2 );

		// admin panel tabs on the add membership plan page.
		add_filter( 'wc_membership_plan_data_tabs', array( $this, 'admin_add_role_options' ) );
		add_action( 'wc_membership_plan_data_panels', array( $this, 'admin_role_data_panel' ) );

		// update roles association.
		add_action( 'wc_memberships_save_meta_box',array( $this, 'save_associations' ), 10, 4 );
	}

	/**
	 * Handle post status transitions for user memberships
	 *
	 * @param string  $new_status New status slug.
	 * @param string  $old_status Old status slug.
	 * @param WP_Post $post Related WP_Post object.
	 */
	public function on_membership_add( $new_status, $old_status, WP_Post $post ) {

		if ( 'wc_user_membership' !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		// It complements the woomemberships, so we only need for the auto-draft etc.
		if ( 'new' !== $old_status && 'auto-draft' !== $old_status ) {
			return;
		}

		// not for us.
		if ( strpos( $new_status, 'wcm-' ) !== 0 ) {
			return;
		}


		$user_membership = wc_memberships_get_user_membership( $post );

		$this->update_role_for_plan( $user_membership->get_plan_id(), $user_membership->get_user_id(), $user_membership->get_status() );
	}

	/**
	 * Handle user membership status changes
	 *
	 * @param \WC_Memberships_User_Membership $user_membership user membership object.
	 * @param string                          $old_status old membership status.
	 * @param string                          $new_status new membership status.
	 */
	public function on_membership_status_change( $user_membership, $old_status, $new_status ) {

		if ( $old_status === $new_status ) {
			return;// no change.
		}
		$this->update_role_for_plan( $user_membership->get_plan_id(), $user_membership->get_user_id(), $new_status );
	}

	/**
	 * On membership delete.
	 *
	 * @param int $post_id post id.
	 */
	public function on_membership_delete( $post_id ) {

		if ( get_post_type( $post_id ) !== 'wc_user_membership' ) {
			return;
		}

		$user_membership = wc_memberships_get_user_membership( $post_id );
		// we will only work for active membership.
		if ( ! $user_membership || $user_membership->get_status() != 'active' ) {
			return;
		}

		$this->update_role_for_plan( $user_membership->get_plan_id(), $user_membership->get_user_id(), 'cancelled' );
	}


	/**
	 * Not used.
	 *
	 * @param WC_Memberships_Membership_Plan $plan plan.
	 * @param array                          $args plan/user info.
	 */
	public function membership_saved( $plan, $args ) {
		if ( ! $plan ) {
			return;
		}

		$user_id = $args['user_id'];
		$user_membership = wc_memberships_get_user_membership( $args['user_membership_id'] );
		$this->update_role_for_plan( $plan->get_id(), $user_id, $user_membership->get_status() );
	}


	/**
	 * Add new tab on add/edit plan.
	 *
	 * @param array $tabs tabs.
	 *
	 * @return array new tabs.
	 */
	public function admin_add_role_options( $tabs ) {
		$tabs['members_roles'] = array(
			'label'  => __( 'Member Role' ),
			'target' => 'membership-plan-data-members-role',
		);

		return $tabs;
	}


	/**
	 * Add roles data on the opanel.
	 */
	public function admin_role_data_panel() {
		global $post;
		$roles          = get_editable_roles();
		$selected_roles = get_post_meta( $post->ID, '_wcmc_roles', true );

		if ( empty( $selected_roles ) ) {
			$selected_roles = array();
		}
		?>

		<div id="membership-plan-data-members-role" class="panel woocommerce_options_panel">

			<div class="table-wrap">
				<div class="widefat js-rules">
					<h4><?php _e( 'Set role to:' );?></h4>
					<?php foreach ( $roles as $key => $role ) : ?>
						<label>
							<input name="_wcmc_roles[]" type="checkbox" value="<?php echo esc_attr( $key );?>" <?php checked( true, in_array( $key, $selected_roles ) );?>> <?php echo esc_html( $role['name'] );?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div><!--//#membership-plan-data-members-role-->

		<style type="text/css">
			#membership-plan-data-members-role label {
				float: none;
				width: auto;
				margin:10px;
				display: block;
			}
			#membership-plan-data-members-role input[type='checkbox'] {
				margin-right: 10px;
			}
		</style>
	<?php }

	/**
	 * Save plan to role association.
	 *
	 * @param array   $pos_vars $_POST data.
	 * @param string  $box_id meta box id?
	 * @param int     $post_id post id.
	 * @param WP_Post $post post object.
	 */
	public function save_associations( $pos_vars, $box_id, $post_id, $post ) {

		$roles = isset( $_POST['_wcmc_roles'] ) ? $_POST['_wcmc_roles'] : false;
		if ( ! $roles ) {
			delete_post_meta( $post_id, '_wcmc_roles' );
		} else {
			update_post_meta( $post_id, '_wcmc_roles', $_POST['_wcmc_roles'] );
		}
	}

	/**
	 * Update user role by plan id.
	 *
	 * @param int    $plan_id plan id.
	 * @param int    $user_id user id.
	 * @param string $new_status membership status.
	 */
	private function update_role_for_plan( $plan_id, $user_id, $new_status ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return;// invalid user.
		}


		// let us get the roles.
		$plan_roles = get_post_meta( $plan_id, '_wcmc_roles', true );

		if ( empty( $plan_roles ) ) {
			return;
		}
		// possible status.
		// cancelled
		// paused
		// active
		// expired.
		if ( 'active' === $new_status ) {
			// Remove any previous role.
			$user->set_role( '' );
			// add roles.
			foreach ( $plan_roles as $role ) {
				$user->add_role( $role );// no set_role.
			}
		} elseif ( 'cancelled' === $new_status || 'paused' === $new_status || 'expired' === $new_status ) {
			// remove these roles.
			foreach ( $plan_roles as $role ) {
				$user->remove_role( $role );
			}
		}
		// and always preserve customer role.
		$user->add_role( 'customer' );
	}

}

// init.
$wcm = new WCMC_Membership_Role_Update_Helper();
$wcm->setup();
