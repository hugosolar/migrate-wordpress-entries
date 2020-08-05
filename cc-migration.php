<?php
   /*
   Plugin Name: CC Staging migration
   Plugin URI: https://creativecommons.org
   description: This plugin migrate blog entries from production to staging
   Version: 2020.08.1
   Author: Hugo Solar
   Author URI: http://hugo.solar
   License: GPL2
   */

class CCMigration {
  const CC_MIGRATE_TRANSIENT_NAME = 'cc_org_last_entries';
  const CC_MIGRATE_IDS_TRANSIENT_NAME = 'cc_org_last_entries_id';
  const PLUGIN_VER = '2020.08.1';

  public $posts_per_page = 20;
  public $query_url = 'https://creativecommons.org/wp-json/wp/v2/posts';
  public $media_url = 'https://creativecommons.org/wp-json/wp/v2/media/';

  private static $instance;

  private function __construct() {}

  public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new CCMigration;
			self::$instance->actions_manager();
		}

		return self::$instance;
	}

  public function __clone() {
		wp_die( "Please don't clone this class" );
	}

  public function add_settings_page() {
    add_management_page( 'Staging migration', 'CC Migration', 'manage_options', 'cc_migration', array( $this, 'cc_render_migration_page' ) );
  }

  public function actions_manager() {

    add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

    add_action("wp_ajax_do_migration", array($this, "cc_org_do_migration"));
    add_action("wp_ajax_nopriv_do_migration", array($this, "cc_org_do_migration"));
  }
  public function enqueue_assets( $hook ) {
    if ( $hook == 'tools_page_cc_migration' ) {
      $this->remove_api_request_cache();
      $entries = $this->get_cc_org_data();
      $id_entries = get_transient( self::CC_MIGRATE_IDS_TRANSIENT_NAME );
      wp_enqueue_script( 'cc-migration', plugins_url( 'js/cc-migration.js', __FILE__ ), array( 'jquery' ), self::PLUGIN_VER, true );
      wp_enqueue_style( 'cc-migration', plugins_url( 'css/cc-migration.css', __FILE__ ), array(), self::PLUGIN_VER );

      wp_localize_script('cc-migration', 'CC_entries', $id_entries);
    }
  }
  public function query_api( $url ) {
    $response = wp_remote_get( $url );
    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code == 200 ) {
      $api_response  = json_decode(wp_remote_retrieve_body( $response ));
      return $api_response;
    } else {
      return false;
    }
  }
  public function get_cc_org_data() {
    if ( false === ( $api_response = get_transient( self::CC_MIGRATE_TRANSIENT_NAME ) ) ) {
      $url_params = $this->query_url.'?per_page='.$this->posts_per_page;
      $api_response = $this->query_api( $url_params );
      if ( !empty( $api_response ) ) {
        $entries_ids_array = array();
        $ids_array = array();
        foreach ($api_response as $entry) {
          $entries_ids_array[$entry->id] = $entry;
          $ids_array[] = $entry->id;
        }
        set_transient( self::CC_MIGRATE_TRANSIENT_NAME , $entries_ids_array, HOUR_IN_SECONDS );
        set_transient( self::CC_MIGRATE_IDS_TRANSIENT_NAME , $ids_array, HOUR_IN_SECONDS );
      } else {
        $this->remove_api_request_cache();
      }
    }
    return $api_response;
  }
  public function remove_api_request_cache() {
    delete_transient( self::CC_MIGRATE_TRANSIENT_NAME );
  }
  public function unique_rename_file( $dir, $name, $ext ) {
    //this will avoid wordpress to rename files adding numbers at the end 
    return $name;
  }
  public function import_media( $entry_id, $parent_id = null ) {
    if ( !empty( $entry_id ) ) {
      $media_url = $this->media_url.$entry_id;
      $api_response = $this->query_api( $media_url );
      if ( !empty( $api_response ) ) {
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $image_url = $api_response->source_url;
        $temp_file = download_url( $image_url );
        if ( !is_wp_error( $temp_file ) ) {
          $file = array(
            'name'     => basename($image_url), // ex: wp-header-logo.png
            'type'     => $api_response->mime_type,
            'tmp_name' => $temp_file,
            'error'    => 0,
            'size'     => filesize( $temp_file ),
          );
          $uploaded_image = wp_handle_sideload( $file, array(
            'test_form' => false, 
            'test_type' => true, 
            'test_upload' => true,
            'unique_filename_callback' => array( $this, 'unique_rename_file' )
          ));
          if ( empty( $uploaded_image['error'] ) ) {
            $image_meta = array(
              'post_title' => esc_attr($api_response->title->rendered),
              'post_excerpt' => $api_response->caption->rendered,
              'post_mime_type' => $uploaded_image['type']
            );
            $attach_id = wp_insert_attachment( $image_meta, $uploaded_image['file'], $parent_id );
            update_post_meta( $attach_id, '_wp_attachment_image_alt', $api_response->alt_text );
            $attach_data = wp_generate_attachment_metadata( $attach_id, $uploaded_image['file'] );
            wp_update_attachment_metadata( $attach_id, $attach_data );
            return $attach_id;
          }
        } else {
          @unlink( $temp_file );
          return false;
        }
      }
    }
  }

  public function replace_media_urls( $entry_content ) {
    if ( !empty($entry_content) ) {
      preg_match_all('@((https?://)?([-\\w]+\\.[-\\w\\.]+)+\\w(:\\d+)?(/([-\\w/_\\.]*(\\?\\S+)?)?)*)@',$entry_content,$a);
      foreach ( $a[0] as $url ) {
        if ( strpos($url, 'wp-content/uploads/2020/') !== false  ) {
          $split_url = explode('/',$url);
          $current_domain_url = explode('/', get_bloginfo('url'));
          $split_url[2] = $current_domain_url[2];
          $split_url[5] = date('Y');
          $split_url[6] = date('m');
          $final_url = implode('/',$split_url);
          $entry_content = str_replace($url,$final_url, $entry_content);
        }
      }
    }
    return $entry_content;
  }
  public function get_terms( $term_access ) {
    $api_response = $this->query_api( $term_access->href );
    if ( !empty( $api_response ) ) {
      return $api_response;
    } else {
      return false;
    }
  }
  public function create_term( $term ) {
    if ( !empty( $term ) ) {
      $term_data = wp_insert_term(
        $term->name,
        $term->taxonomy,
        array(
          'description' => $term->description,
          'slug' => $term->slug
        )
      );
      if ( !is_wp_error( term_data ) ) {
        return $term_data['term_id'];
      } else {
        return false;
      }
    }
  }
  public function process_entry_terms( $entry, $inserted_post_id ) {
    if ( !empty( $entry ) ) {
      wp_remove_object_terms( $inserted_post_id, array( 1 ), 'category' );
      $terms_array = $entry->{'_links'}->{'wp:term'};
      if ( !empty( $terms_array ) ) {
        foreach ( $terms_array as $terms ) {
          $term_list = $this->get_terms( $terms );
          if ( !empty( $term_list ) ) {
            foreach ( $term_list as $term ) {
              $term_exist = term_exists( $term->slug, $terms->taxonomy );
              if ( !$term_exist ) {
                $term_id = $this->create_term( $term );
              } else {
                $term_id = $term_exist['term_id'];
              }
              if ( !empty( $term_id ) ) {
                $inserted_term = wp_set_post_terms( $inserted_post_id, array( (int)$term_id ), $terms->taxonomy, true);
              }
            }
          }
        }
      }
    }
  }
  // TODO improve error handling
  public function process_entry( $entry_id ) {
    $entries = $this->get_cc_org_data();
    $entry = $entries[$entry_id];
    $post_exists = post_exists( $entry->title->rendered );
    if ( !empty( $entry ) && ( !$post_exists )  ) {
      $post = array(
        'post_title' => $entry->title->rendered,
        'post_status' => $entry->status,
        'post_author' => $entry->author,
        'post_date' => $entry->date,
        'post_name' => $entry->slug,
        'post_content' => $this->replace_media_urls( $entry->content->rendered )
      );
      $entry_post = wp_insert_post( $post );
      $this->process_entry_terms( $entry, $entry_post );
      if ( !empty( $entry->featured_media ) ) {
        $attachment_id = $this->import_media( $entry->featured_media, $entry_post );
        set_post_thumbnail($entry_post, $attachment_id);
      }
      $entry_media = $this->entry_related_media( $entry->id );
      return $entry_post;
    } else {
      throw new Exception('Post already exists');
    } 
  }

  public function entry_related_media( $parent_id ) {
    if ( !empty( $parent_id ) ) {
      $media_url = $this->media_url.'?parent='.$parent_id;
      $api_response = $this->query_api( $media_url );
      if ( !empty( $api_response ) ) {
        foreach ( $api_response as $entry ) {
          $this->import_media( $entry->id, $parent_id );
        }
      }
    }
  }

  public function cc_org_do_migration() {
    
    if ( check_ajax_referer( 'cc_get_posts', 'nonce' ) ) {
      $id = esc_attr($_POST['id']);
      if ( !empty( $id ) ) {
        $entries = $this->get_cc_org_data();
        $entry = $entries[$id];
        echo 'Processing post with the ID '.$id.' - <a href="'.$entry->link.'" target="_blank">'.$entry->title->rendered.'</a> <br>';
        try {
          $insert_post = $this->process_entry( $id );
          echo '<p style="color:green;">Post added </p>';
        } catch ( Exception $e ) {
          echo '<p style="color:red;">Post not added:'. $e->getMessage() .'</p>';
        }
      }
    }
    exit(0);
  }

  public function cc_render_migration_page() {
    ?>
    <h2>Migrate CC.org Entries</h2>
    <?php 
      echo wp_nonce_field( 'cc_get_posts', 'cc_get_posts_nonce', true, false ); 
    ?>
    <div class="cc_migration_result" id="cc_migration_result"></div>
    <button class="button button-primary" id="do_migration" ><?php esc_attr_e( 'Migrate' ); ?></button>
    
    <?php

  }
}

$cc_migration = CCMigration::get_instance();
