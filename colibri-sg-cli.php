<?php


if ( defined( 'WP_CLI' ) && WP_CLI ) {

    if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
        define( 'WP_LOAD_IMPORTERS', true );
    }

    class ColibriWPSGCLI extends WP_CLI_Command {

        const COLIBRI_PLUGIN = 'colibri-page-builder/colibri-page-builder.php';
        const IMPORTER_PLUGIN = 'wordpress-importer/wordpress-importer.php';
        const DEMO_URL_BASE = 'https://colibriwp.com/sg-demos/demo-{{id}}.json';
        const SITEGROUND_WIZARD = "wordpress-starter/siteground-wizard.php";

        /** @var \WP_Import $this */
        private $wp_importer;
        /** @var \WP_Filesystem_Base $this */
        private $wp_filesystem;

        private $source_site_url;

        private $xml_file = false;
        private $empty = false;


        private $import_map = array(
            'partials'  => array(),
            'pages'     => array(),
            'post_json' => array(),
            'menus'     => array(),
        );
        private $partials_by_id = [];


        private $colibri_post_types = null;


        private function prepare() {

            global $wp_filesystem;

            if ( empty( $wp_filesystem ) ) {
                require_once( ABSPATH . '/wp-admin/includes/file.php' );
                WP_Filesystem();
            }
            $this->wp_filesystem = $wp_filesystem;

            $this->clear_log();
            $this->install_if_missing();
            $this->wp_importer = new \WP_Import();
            \ExtendBuilder\register_custom_post_types();
        }

        public function import( $args ) {
            $import      = isset( $args[0] ) ? $args[0] : false;
            $this->empty = ( isset( $args[1] ) && $args[1] === 'empty' );


            if ( ! $import ) {
                return;
            }

            $this->prepare();
            $json = $this->get_import_data( $import );
            $this->execute_import( $json );
        }

        private function colibri_can_continue() {
            return true;
        }

        private function get_import_data( $import ) {
            $url = str_replace( '{{id}}', $import, self::DEMO_URL_BASE );
            $this->log( array( 'Import URL', $url ) );

            return wp_remote_retrieve_body( wp_remote_get( $url ) );
        }

        private function mapAdd( $type, $key, $value ) {
            $this->log( array( 'Map Add', $type, $key, $value ) );
            $this->import_map[ $type ][ $key ] = $value;
        }

        private function mapGet( $type ) {
            return $this->import_map[ $type ];
        }

        private function mapSet( $type, $data ) {
            $this->import_map[ $type ] = $data;
        }

        private function execute_import( $json ) {
            $this->log( 'Maybe Started' );

            if ( ! $this->colibri_can_continue() ) {
                return true;
            }

            $this->log( 'Import Started' );

            $json = json_decode( $json, true );

            $this->log( $json );

            // Bail if provided json is invalid.
            if ( false === $json ) {
                return true;
            }

            // Bail if provided Colibri Page Builder is not installed.
            if ( ! class_exists( '\ColibriWP\PageBuilder\PageBuilder' ) ) {
                $this->log( '\ColibriWP\PageBuilder\PageBuilder not found' );

                return true;
            }

            // Bail if provided WP_Importer is not installed.
            if ( ! class_exists( "WP_Import" ) ) {
                $this->log( 'WP_Import not found' );

                return true;
            }


            if ( $this->empty ) {
                $this->log( 'Empty Site' );
                exec( 'wp site empty --yes' );
            }

            $this->log( 'Import XML' );
            ob_start();
            $this->colibri_import_xml( $json['xml'] );
            $this->log( array( 'Import log', ob_get_clean() ) );
            $this->log( 'Import Customizer Options' );
            $this->colibri_import_options( $json['dat'] );
            $this->log( 'Import Widgets' );
            $this->colibri_import_widgets( $json['wie'] );
            $this->log( 'Prepare Local Style' );
            $this->prepare_local_style();
            $this->log( 'Finish' );
            $this->finish();

            return false;
        }


        private function install_if_missing() {
            include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

            $theme = 'colibri-wp';

            if ( get_option( 'template' ) !== $theme ) {
                $this->log( "Install theme: {$theme}" );

                if ( ! file_exists( WP_CONTENT_DIR . "/themes/{$theme}" ) ) {
                    exec( "wp theme install {$theme} --activate" );
                }
                update_option( 'template', $theme );
                update_option( 'stylesheet', $theme );
                wp_cache_flush();
            }

            $plugins = array(
                'wordpress-importer'   => array(
                    'file'  => self::IMPORTER_PLUGIN,
                    'class' => '\WP_Import',
                ),
                'colibri-page-builder' => array(
                    'file'  => self::COLIBRI_PLUGIN,
                    'class' => '\ColibriWP\PageBuilder\PageBuilder',
                )
            );


            foreach ( $plugins as $plugin => $data ) {
                if ( ! \is_plugin_active( $data['file'] ) || ! file_exists( WP_PLUGIN_DIR . "/" . $data['file'] ) ) {
                    exec( "wp plugin install {$plugin} --activate --force" );
                }

                if ( ! class_exists( $data['class'] ) ) {
                    if ( file_exists( WP_PLUGIN_DIR . "/" . $data['file'] ) ) {
                        $this->log( array(
                            'Plugin Loaded',
                            $data['file']
                        ) );
                        include_once WP_PLUGIN_DIR . "/" . $data['file'];
                    }
                }
            }


        }

        private function colibri_import_xml( $filename ) {
            set_time_limit( 0 );
            $this->wp_importer->id                = 0;
            $this->wp_importer->fetch_attachments = true;

            $self = $this;
            add_filter( 'wp_import_posts', function ( $posts ) use ( $self ) {

                $this->log( 'Preprocess Posts' );

                $posts = $self->colibri_pre_import_filter_posts( $posts );

                $self->colibri_pre_import_posts( $posts );

                return $posts;
            } );

            add_filter( 'wp_import_terms', function ( $terms ) use ( $self ) {

                $this->log( 'Preprocess Terms' );

                $self->colibri_pre_import_terms( $terms );

                return $terms;
            } );


            add_filter( 'wp_import_post_data_processed', function ( $post_data ) use ( $self ) {

                return $self->colibri_post_data_processed( $post_data );
            } );

            add_action( 'import_start', function () use ( $self ) {
                $self->source_site_url = $self->wp_importer->base_url;
            } );

            $colibri_posts = $this->get_colibri_post_types();

            add_filter( 'wp_import_existing_post', function ( $exists, $post ) use ( $colibri_posts ) {
                if ( in_array( $post['post_type'], $colibri_posts ) ) {
                    $exists = false;
                }

                return $exists;
            }, 10, 2 );


            $this->xml_file = $this->colibri_save_remote_file( $filename, uniqid( 'colibri-xml' ) . ".xml" );


            $this->log( array( 'XML File:', $this->xml_file ) );

            $this->wp_importer->import( $this->xml_file );


            $this->colibri_post_import_posts_process_map();
            $this->colibri_post_import_posts_process_json();
        }


        private function colibri_save_remote_file( $fileurl, $filename = false ) {
            // Get the WordPress uploads dir.
            $upload_dir = wp_upload_dir();

            if ( ! $filename ) {
                $filename = basename( $fileurl );
            }

            // Get the file content.
            $contents = $this->wp_filesystem->get_contents( $fileurl );

            // Build the temp filename.
            $temp_filename = $upload_dir['basedir'] . '/' . $filename;

            // Save the content to temp file.
            $status = $this->wp_filesystem->put_contents(
                $temp_filename, // Temp filename.
                $contents // File content.
            );

            // Finally return the temp filename.
            return $temp_filename;


        }


        private function get_colibri_post_types() {
            if ( ! $this->colibri_post_types ) {
                $post_types               = \ExtendBuilder\post_types();
                $post_types[]             = "sidebar";
                $this->colibri_post_types = array_map( function ( $value ) {
                    return "extb_post_{$value}";
                }, $post_types );
            }

            return $this->colibri_post_types;
        }


        private function colibri_pre_import_filter_posts( $posts ) {
            $result     = array();
            $skip_posts = array( 'revision' );
            foreach ( $posts as $post ) {
                if ( ! in_array( $post['post_type'], $skip_posts ) ) {
                    $result[] = $post;
                }
            }

            $this->log( array(
                'Filtered Posts',
                'before' => count( $posts ),
                'after'  => count( $result )
            ) );


            return $result;
        }

        private function colibri_pre_import_posts( $posts ) {
            $colibri_post_types = $this->get_colibri_post_types();

            $this->log( 'post types' );


            $this->log( $colibri_post_types );

            foreach ( $posts as $index => $post ) {
                $post_type = $post['post_type'];
                $guid      = $post['guid'];
                $post_id   = $post['post_id'];
                $meta      = isset( $post['postmeta'] ) ? $post['postmeta'] : array();
                if ( in_array( $post_type, $colibri_post_types ) ) {
                    $this->mapAdd( 'partials', $guid, $post_id );
                }

                if ( $post_type === 'page' ) {
                    $this->mapAdd( 'pages', $guid, $post_id );
                }

                $post['json_data'] = false;

                if ( ! empty( $meta ) ) {
                    foreach ( $meta as $item ) {
                        if ( $item['key'] === 'extend_builder' ) {
                            $value = unserialize( $item['value'] );
                            $this->mapAdd( 'post_json', $post_id, intval( $value['json'] ) );
                            $post['json_data'] = intval( $value['json'] );
                        }
                    };
                }

                if ( in_array( $post_type, $colibri_post_types ) || $post_type === 'page' ) {
                    $this->partials_by_id[ intval( $post_id ) ] = $post;
                }

            }


            $this->log( array_merge( (array) 'Pre import posts', $this->import_map ) );
        }

        private function colibri_pre_import_terms( $terms ) {
            foreach ( $terms as $term ) {
                if ( isset( $term['term_taxonomy'] ) && $term['term_taxonomy'] === "nav_menu" ) {
                    $slug = $term['slug'];
                    $this->mapAdd( 'menus', $slug, $term['term_id'] );
                }
            }
        }

        private function colibri_post_data_processed( $data ) {
            if ( $data['post_content'] && strpos( $data['post_type'],
                    \ExtendBuilder\custom_post_type_wp_name( 'json' ) ) !== false ) {

                if ( ! json_decode( $data['post_content'] ) ) {
                    $this->log( 'WP Unslash' );
                    $data['post_content'] = wp_unslash( $data['post_content'] );
                }

            }

            return $data;
        }


        private function colibri_post_import_posts_process_map() {
            $this->log( array( 'MAP', $this->import_map ) );

            $partials = $this->mapGet( 'partials' );
            $this->mapSet( 'partials', $this->colibri_process_mapped_batch( $partials ) );

            $pages = $this->mapGet( 'pages' );
            $this->mapSet( 'pages', $this->colibri_process_mapped_batch( $pages ) );

            $this->log( array( 'MAP AFTER', $this->import_map ) );
        }

        private function colibri_post_import_posts_process_json() {

            $pages_map = $this->mapGet( 'pages' );
            $partials  = $this->mapGet( 'partials' );
            $entities  = $pages_map + $partials;
            $json      = $this->mapGet( 'post_json' );

            foreach ( $json as $post_id => $json_id ) {
                $current_post_id = isset( $entities[ $post_id ] ) ? $entities[ $post_id ] : false;
                $current_json_id = isset( $entities[ $json_id ] ) ? $entities[ $json_id ] : false;

                if ( $current_json_id && $current_post_id ) {
                    $meta         = get_post_meta( $current_post_id, 'extend_builder', true );
                    $meta['json'] = $current_json_id;
                    delete_post_meta( $current_post_id, 'extend_builder' );
                    update_post_meta( $current_post_id, 'extend_builder', $meta );

                }
            }
        }

        private function colibri_process_menu_items() {
            $menus        = $this->mapGet( 'menus' );
            $cached_terms = array();
            $map          = array();

            $this->log( array( 'Menus', $menus ) );

            $nav_menu_locations = get_theme_mod( 'nav_menu_locations' );

            foreach ( $menus as $slug => $id ) {

                if ( ! isset( $cached_terms[ $slug ] ) ) {
                    $cached_terms[ $slug ] = get_term_by( 'slug', $slug, 'nav_menu' );
                }
                $term = $cached_terms[ $slug ];

                if ( $term ) {
                    $map[ intval( $id ) ] = intval( $term->term_id );
                }
            }

            if ( count( $map ) ) {

                foreach ( $nav_menu_locations as $key => $value ) {
                    if ( isset( $map[ $value ] ) ) {
                        $nav_menu_locations[ $key ] = $map[ $value ];
                    }
                }

                set_theme_mod( 'nav_menu_locations', $nav_menu_locations );
            }

            $source_url_base = $this->source_site_url;
            $target_url_base = untrailingslashit( home_url() );

            foreach ( $cached_terms as $term ) {
                $menuItems = wp_get_nav_menu_items( $term->term_id );

                /** @var \WP_Post $menuItem */
                foreach ( $menuItems as $menuItem ) {
                    if ( $menuItem->type === 'custom' && strpos( $menuItem->url, $source_url_base ) === 0 ) {
                        $newURL = str_replace( $source_url_base, $target_url_base, $menuItem->url );
                        wp_update_nav_menu_item( $term->term_id, $menuItem->ID, array(
                            'menu-item-object-id'   => $menuItem->object_id,
                            'menu-item-object'      => $menuItem->object,
                            'menu-item-parent-id'   => $menuItem->menu_item_parent,
                            'menu-item-position'    => $menuItem->menu_order,
                            'menu-item-type'        => $menuItem->type,
                            'menu-item-title'       => $menuItem->post_title,
                            'menu-item-url'         => $newURL,
                            'menu-item-description' => $menuItem->post_content,
                            'menu-item-attr-title'  => $menuItem->post_excerpt,
                            'menu-item-target'      => $menuItem->target,
                            'menu-item-classes'     => $menuItem->classes,
                            'menu-item-xfn'         => $menuItem->xfn,
                            'menu-item-status'      => $menuItem->post_status,
                        ) );
                    }
                }
            }
        }

        private function colibri_process_mapped_batch( $batch ) {
            global $wpdb;

            $guids = array_map( function ( $guid ) use ( $wpdb ) {
                $wpdb->escape_by_ref( $guid );

                $guid_escaped = str_replace( "&", "&#038;", $guid );
                $wpdb->escape_by_ref( $guid_escaped );

                $guid_escaped_2 = str_replace( "&", "&amp;", $guid );
                $wpdb->escape_by_ref( $guid_escaped_2 );

                return "'{$guid}' , '$guid_escaped', '$guid_escaped_2'";
            }, array_keys( $batch ) );


            $guids_string = implode( ",", $guids );
            $query        = "SELECT ID AS id, guid FROM {$wpdb->posts} WHERE guid IN ({$guids_string})";

            $entities = $wpdb->get_results( $query );

            $map = array();

            foreach ( $entities as $entity ) {
                $post_guid  = html_entity_decode( $entity->guid );
                $initial_id = null;
                if ( isset( $batch[ $post_guid ] ) ) {
                    $initial_id = $batch[ $post_guid ];
                } else {
                    $post_guid = str_replace( "&", "&#038;", $post_guid );
                    if ( isset( $batch[ $post_guid ] ) ) {
                        $initial_id = $batch[ $post_guid ];
                    }
                }

                if ( $initial_id ) {
                    $map[ intval( $initial_id ) ] = intval( $entity->id );
                }
            }

            return $map;
        }

        private function colibri_import_options( $filename ) {

            $options = $this->wp_filesystem->get_contents( $filename );

            if ( empty( $options ) ) {
                return true;
            }

            $data = unserialize( $options );

            if ( ! is_array( $data ) ) {
                return true;
            }

            $data['mods'] = $this->colibri_import_customizer_images( $data['mods'] );

            foreach ( $data['mods'] as $key => $value ) {
                set_theme_mod( $key, $value );
            }

            $pages_map = $this->mapGet( 'pages' );
            $this->log( array( 'Options set pages map', $pages_map ) );
            if ( isset( $data['options'] ) ) {
                $options      = $data['options'];
                $options_keys = array(
                    "extend_builder_theme",
                    "show_on_front",
                    "page_on_front",
                    "page_for_posts",
                    "site_icon",
                );


                foreach ( $options_keys as $key ) {
                    if ( isset( $options[ $key ] ) ) {
                        $value = $options[ $key ];

                        if ( in_array( $key, array( 'page_on_front', 'page_for_posts' ) ) ) {
                            if ( isset( $pages_map[ $value ] ) ) {
                                $value = $pages_map[ $value ];
                            }
                        }

                        update_option( $key, $value );
                    }
                }
            }

            wp_cache_flush();
            $this->colibri_set_template_partials();
            $this->colibri_set_pages();
            $this->colibri_process_menu_items();

        }

        private function colibri_import_customizer_images( $mods ) {
            foreach ( $mods as $key => $val ) {
                if ( $this->customizer_is_image_url( $val ) ) {
                    $data = $this->customizer_sideload_image( $val );
                    if ( ! is_wp_error( $data ) ) {
                        $mods[ $key ] = $data->url;

                        // Handle header image controls.
                        if ( isset( $mods[ $key . '_data' ] ) ) {
                            $mods[ $key . '_data' ] = $data;
                            update_post_meta( $data->attachment_id, '_wp_attachment_is_custom_header',
                                get_stylesheet() );
                        }
                    }
                }
            }

            return $mods;
        }


        private function customizer_is_image_url( $string = '' ) {
            if ( is_string( $string ) ) {
                if ( preg_match( '/\.(jpg|jpeg|png|gif)/i', $string ) ) {
                    return true;
                }
            }

            return false;
        }


        private function customizer_sideload_image( $file ) {
            $data = new \stdClass();

            if ( ! function_exists( 'media_handle_sideload' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
            }
            if ( ! empty( $file ) ) {
                // Set variables for storage, fix file filename for query strings.
                preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
                $file_array         = array();
                $file_array['name'] = basename( $matches[0] );

                // Download file to temp location.
                $file_array['tmp_name'] = download_url( $file );

                // If error storing temporarily, return the error.
                if ( is_wp_error( $file_array['tmp_name'] ) ) {
                    return $file_array['tmp_name'];
                }

                // Do the validation and storage stuff.
                $id = media_handle_sideload( $file_array, 0 );

                // If error storing permanently, unlink.
                if ( is_wp_error( $id ) ) {
                    unlink( $file_array['tmp_name'] );

                    return $id;
                }

                // Build the object to return.
                $meta                = wp_get_attachment_metadata( $id );
                $data->attachment_id = $id;
                $data->url           = wp_get_attachment_url( $id );
                $data->thumbnail_url = wp_get_attachment_thumb_url( $id );
                $data->height        = $meta['height'];
                $data->width         = $meta['width'];
            }

            return $data;
        }

        private function colibri_set_template_partials() {
            $extend_builder_theme = get_option( 'extend_builder_theme' );
            $default_partials     = \ExtendBuilder\array_get_value( $extend_builder_theme, 'defaults.partials' );

            $partials = $this->mapGet( 'partials' );

            foreach ( $default_partials as $area => $data ) {
                foreach ( $data as $partial => $id ) {
                    if ( isset( $partials[ $id ] ) ) {
                        $default_partials[ $area ][ $partial ] = $partials[ $id ];
                    }
                }
            }
            \ExtendBuilder\array_set_value( $extend_builder_theme, 'defaults.partials', $default_partials );
            update_option( 'extend_builder_theme', $extend_builder_theme );
        }

        private function colibri_set_pages() {
            $pages_map = $this->mapGet( 'pages' );

            $initial_front_page = get_option( 'page_on_front' );
            $initial_blog_page  = get_option( 'page_for_posts' );

            if ( isset( $pages[ $initial_front_page ] ) ) {
                update_option( 'page_on_front', $pages_map[ $initial_front_page ] );
            }


            if ( isset( $pages_map[ $initial_blog_page ] ) ) {
                update_option( 'page_for_posts', $pages_map[ $initial_blog_page ] );
            }
        }

        private function colibri_import_widgets( $filename ) {
            global $siteground_migrator_helper;

            if ( ! $siteground_migrator_helper ) {
                if ( file_exists( WP_PLUGIN_DIR . "/" . self::SITEGROUND_WIZARD ) ) {
                    $this->log( 'Manually load SiteGround Starter Wizard' );
                    require_once WP_PLUGIN_DIR . "/" . self::SITEGROUND_WIZARD;
                } else {
                    return false;
                }
            }


            try {
                $wie = $this->wp_filesystem->get_contents( $filename );

                if ( empty( $wie ) ) {
                    return true;
                }
                $wie_importer = new \SiteGround_Wizard\Importer\Wie_Importer();

                $wie_importer->import( json_decode( $wie ) );
            } catch ( \Exception $e ) {
                $this->log( $e->getMessage(), 'ERROR' );
            }
        }


        private function prepare_local_style() {
            $theme           = \ExtendBuilder\get_theme_data();
            $cssByPartialId  = \ExtendBuilder\get_key_value( $theme, 'cssByPartialId', array() );
            $new_partial_css = array();

            foreach ( $this->partials_by_id as $id => $partial ) {
                $id      = intval( $id );
                $json_id = $partial['json_data'];

                $new_id      = false;
                $new_json_id = false;

                if ( isset( $this->import_map['partials'][ $id ] ) ) {
                    $new_id = $this->import_map['partials'][ $id ];
                }

                if ( isset( $this->import_map['pages'][ $id ] ) ) {
                    $new_id = $this->import_map['pages'][ $id ];
                }

                if ( isset( $this->import_map['partials'][ $json_id ] ) ) {
                    $new_json_id = $this->import_map['partials'][ $json_id ];
                }


                if ( $new_id && $new_json_id ) {
                    $partial_content = $partial['post_content'];
                    $json_content    = $this->partials_by_id[ $json_id ]['post_content'];

                    $this->log( "Set local style: partial - {$id} => {$new_id} | json - {$json_id} => {$new_json_id}" );

                    $this->set_item_local_style( $new_id, $partial_content, $id, $new_id );
                    $this->set_item_local_style( $new_json_id, $json_content, $id, $new_id );

                    $partial_css = $cssByPartialId[ $id ];

                    foreach ( $partial_css as $style_id => $css_by_media ) {
                        foreach ( $css_by_media as $media => $css ) {
                            $new_css = $css;

                            $new_css = preg_replace( '/([\-])(' . $id . ')([\-])/i',
                                '${1}' . $new_id . '${3}', $new_css );

                            $new_style_id = \ExtendBuilder\Import::replace_partial_id_short( $style_id, $id, $new_id );
                            \ExtendBuilder\array_set_value( $new_partial_css, [ $new_id, $new_style_id, $media ],
                                $new_css );
                        }
                    }

                }

            }

            \ExtendBuilder\array_set_value( $theme, 'cssByPartialId', $new_partial_css );
            \ExtendBuilder\save_theme_data( $theme );
        }

        private function set_item_local_style( $id, $content, $find_id, $replace_with_id ) {
            $content = \ExtendBuilder\Import::replace_partial_id( $content, $find_id, $replace_with_id );

            wp_update_post(
                array(
                    'ID'           => $id,
                    'post_content' => $content
                )
            );
        }

        private function finish() {
            update_option( 'colibri_sg_imported', time() );

            if ( is_file( $this->xml_file ) ) {
                $this->wp_filesystem->delete( $this->xml_file );
            } else {
                $this->log( "File: {$this->xml_file} does not exists" );
            }
        }

        private function log( $message = '', $type = 'INFO' ) {
            if ( defined( 'COLIBRI_DEBUG' ) ) {
                $upload_dir = wp_upload_dir();
                $message    = json_encode( $message );
                $time       = date( "Y-m-d H:i:s" );
                $message    = "[{$type}]: {$time}\n{$message}\n\n";
                file_put_contents( $upload_dir['basedir'] . "/colibri-si.log", $message, FILE_APPEND );
            }
        }


        private function clear_log() {
            if ( defined( 'COLIBRI_DEBUG' ) ) {
                $upload_dir = wp_upload_dir();
                if ( file_exists( $upload_dir['basedir'] . "/colibri-si.log" ) ) {
                    $this->wp_filesystem->delete( $upload_dir['basedir'] . "/colibri-si.log" );
                }
            }
        }

    }


    try {
        WP_CLI::add_command( 'colibri-si', 'ColibriWPSGCLI' );
    } catch ( \Exception $e ) {
    }
}

