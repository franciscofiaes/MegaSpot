<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Listing_Manager_Submission
 *
 * @class Listing_Manager_Submission
 * @package Listing_Manager/Classes/Submission
 * @author Code Vision
 */
class Listing_Manager_Submission {
	/**
	 * Initialize
	 *
	 * @access public
	 * @return void
	 */
	public static function init() {
		add_filter( 'listing_manager_submission_fields', array( __CLASS__, 'add_general_fields' ), 9, 1 );
		add_filter( 'listing_manager_file_fields', array( __CLASS__, 'file_fields' ) );

		add_action( 'init', array( __CLASS__, 'process_remove_form' ), 9999 );
		add_action( 'init', array( __CLASS__, 'process_remove_attachment' ), 9999 );
		add_action( 'wp_loaded', array( __CLASS__, 'process_submission_form' ) );
	}

	/**
	 * Process remove listing form
	 *
	 * @access public
	 * @return void
	 */
	public static function process_remove_form() {
		if ( ! isset( $_POST['remove_listing_form'] ) || empty( $_POST['listing_id'] ) ) {
			return;
		}

		if ( wp_delete_post( $_POST['listing_id'] ) ) {
			wc_add_notice( esc_html__( 'Listing has been successfully removed.', 'listing-manager' ), 'success' );
		} else {
			wc_add_notice( esc_html__( 'An error occurred when removing listing.', 'listing-manager' ), 'error' );
		}
	}

	/**
	 * Removes attachment
	 *
	 * @access public
	 * @return void
	 */
	public static function process_remove_attachment() {
		if ( empty( $_GET['id'] ) || empty( $_GET['remove_attachment_id'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || empty( $_GET['id'] ) || empty( $_GET['remove_attachment_id'] ) ) {
			wc_add_notice( esc_html__( 'You are not allowed to do this.', 'listing-manager' ), 'error' );
			return;
		}

		if ( ! Listing_Manager_Utilities::is_allowed_to_remove( get_current_user_id(), $_GET['id'] ) ) {
			wc_add_notice( esc_html__( 'You are not owner of this listing.', 'listing-manager' ), 'error' );
			return;
		}

		$attachment = get_post( $_GET['remove_attachment_id'] );

		if ( empty( $attachment ) ) {
			wc_add_notice( esc_html__( 'Attachment does not exist.', 'listing-manager' ), 'error' );
			return;
		}

		if ( get_current_user_id() != $attachment->post_author ) {
			wc_add_notice( esc_html__( 'You are not an owner of this attachment file.', 'listing-manager' ), 'error' );
			return;
		}

		if ( true == wp_delete_attachment( $_GET['remove_attachment_id'] ) ) {
			wc_add_notice( esc_html__( 'Attachment has been successfully removed.', 'listing-manager' ), 'success' );

			$fields = self::get_fields();

			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					$ids = explode( ',', get_post_meta( $_GET['id'], $field, true ) );

					if ( is_array( $ids ) ) {
						$okay = array();
						foreach ( $ids as $id ) {
							if ( $id != $_GET['remove_attachment_id'] ) {
								$okay[] = $id;
							}
						}

						if ( count( $okay ) > 0 ) {
							update_post_meta( $_GET['id'], $field, implode( ',', $okay ) );
						} else {
							delete_post_meta( $_GET['id'], $field );
						}
					} else {
						delete_post_meta( $_GET['id'], $field );
					}
				}
			}

			$listing_add = get_theme_mod( 'listing_manager_pages_listing_add', null );
			if ( ! empty( $listing_add ) ) {
				$url = get_permalink( $listing_add ) . '?id=' . $_GET['id'];
			} else {
				$url = site_url();
			}

			wp_redirect( $url );
			exit();
		} else {
			wc_add_notice( esc_html__( 'There was an error when removing an attachment.', 'listing-manager' ), 'error' );
		}
	}

	/**
	 * Process submission form
	 *
	 * @see init
	 * @access public
	 * @return void
	 */
	public static function process_submission_form() {
		if ( empty( $_POST['submit_listing'] ) ) {
			return;
		}

		if ( self::validate_package() && self::validate_fields() && Listing_Manager_User::validate_user_fields() ) {
			if ( $result = self::save_listing() ) {
				$listing_edit = get_theme_mod( 'listing_manager_pages_listing_edit', null );
				$listing_list = get_theme_mod( 'listing_manager_pages_listing_list', null );
				$listing_create_after = get_theme_mod( 'listing_manager_pages_listing_create_after' , null );

				if ( 'create' === $result['action'] && ! empty( $listing_create_after ) ) {
					$url = get_permalink( $listing_create_after );
				} elseif ( is_int( $result['post_id'] ) && ! empty( $listing_edit ) ) {
					$url = get_permalink( $listing_edit ) . '?id=' . $result['post_id'];
				} elseif ( ! empty( $listing_list ) ) {
					$url = get_permalink( $listing_list );
				} else {
					$url = home_url();
				}

				wp_redirect( $url );
				exit();
			}
		}
	}

	/**
	 * Validates chosen package
	 *
	 * @access public
	 * @return bool
	 */
	public static function validate_package() {
		if ( 'free' === get_theme_mod( 'listing_manager_submission_type', 'free' ) ) {
			return true;
		}

		if ( empty( $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package' ] ) &&
		     empty( $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package_order' ] ) ) {
			wc_add_notice( esc_html__( 'Please select the package.', 'listing-manager' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Create new listing post type
	 *
	 * @access public
	 * @return bool|array
	 */
	public static function save_listing() {
		$order_id = empty( $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package_order' ] ) ? null : $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package_order' ];
		$package_id = empty( $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package' ] ) ? null : $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package' ];

		// If user selected already purchased package
		if ( ! empty( $order_id ) ) {
			$_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package' ] = Listing_Manager_Product_Package::get_order_package_id( $order_id );
		} elseif ( empty( $order_id ) && ! empty( $package_id ) ) {
			$_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package_order' ] = '';
		}

		// Set proper status
		if ( 'packages' === get_theme_mod( 'listing_manager_submission_type', 'free' ) ) {
			if ( empty( $order_id ) ) {
				$status = 'pending';
			} elseif ( ! Listing_Manager_Product_Package::is_expired( $order_id, Listing_Manager_Product_Package::get_order_package_id( $order_id ) ) ) {
				if ( Listing_Manager_Product_Package::get_remaining_posts( $order_id ) > 0 ) {
					$status = 'publish';
				} else {
					$status = 'pending';
					wc_add_notice( esc_html__( 'No enough available listings for the selected package.' ), 'error' );
				}
			} else {
				$status = 'pending';
			}
		} else {
			$status = 'publish';
		}

		// Register user if needed
		if ( true === Listing_Manager_User::register_user() ) {
			wc_add_notice( esc_html__( 'You have been successfully registered.', 'listing-manager' ), 'success' );
		}

		$args = array(
			'post_title' 	=> $_POST['post_title'],
			'post_content' 	=> $_POST['post_content'],
			'post_type'		=> 'product',
			'post_status'	=> $status,
			'post_author'	=> get_current_user_id(),
		);

		if ( ! empty( $_GET['id'] ) ) {
			$args['ID'] = $_GET['id'];
			$post_id = wp_update_post( $args );
		} else {
			$post_id = wp_insert_post( $args );
		}

		if ( is_int( $post_id ) ) {
			// Set proper product type
			wp_set_object_terms( $post_id, 'listing', 'product_type' );
			
			// Add new package into cart if the user selected new package
		 	if ( 'packages' === get_theme_mod( 'listing_manager_submission_type', 'free' ) && empty( $order_id ) ) {
				$result = WC()->cart->add_to_cart( $_POST[ LISTING_MANAGER_LISTING_PREFIX . 'package' ] );

			    if ( false !== $result ) {
				    wc_add_notice( esc_html__( 'Package has been added into cart. Please purchase it before your listing will be published.', 'listing-manager' ), 'success' );
			    }
			}
            
			// Save all post meta beginning with listing prefix or underscore
			if ( ! empty( $_POST ) ) {
				foreach ( $_POST as $key => $value ) {
					if ( '_' === substr( $key, 0, strlen( '_' ) ) ||
						LISTING_MANAGER_LISTING_PREFIX === substr( $key, 0, strlen( LISTING_MANAGER_LISTING_PREFIX ) )
						) {

						$definition = self::get_field_definition( $key );
						if ( 'taxonomy' === $definition['type'] ) {
							$ids = array();

							if ( is_array( $value ) ) {
								foreach ( $value as $index => $id ) {
									$ids[] = (int) $id;
								}
								/* Alterei - adicionei uma categoria ao producto criado (ex: 'Credit') */
								//array_push($ids, 255);
							}

							wp_set_object_terms( $post_id, $ids, $definition['taxonomy'] );
							
							/* Alterei - adicionei uma tag ao producto chamada destaque */
							
							wp_set_object_terms( $post_id, 'destaque' , 'product_tag');
							
						}
						else {
							if ( ! empty( $value ) ) {
								update_post_meta( $post_id, $key, $value );
							} else {
								delete_post_meta( $post_id, $key );
							}
						}
					}
				}
			}
			
			// Alterei - Foi alterado o processo de save do campo preço deixou de ser _regular_price para _price
			if(! empty( $_POST['_regular_price'])){
				$preco = $_POST['_regular_price'];
				update_post_meta( $post_id, '_price', sanitize_text_field( $_POST['_regular_price'] ) );
			}
			
			
			// Save all media files
			if ( ! empty( $_FILES ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
                
				foreach ( $_FILES as $key => $value ) {
					if ( empty( $value['name'] ) || 0 === $value['size'] ) {
						continue;
					}
					if ( is_array( $value['name'] ) ) {
						Listing_Manager_Utilities::process_files( $post_id, $key, $value );
					} else {
						Listing_Manager_Utilities::process_file( $post_id, $key, $value );
					}
				}
			}
			
			
            // Alterei - Save PDF File
            if ( ! empty($_FILES['pdf'])){
				if($_FILES['pdf']['size'] <= 1000000){
					$upload_dir = wp_upload_dir();
					$user_dirname = $upload_dir['url'];

					$uploadfile = $user_dirname . '/' . basename($_FILES['pdf']['name']);
					update_field('pdf', $uploadfile, $post_id);
				}
				else{
					echo '<script>alert("Ficheiro demasiado grande.");</script>';
					unlink($_FILES['pdf']);
					wp_delete_post($post_id);
					return false;
				}
            }
			
			
			if( ! empty($_FILES['pdf-capa'])){
				$upload_dir = wp_upload_dir();
				$user_dirname = $upload_dir['url'];
				$uploadfile = $user_dirname . '/' . basename($_FILES['pdf-capa']['name']);
				
				//$attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $uploadfile )); 
        		//$attachment_id = $attachment[0]; 
				//set_post_thumbnail( $post_id, $attachment_id );
				
			}
			
			
			
			
			if ( ! empty( $_GET['id'] ) ) {
				$action = 'update';
				wc_add_notice( esc_html__( 'Listing has been successfully updated.', 'listing-manager' ), 'success' );
			} else {
				$action = 'create';
				wc_add_notice( esc_html__( 'Listing has been successfully added.', 'listing-manager' ), 'success' );
			}

			return array(
				'action' 	=> $action,
				'post_id' 	=> $post_id
			);
		}

		wc_add_notice( esc_html__( 'There was an error when saving the listing.' ), 'error' );
		return false;
	}

	/**
	 * Gets list of all required fields in submission form
	 *
	 * @access public
	 * @param array $fields
	 * @param array $ids
	 * @return array
	 */
	public static function get_required_fields( $fields, $ids = array() ) {
		foreach ( $fields as $field ) {
			if ( ! empty( $field['required'] ) && true === $field['required'] ) {
				$ids[] = $field['id'];
			}

			if ( 'fieldset' === $field['type'] ) {
				$ids = self::get_required_fields( $field['fields'], $ids );
			}
		}

		return $ids;
	}

	/**
	 * Gets field params
	 *
	 * @access public
	 * @param string $key
	 * @param array $fields
	 * @return array
	 */
	public static function get_field_definition( $key, $fields = null ) {
		if ( empty( $fields ) ) {
			$fields = self::get_fields();
		}

		foreach ( $fields as $field ) {
			if ( 'fieldset' === $field['type'] ) {
				$result = self::get_field_definition( $key, $field['fields'] );

				if ( ! empty( $result ) ) {
					return $result;
				}
			}
			if ( ! empty( $field['id'] ) && $field['id'] == $key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Validate submission for fields
	 *
	 * @access public
	 * @return bool|array
	 */
	public static function validate_fields() {
		$fields = self::get_required_fields( self::get_fields() );

		foreach ( $fields as $key ) {
			if ( empty( $_POST[ $key ] ) ) {
				$_SESSION['form_errors'][ $key ][] = esc_html__( 'Field is required.', 'listing-manager' );
			}
		}

		if ( ! empty( $_SESSION['form_errors'] ) && count( $_SESSION['form_errors'] ) > 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Remove all validation errors
	 *
	 * @access public
	 * @return void
	 */
	public static function clean_validation_errors() {
		unset( $_SESSION['form_errors'] );
	}

	/**
	 * Adds general fields
	 *
	 * @hook listing_manager_submission_fields
	 * @access public
	 * @param array $fields
	 * @return void
	 */
	public static function add_general_fields( $fields ) {
		$fields[] = array(
			'type' 				=> 'fieldset',
			'id'                => 'general',
			'legend'            => esc_html__( 'General Information', 'listing-manager' ),
			'fields'			=> array(
				array(
					'id' 		=> 'post_title',
					'type'		=> 'text',
					'label' 	=> esc_html__( 'Title', 'listing-manager' ),
					'required' 	=> true,
				),
				array(
					'id' 		=> 'post_content',
					'type'		=> 'textarea',
					'label' 	=> esc_html__( 'Description', 'listing-manager' ),
					'required' 	=> true,
					'rows'		=> 5,
				),
				array(
					'id' 		=> 'featured_image',
					'type'		=> 'file',
					'label' 	=> esc_html__( 'Featured Image', 'listing-manager' ),
					'required' 	=> false,
				),
			),
		);

		$fields[] = array(
			'type'				=> 'fieldset',
			'id'                => 'gallery',
			'legend'			=> esc_html__( 'Gallery', 'listing-manager' ),
			'fields'			=> array(
				array(
					'id'        => '_product_image_gallery',
					'type'      => 'files',
					'label'		=> esc_html__( 'Gallery', 'listing-manager' ),
					'required' 	=> false,
				),
			),
		);

		$fields[] = array(
			'type'				=> 'fieldset',
			'id'                => 'taxonomies',
			'legend'			=> esc_html__( 'Taxonomies', 'listing-manager' ),
			'fields'			=> array(
				array(
					'id'        => LISTING_MANAGER_LISTING_PREFIX . 'product_tag',
					'type'      => 'taxonomy',
					'taxonomy'  => 'product_tag',
					'label'     => esc_html__( 'Tags', 'listing-manager' ),
					'required' 	=> false,
				),
				array(
					'id'        => LISTING_MANAGER_LISTING_PREFIX . 'product_cat',
					'type'      => 'taxonomy',
					'taxonomy'  => 'product_cat',
					'label'     => esc_html__( 'Categories', 'listing-manager' ),
					'required' 	=> false,
				),
				array(
					'id'        => LISTING_MANAGER_LISTING_PREFIX . 'locations',
					'type'      => 'taxonomy',
					'taxonomy'  => 'locations',
					'label'     => esc_html__( 'Locations', 'listing-manager' ),
					'required' 	=> false,
				),

				array(
					'id'        => LISTING_MANAGER_LISTING_PREFIX . 'amenities',
					'type'      => 'taxonomy',
					'taxonomy'  => 'amenities',
					'label'     => esc_html__( 'Amenities', 'listing-manager' ),
					'required' 	=> false,
				),
			),
		);

		$fields[] = array(
			'type'				=> 'fieldset',
			'id'                => 'price',
			'legend'			=> esc_html__( 'Price Options', 'listing-manager' ),
			'collapsible'       => true,
			'fields'			=> array(
				array(
					'id' 		=> '_regular_price',
					'type'		=> 'numeric',
					'label' 	=> esc_html__( 'Regular Price', 'listing-manager' ),
					'required' 	=> false,
				),
				array(
					'id' 		=> '_sale_price',
					'type'		=> 'text',
					'label' 	=> esc_html__( 'Sale Price', 'listing-manager' ),
					'required' 	=> false,
				),
			),
		);

		return $fields;
	}

	/**
	 * Gets fields
	 *
	 * @access public
	 * @return array
	 */
	public static function get_fields() {
		$fields = get_option( 'listing_manager_field_builder' );

		if ( empty( $fields ) ) {
			$fields = apply_filters( 'listing_manager_submission_fields', array() );
		}

		return $fields;
	}

	/**
	 * Render form fields
	 *
	 * @access public
	 * @param array $fields
	 * @param bool $update
	 * @return array
	 */
	public static function render_fields( $fields = null, $update = false ) {
		$output = '';

		if ( null === $fields ) {
			$fields = self::get_fields();
		}
		//echo '<pre>'; print_r( $fields ); die;
		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( 'fieldset' === $field['type'] ) {
					$atts = array();

					if ( ! empty( $field['fields'] ) ) {
						$atts['content'] = self::render_fields( $field['fields'], $update );
					} else {
						$atts['content'] = esc_html__( 'No fields defined.', 'listing-manager' );
					}

					$atts['collapsible'] = ! empty( $field['collapsible'] ) ? true : false;
					$atts['id'] = ! empty( $field['id'] ) ? $field['id'] : null;

					if ( ! empty( $field['legend'] ) ) {
						$atts['legend'] = $field['legend'];
					}

					$output .= wc_get_template_html( 'listing-manager/fields/fieldset.php', $atts, '', LISTING_MANAGER_DIR . 'templates/' );
				} else {
					$output .= self::render_field( $field, $update );
				}
			}
		}

		return $output;
	}

	/**
	 * Gets template for field
	 *
	 * @access public
	 * @param array $field
	 * @param bool $update
	 * @return string
	 */
	public static function render_field( $field, $update = false ) {
		$id = ! empty( $_GET['id'] ) ? $_GET['id'] : null;
		$field['value'] = self::get_field_value( $id, $field );

		return wc_get_template_html( 'listing-manager/fields/' . $field['type'] . '.php',
		$field, '', LISTING_MANAGER_DIR . 'templates/' );
	}

	/**
	 * Gets value for field
	 *
	 * @access public
	 * @param int $post_id
	 * @param array $field
	 * @return string|null
	 */
	public static function get_field_value( $post_id, $field ) {
		if ( ! empty( $_POST[ $field['id'] ] ) ) {
			return $_POST[ $field['id'] ];
		}

		if ( ! empty( $field['value'] ) ) {
			return $field['value'];
		}

		if ( empty( $post_id ) ) {
			return null;
		}

		// Process special fields
		if ( 'post_title' === $field['id'] ) {
			return get_the_title( $post_id );
		} elseif ( 'post_content' === $field['id'] ) {
			$post = get_post( $post_id );
			return $post->post_content;
		}

		// Fallback check the field meta value
		return get_post_meta( $post_id, $field['id'], true );
	}

	/**
	 * File fields
	 *
	 * @access public
	 * @param array $fields
	 * @return array
	 */
	public static function file_fields( $fields ) {
		$fields[] = 'featured_image';
		$fields[] = '_product_image_gallery';
		return $fields;
	}
}

Listing_Manager_Submission::init();
