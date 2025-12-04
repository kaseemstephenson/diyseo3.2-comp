<?php

/*
Plugin Name: DIYSEO - AI Writer for SEO Content
Plugin URI: https://diyseo.ai/
Description: An AI-powered SEO content generator for WordPress.
Version: 3.5
Author: LSEO
License: GPL2
*/
/*
 * Add my new menu to the Admin Control Panel
 */
 if (! defined( 'ABSPATH' )) {
     exit;
 } // Exit if accessed directly

if (!defined('DIYSEO_PLUGIN_VERSION')) {
    define('DIYSEO_PLUGIN_VERSION', '3.4.0');
}

require_once(plugin_dir_path(__FILE__) . 'diyseo-settings.php');



// Add this to your main plugin file

// Define a global variable to store the license key
$GLOBALS['diyseo_license_key'] = '';

function diyseo_post_has_seo_data($post_id = null) {
    if (!is_singular()) {
        return false;
    }

    if (!$post_id) {
        $post_id = get_queried_object_id();
    }

    if (!$post_id) {
        $post_id = get_the_ID();
    }

    if (!$post_id) {
        return false;
    }

    $meta_title = trim((string) get_post_meta($post_id, '_diyseo_meta_title', true));
    $meta_description = trim((string) get_post_meta($post_id, '_diyseo_meta_description', true));

    return ($meta_title !== '' || $meta_description !== '');
}
function diyseo_track_article_generation() {
    $generation_count = get_option('diyseo_free_generations', 0);
    update_option('diyseo_free_generations', $generation_count + 1);
    return $generation_count + 1;
}
function diyseo_cleanup_head() {
    if (!diyseo_post_has_seo_data()) {
        return;
    }

    // Remove default WordPress meta tags
    remove_action('wp_head', 'rel_canonical');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'yoast-seo-meta-tag');
    // Remove feed links if not needed
    remove_action('wp_head', 'feed_links', 2);
    remove_action('wp_head', 'feed_links_extra', 3);
}
add_action('init', 'diyseo_cleanup_head');
function diyseo_clean_page_output() {
    if (!is_page() || !diyseo_post_has_seo_data()) {
        return;
    }

    global $wp_filter;

    // Remove all filters that might add meta tags
    $meta_filters = array(
        'wp_head',
        'get_wp_title_rss',
        'wp_title',
        'pre_get_document_title',
        'document_title_parts'
    );

    foreach ($meta_filters as $filter) {
        if (isset($wp_filter[$filter])) {
            foreach ($wp_filter[$filter] as $priority => $callbacks) {
                foreach ($callbacks as $callback_data) {
                    // Check if the callback contains 'yoast' or 'wpseo' without serializing
                    $callback_string = '';
                    if (is_string($callback_data['function'])) {
                        $callback_string = $callback_data['function'];
                    } elseif (is_array($callback_data['function'])) {
                        if (is_object($callback_data['function'][0])) {
                            $callback_string = get_class($callback_data['function'][0]);
                        } elseif (is_string($callback_data['function'][0])) {
                            $callback_string = $callback_data['function'][0];
                        }
                        if (isset($callback_data['function'][1])) {
                            $callback_string .= '::' . $callback_data['function'][1];
                        }
                    }

                    if (stripos($callback_string, 'yoast') !== false ||
                        stripos($callback_string, 'wpseo') !== false) {
                        remove_filter($filter, $callback_data['function'], $priority);
                    }
                }
            }
        }
    }
}
add_action('template_redirect', 'diyseo_clean_page_output', 0);
function diyseo_ensure_meta_tags() {
    if (!diyseo_post_has_seo_data()) {
        return;
    }

    // Remove only specific actions that might interfere with our meta tags
    remove_all_actions('wp_head', 1);  // Remove early actions

    // Add our meta tags back
    add_action('wp_head', 'diyseo_output_meta_tags', 1);

    // Add back essential WordPress head elements
    add_action('wp_head', 'wp_enqueue_scripts', 2);
    add_action('wp_head', 'wp_print_styles', 8);
    add_action('wp_head', 'wp_print_head_scripts', 9);
}
add_action('init', 'diyseo_ensure_meta_tags');
function diyseo_get_command_center_context() {
    $user_id = get_current_user_id();
    $email = get_user_meta($user_id, 'diyseo_user_email', true);

    if (!$email) return '';

    $response = wp_remote_post('https://diyseoapi-69f00dabdd4f.herokuapp.com/api/command-center/context', [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body' => json_encode([
            'email' => $email,
            'scope' => 'plugin'
        ])
    ]);

    if (is_wp_error($response)) return '';

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['context'] ?? '';
}

function diyseo_clean_header_output() {
    // Changed to check for ALL single content types
    if (!defined('WPSEO_VERSION') || !is_singular() || !diyseo_post_has_seo_data()) {
        return;
    }

    add_action('wp_head', function() {
        ob_start(function($output) {
            // Remove any Yoast meta tags
            $output = preg_replace('/<meta[^>]*(yoast-seo-meta-tag|wpseo)[^>]*>/', '', $output);

            // Remove duplicate title tags (keep only the first one)
            $pattern = "/<title>(.*?)<\/title>/i";
            preg_match_all($pattern, $output, $matches);
            if (count($matches[0]) > 1) {
                $output = preg_replace($pattern, '', $output, count($matches[0]) - 1);
            }

            return $output;
        });
    }, 0);

    add_action('wp_head', function() {
        ob_end_flush();
    }, 999);

    // Remove Yoast's actions explicitly
    remove_action('wp_head', array(YoastSEO()->meta, 'meta_description'), 6);
    remove_action('wp_head', array(YoastSEO()->meta, 'print_title'), 1);
    remove_action('wp_head', array(YoastSEO()->meta, 'metadesc'), 6);
    remove_action('wp_head', array(YoastSEO()->meta, 'print_head'), 9);

    // Remove Yoast's JSON-LD
    add_filter('wpseo_json_ld_output', '__return_false');

    // Remove Yoast's OpenGraph
    remove_action('wpseo_opengraph', array(YoastSEO()->meta, 'opengraph_title'), 10);
    remove_action('wpseo_opengraph', array(YoastSEO()->meta, 'opengraph_description'), 10);
}
add_action('template_redirect', 'diyseo_clean_header_output', 0);
function diyseo_add_meta_tags() {
    if (!diyseo_post_has_seo_data()) {
        return;
    }

    // Add our meta tags with very high priority to ensure they're added after cleanup
    add_action('wp_head', 'diyseo_output_meta_tags', 1);

    // Remove conflicting title actions
    remove_action('wp_head', '_wp_render_title_tag', 1);
    add_filter('pre_get_document_title', '__return_empty_string', 999);
    add_filter('wp_title', '__return_empty_string', 999);
}

function diyseo_output_meta_tags() {
    $post_id = get_the_ID();
    $meta_title = get_post_meta($post_id, '_diyseo_meta_title', true);
    $meta_description = get_post_meta($post_id, '_diyseo_meta_description', true);
    
    // Always output viewport and robots meta
    echo '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
    echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />' . "\n";
    
    // Output title tag if we have one
    if (!empty($meta_title)) {
        echo "<title>" . esc_html($meta_title) . "</title>\n";
        echo '<meta property="og:title" content="' . esc_attr($meta_title) . '" />' . "\n";
    } else {
        // Fallback to post/page title if no meta title is set
        echo "<title>" . esc_html(get_the_title()) . "</title>\n";
        echo '<meta property="og:title" content="' . esc_attr(get_the_title()) . '" />' . "\n";
    }
    
    // Output description if we have one
    if (!empty($meta_description)) {
        echo '<meta name="description" content="' . esc_attr($meta_description) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($meta_description) . '" />' . "\n";
    }
    
    // Add basic OG type
    echo '<meta property="og:type" content="' . (is_page() ? 'website' : 'article') . '" />' . "\n";
    
    // Add canonical URL
    $canonical_url = get_permalink();
    echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
}

// Hook into WordPress head
add_action('wp_head', 'diyseo_add_meta_tags', 1);
function diyseo_get_remaining_generations() {
    $has_license = diyseo_get_license_key();

    // Add free trial message at the top if no license
    if (!$has_license) {
    $generation_count = get_option('diyseo_free_generations', 0);
    return max(0, 3 - $generation_count);
}
$sub_level = get_option('diyseo_user_sub');
if($sub_level == "PRO"){
$generation_count = get_option('diyseo_free_generations', 0);
    return max(0, 100 - $generation_count);
}
if($sub_level == "PLUS"){
$generation_count = get_option('diyseo_free_generations', 0);
    return max(0, 50 - $generation_count);

}
return null;
}

function diyseo_check_generation_limit() {
    if (!diyseo_get_license_key()) {
        return diyseo_get_remaining_generations() > 0;
    }
    return true;
}
function diyseo_set_license_key($key) {
    update_option('diyseo_license_key', $key);
    //("License key set: " . substr($key, 0, 5) . "...");
}

function diyseo_get_license_key() {
    //("License key retrieved from options: " . ($key ? "Yes" : "No"));
    return get_option('diyseo_license_key');
}
function diyseo_is_user_authenticated() {
    $user_id = get_current_user_id();
    $user_email = get_user_meta($user_id, 'diyseo_user_email', true);
    $access_token = get_user_meta($user_id, 'diyseo_access_token', true);
    
    return (!empty($user_email) && !empty($access_token));
}
function diyseo_ajax_clear_auth() {
    check_ajax_referer('diyseo_license_nonce', 'nonce');
    
    $user_id = get_current_user_id();
    delete_user_meta($user_id, 'diyseo_user_email');
    delete_user_meta($user_id, 'diyseo_access_token');
    delete_option('diyseo_license_key');
    
    wp_send_json_success('Authentication cleared');
}
add_action('wp_ajax_diyseo_clear_auth', 'diyseo_ajax_clear_auth');
// Function to safely get POST data
function diyseo_get_post_data($key, $nonce_key = null, $nonce_action = null, $default = null) {
    // If nonce checking is requested
    if ($nonce_key !== null && $nonce_action !== null) {
        if (!isset($_POST[$nonce_key])) {
            wp_die('Nonce is missing', 'Security Error', array('response' => 403));
        }
        $nonce = sanitize_text_field(wp_unslash($_POST[$nonce_key]));
        if (!wp_verify_nonce($nonce, $nonce_action)) {
            wp_die('Security check failed', 'Security Error', array('response' => 403));
        }
    }

    // Return the sanitized value if it exists, otherwise return the default
    return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
}

function diyseo_check_existing_articles($articles) {
    $existing_articles = array();
    
    foreach ($articles as $article) {
        $args = array(
            'post_type' => array('post', 'page'), // Changed to include both posts and pages
            'post_status' => array('publish', 'draft', 'pending', 'private'),
            'title' => $article['title'],
            'posts_per_page' => 1
        );
        
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            $existing_articles[] = $article['title'];
        }
    }
    
    return $existing_articles;
}




function diyseo_plugin_row_meta($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $new_links = array(
            'visit-site' => '<a href="https://diyseo.ai" target="_blank">' . __('Visit plugin site', 'diyseo-ai-powered-seo-content-generator') . '</a>'
        );
        $links = array_merge($links, $new_links);
    }
    return $links;
}
add_filter('plugin_row_meta', 'diyseo_plugin_row_meta', 10, 2);

















// AJAX handler to set the license key
// AJAX handler to set the license key
function diyseo_ajax_set_license_key() {
    $nonce = diyseo_get_post_data('_ajax_nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'diyseo_set_license_key_nonce')) {
        wp_send_json_error('Nonce verification failed');
        return;
    }

    $license_key = diyseo_get_post_data('license_key');
    if ($license_key) {
        diyseo_set_license_key($license_key);
        wp_send_json_success('License key set successfully');
    } else {
        wp_send_json_error('No license key provided');
    }
}
add_action('wp_ajax_diyseo_set_license_key', 'diyseo_ajax_set_license_key');
add_action('wp_ajax_nopriv_diyseo_set_license_key', 'diyseo_ajax_set_license_key');


// AJAX handler to check if the license key is set
function diyseo_ajax_check_license_key() {
    $key = diyseo_get_license_key();
    wp_send_json_success(array('is_set' => !empty($key)));
}
add_action('wp_ajax_diyseo_check_license_key', 'diyseo_ajax_check_license_key');
add_action('wp_ajax_nopriv_diyseo_check_license_key', 'diyseo_ajax_check_license_key');
function diyseo_enqueue_scripts() {
    wp_enqueue_script('diyseo-ajax-script', plugin_dir_url(__FILE__) . 'diyseo-script.js', array('jquery'), DIYSEO_PLUGIN_VERSION, true);

    wp_localize_script('diyseo-ajax-script', 'diyseo_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('diyseo_set_license_key_nonce')
    ));

    $screen = get_current_screen();
    if ($screen && $screen->base === 'post') {
        wp_enqueue_script('diyseo-calendar', plugin_dir_url(__FILE__) . 'js/diyseo-calendar.js', array('jquery'), '1.0.0', true);
        wp_localize_script('diyseo-calendar', 'diyseoCalendarParams', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('diyseo_calendar_nonce'),
            'postId' => get_the_ID()
        ));

        // Register and enqueue our custom styles
        wp_register_style('diyseo-admin-styles', false, array(), DIYSEO_PLUGIN_VERSION);
        wp_enqueue_style('diyseo-admin-styles');
        
        wp_add_inline_style('diyseo-admin-styles', '
            .diyseo-notification {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(0.9);
                background-color: #080B53;
                color: #ffffff;
                padding: 20px 30px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 999999;
                opacity: 0;
                transition: all 0.3s ease-in-out;
                border-left: 4px solid #ea188a;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 14px;
            }
            .diyseo-notification.show {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        ');
    }
        wp_add_inline_script('diyseo-calendar', "
            document.addEventListener('DOMContentLoaded', function() {
                const dateInput = document.getElementById('diyseo_post_date');
                if (dateInput) {
                    // Ensure the input is enabled
                    dateInput.removeAttribute('readonly');
                    dateInput.removeAttribute('disabled');
                    
                    // Set min date to today to prevent selecting past dates (optional)
                    dateInput.min = new Date().toISOString().split('T')[0];
                    
                    // Force the calendar to open on click
                    dateInput.addEventListener('click', function(e) {
                        e.preventDefault();
                        this.showPicker();
                    });
                }
            });
        ");
}
add_action('admin_enqueue_scripts', 'diyseo_enqueue_scripts');


function diyseo_get_api_key() {
    $license_key = diyseo_get_license_key();
    //("License key retrieved: " . ($license_key ? "Yes" : "No"));
    
    if (empty($license_key)) {
        //("License key is empty");
        return false;
    }

    $api_url = 'https://diyseoapi-69f00dabdd4f.herokuapp.com/givePluginContentKey/' . urlencode($license_key);
    //("Requesting API key from: $api_url");

    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        //("Error in wp_remote_get: " . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    //("API response body: " . substr($body, 0, 100) . "...");

    // Extract the API key from the Authorization header
    if (preg_match('/Bearer\s+(sk-[a-zA-Z0-9]+)/', $body, $matches)) {
        $api_key = $matches[1];
        //("API key extracted successfully: " . substr($api_key, 0, 10) . "...");
        return str_replace('9832864324', '', $api_key);
    }

    //("Failed to extract API key from response");
    return false;
}





/*function diyseo_add_admin_menu() {
    add_menu_page(
        'DIYSEO',     // Page title
        'DIY SEO',              // Menu title
        'manage_options',       // Capability
        'diyseo_home',      // Menu slug
        'diyseo_home_page'  // Callback function
    );
    add_submenu_page(
        'diyseo_home',          // Parent slug (must match main menu slug)
        'Settings',           // Page title
        'Settings',           // Menu title
        'manage_options',            // Capability
        'diyseo_settings_page',    // Menu slug
        'diyseo_settings_page' // Callback function
    );
    add_submenu_page(
        'diyseo_home',          // Parent slug (must match main menu slug)
        'Content Calendar',           // Page title
        'Content Calendar',           // Menu title
        'manage_options',            // Capability
        'diyseo_contentcalendar_page',    // Menu slug
        'diyseo_contentcalendar_page' // Callback function
    );
}
*/
function diyseo_add_admin_menu() {
    add_menu_page(
        'DIYSEO',              // Page title
        'DIYSEO',              // Menu title
        'manage_options',      // Capability
        'diyseo_settings_page',// Menu slug
        'diyseo_settings_page' // Callback function
    );

    add_submenu_page(
        'diyseo_settings_page',
        'Feedback',
        'Feedback',
        'manage_options',
        'diyseo-feedback',
        '__return_false'
    );

    add_submenu_page(
        'diyseo_settings_page',
        'Link Marketplace',
        'Link Marketplace',
        'manage_options',
        'diyseo-link-marketplace',
        '__return_false' // We'll override this with JavaScript
    );
}
add_action('admin_menu', 'diyseo_add_admin_menu');
require_once plugin_dir_path(__FILE__) . 'diyseo-command-center.php';

function diyseo_admin_footer_script() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Override Feedback submenu link
        $('a[href="admin.php?page=diyseo-feedback"]').attr('href', 'https://diyseo.ai/diyseo-user-feedback/')
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer');

        // Override Link Marketplace submenu link
        $('a[href="admin.php?page=diyseo-link-marketplace"]').attr('href', 'https://diyseo.ai/marketplace/')
            .attr('target', '_blank')
            .attr('rel', 'noopener noreferrer');
    });
    </script>
    <?php
}
add_action('admin_footer', 'diyseo_admin_footer_script');


// Perform the action based on the license key



















function diyseo_generate_content($prompt) {
     $context = diyseo_get_command_center_context();
     $prompt = $context . "\n\n" . $prompt;

    //("diyseo_generate_content called with prompt: " . substr($prompt, 0, 100) . "...");
 if (!diyseo_check_generation_limit()) {
        return new WP_Error('generation_limit', 'You have reached your free article generation limit. Please obtain a license key from diyseo.ai to continue.');
    }
    
    // If no license key, track the generation
    if (!diyseo_get_license_key()) {
        diyseo_track_article_generation();
    }
    $api_key = diyseo_get_api_key();
    if (!$api_key) {
        //("Failed to retrieve API key");
        return new WP_Error('api_key_error', 'Failed to retrieve API key. Please check your license key or try refreshing the page.');
    }
    //("API key retrieved successfully: " . substr($api_key, 0, 10) . "...");

    $response = wp_remote_post("https://api.openai.com/v1/chat/completions", array(
        'timeout' => 90,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ),
        'body' => wp_json_encode(array(
            "model" => "gpt-4o",
            "max_tokens" => 4000,
            "messages" => array(
                array("role" => "system", "content" => $prompt)
            )
        ))
    ));

    //("Sending request to OpenAI API");

    if (is_wp_error($response)) {
        //("WP_Error: " . $response->get_error_message());
        return new WP_Error('api_error', 'WP_Error: ' . $response->get_error_message());
    }

    wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    //("API Response Code: " . $http_code);
    //("API Response: " . substr($body, 0, 1000) . "...");

    $parsedResponse = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        //("JSON Decode Error: " . json_last_error_msg());
        return new WP_Error('api_error', 'JSON Decode Error: ' . json_last_error_msg());
    }

    if (isset($parsedResponse['choices'][0]['message']['content'])) {
        //("Content generated successfully");
        return $parsedResponse['choices'][0]['message']['content'];
    } else {
        return new WP_Error('api_error', 'Failed to generate content or unexpected response structure.');
    }
}









function diyseo_add_calendar_meta_box() {
    add_meta_box(
        'diyseo_calendar_meta_box',
        'DIY SEO Content Generation',
        'diyseo_calendar_meta_box_callback',
        array('post', 'page'), // Changed from just 'post' to array of post types
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'diyseo_add_calendar_meta_box');



// Add Meta Box to Post Edit Screen
/*
function diyseo_add_meta_box() {
    add_meta_box(
        'diyseo_meta_box', // Meta Box ID
        'DIY SEO Article Generator', // Title
        'diyseo_meta_box_callback', // Callback function
        'post', // Post type
        'normal', // Context
        'high' // Priority
    );
    
}
*/
// Hook to add the meta box
//add_action('add_meta_boxes', 'diyseo_add_meta_box');

// Meta Box Callback Function
function diyseo_meta_box_callback($post) {
    // Generate nonce field for security
    wp_nonce_field('diyseo_save_post_meta_box_data', 'diyseo_meta_box_nonce');

   /* $post_title = get_post_meta($post->ID, '_diyseo_post_title', true);
    $post_date = get_post_meta($post->ID, '_diyseo_post_date', true);
    $post_category = get_post_meta($post->ID, '_diyseo_post_category', true);
    $word_count = get_post_meta($post->ID, '_diyseo_word_count', true);
*/
    // High-tech, futuristic styles
    echo '<div style="background-color: #080B53; padding: 20px; color: #E0E0E0; border-radius: 15px; box-shadow: 0px 0px 20px grey; font-family: \'Roboto\', sans-serif;">';

    ?>
    
    <!-- End of Meta Box -->

    <?php
    //wp_enqueue_script('diyseo-meta-box', plugin_dir_url(__FILE__) . 'diyseo-meta-box.js', array('jquery'), '1.0.0', true);

    // Localize the script with new data
    $script_data_array = array(
        'postId' => $post->ID,
        'nonce' => wp_create_nonce('diyseo_save_post_meta_box_data')
    );
    wp_localize_script('diyseo-meta-box', 'diyseoParams', $script_data_array);
}



// Handle AJAX request to generate article
function diyseo_handle_ajax_request() {
    check_ajax_referer('diyseo_save_post_meta_box_data', 'nonce');

$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

$post_title = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';

    $word_count = isset($_POST['word_count']) ? absint($_POST['word_count']) : 1500; // 1500 as default fallback

    // Update post meta
    update_post_meta($post_id, '_diyseo_post_title', $post_title);
    update_post_meta($post_id, '_diyseo_word_count', $word_count);

    // Generate the article
    diyseo_generate_custom_article($post_id); // Ensure this returns content

    // Get the post data
    $post = get_post($post_id);

    // Return updated post title and content
    wp_send_json_success(array(
        'title'   => $post->post_title,
        'content' => $post->post_content,
    ));
}
//add_action('wp_ajax_diyseo_generate_article', 'diyseo_handle_ajax_request');
// Add these AJAX handlers along with other AJAX handlers in diyseo.php

add_action('wp_ajax_diyseo_get_calendars', 'diyseo_ajax_get_calendars');
add_action('wp_ajax_diyseo_get_categories', 'diyseo_ajax_get_categories');
add_action('wp_ajax_diyseo_get_articles', 'diyseo_ajax_get_articles');
add_action('wp_ajax_diyseo_generate_calendar_article', 'diyseo_ajax_generate_calendar_article');

function diyseo_ajax_get_calendars() {
    check_ajax_referer('diyseo_calendar_nonce', 'nonce');
    
    $user_email = get_user_meta(get_current_user_id(), 'diyseo_user_email', true);
    $access_token = get_user_meta(get_current_user_id(), 'diyseo_access_token', true);
    
    if (empty($user_email) || empty($access_token)) {
        wp_send_json_error('User not authenticated');
        return;
    }
    
    $response = wp_remote_get('https://diyseoapi-69f00dabdd4f.herokuapp.com/siteList/' . 
        urlencode($user_email) . '/' . urlencode($access_token));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Failed to fetch calendars');
        return;
    }
    
    $calendars = json_decode(wp_remote_retrieve_body($response), true);
    wp_send_json_success($calendars);
}
// In diyseo.php
function diyseo_ajax_get_categories() {
    check_ajax_referer('diyseo_calendar_nonce', 'nonce');
    
    if (empty($_POST['calendar'])) {
        wp_send_json_error('No calendar selected');
        return;
    }

    $domain = isset($_POST['calendar']) ? sanitize_text_field(wp_unslash($_POST['calendar'])) : '';
    $user_id = get_current_user_id();
    $user_email = get_user_meta($user_id, 'diyseo_user_email', true);
    $access_token = get_user_meta($user_id, 'diyseo_access_token', true);

    $response = wp_remote_get('https://diyseoapi-69f00dabdd4f.herokuapp.com/siteList/' . 
        urlencode($user_email) . '/' . urlencode($access_token));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Failed to fetch categories');
        return;
    }

    $sites = json_decode(wp_remote_retrieve_body($response), true);
    
   foreach ($sites as $site) {
        if ($site['domain'] === $domain && isset($site['categories'])) {
            $categories_data = json_decode($site['categories'], true);

            if (isset($categories_data['categories']) && is_array($categories_data['categories'])) {
                $formatted_categories = [];

                $formatted_categories[] = [
                    'value' => 'All',
                    'label' => 'All',
                    'indent' => false
                ];

                foreach ($categories_data['categories'] as $category_data) {
                    $main_category_key = key($category_data);
                    $main_category = $category_data[$main_category_key];
                    $subcategories = $category_data['subcategories'];

                    $formatted_categories[] = [
                        'value' => $main_category,
                        'label' => $main_category,
                        'indent' => false
                    ];

                    foreach ($subcategories as $subcategory) {
                        $formatted_categories[] = [
                            'value' => $main_category . '|' . $subcategory,
                            'label' => $subcategory,
                            'indent' => true
                        ];
                    }
                }

                wp_send_json_success(['categories' => $formatted_categories]);
                return;
            }
        }
    }
    wp_send_json_error('Categories not found');
}
add_action('wp_ajax_diyseo_get_categories', 'diyseo_ajax_get_categories');
function diyseo_ajax_get_articles() {
    check_ajax_referer('diyseo_calendar_nonce', 'nonce');
    
    if (empty($_POST['calendar'])) {
        wp_send_json_error('No calendar selected');
        return;
    }

    $domain = isset($_POST['calendar']) ? sanitize_text_field(wp_unslash($_POST['calendar'])) : '';
$category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';

    $user_id = get_current_user_id();
    $user_email = get_user_meta($user_id, 'diyseo_user_email', true);
    $access_token = get_user_meta($user_id, 'diyseo_access_token', true);
    
    $response = wp_remote_get('https://diyseoapi-69f00dabdd4f.herokuapp.com/siteList/' .
        urlencode($user_email) . '/' . urlencode($access_token));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Failed to fetch articles');
        return;
    }

    $sites = json_decode(wp_remote_retrieve_body($response), true);
    $articles = array();

    foreach ($sites as $site) {
        if ($site['domain'] === $domain && isset($site['contentCalendar'])) {
            $calendar_data = is_string($site['contentCalendar']) ? 
    json_decode($site['contentCalendar'], true) : 
    $site['contentCalendar'];
            if (isset($calendar_data['content_calendar'])) {
                foreach ($calendar_data['content_calendar'] as $article) {
                    $mainCategory = isset($article['category']) ? $article['category'] : '';
                    $subCategory = isset($article['subcategory']) ? $article['subcategory'] : '';
                    
                    if ($category === 'All') {
                        // Include all articles
                        $articles[] = array(
                            'date' => isset($article['date']) ? $article['date'] : '',
                            'title' => isset($article['title']) ? $article['title'] : ''
                        );
                    } 
                    elseif (strpos($category, '|') !== false) {
                        // Handling subcategory selection
                        list($selectedMainCat, $selectedSubCat) = explode('|', $category);
                        
                        // Check if both category and subcategory match
                        if ($mainCategory === $selectedMainCat && $subCategory === $selectedSubCat) {
                            $articles[] = array(
                                'date' => isset($article['date']) ? $article['date'] : '',
                                'title' => isset($article['title']) ? $article['title'] : ''
                            );
                        }
                    } elseif ($mainCategory === $category) {
                        // Handling main category selection
                        $articles[] = array(
                            'date' => isset($article['date']) ? $article['date'] : '',
                            'title' => isset($article['title']) ? $article['title'] : ''
                        );
                    }
                }
            }
        }
    }
    $existing_articles = diyseo_check_existing_articles($articles);
    
    // Add status to each article
    foreach ($articles as &$article) {
        $article['status'] = in_array($article['title'], $existing_articles) ? 'completed' : 'pending';
    }
    
    wp_send_json_success(array(
        'articles' => $articles,
        'existing' => $existing_articles
    ));
}
function diyseo_ajax_generate_calendar_article() {
    check_ajax_referer('diyseo_calendar_nonce', 'nonce');
$additional_context = isset($_POST['additional_context']) ? sanitize_textarea_field(wp_unslash($_POST['additional_context'])) : '';
$focus_keyword = isset($_POST['focus_keyword']) ? sanitize_textarea_field(wp_unslash($_POST['focus_keyword'])) : '';

diyseo_track_article_generation();
    
    // Add context to prompt if provided

$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    $word_count = isset($_POST['word_count']) ? absint($_POST['word_count']) : 1500; if (isset($_POST['is_custom'])) {
    filter_var(wp_unslash($_POST['is_custom']), FILTER_VALIDATE_BOOLEAN);
}
$title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';


    if (empty($title)) {
       // $error_message = $is_custom ? 'Please enter a custom title' : 'Please select an article from the content calendar';
       // wp_send_json_error(array('message' => $error_message));
        return;
    }
    update_post_meta($post_id, '_diyseo_post_title', $title);
    update_post_meta($post_id, '_diyseo_word_count', $word_count);

    $prompt = "You are a professional writer who must write EXACTLY {$word_count} words. You will be penalized if you write even one word more or less.

Write an article titled \"{$title}\".

Required word count: {$word_count} words exactly
Current task: Write article in HTML format using only h tags and p tags

Critical Instructions:
1. Count every single word carefully
2. Include a word count at the end in [Word Count: X] format
3. Do NOT include the word count in the article's word count
4. Use this exact structure:
   -(20% of words) Introduce the topic, define key terms, and clearly state why it matters.
   -(65% of words) Divide into logical sections using <h2> headers. Each section must address a subtopic, include a real-world example, and explain in plain terms.
   -(15% of words) Summarize the key takeaways, reinforce the main benefit to the reader, and end with a simple call-to-action or next step.
5. Stay focused on the topic without filler words
Use html format. Write as if you are in between html body tags. Please avoid using overly formal or complex language and use an authoritative, conversational tone.Please be extremly lengthy. Use every detail possible.
6. Format Guidelines:
- Use <h2> tags for section headers
- Use <p> tags for paragraphs
- Do not include doctype, html, head, or body tags
- Do not include any raw 'html' text in the output
- Do not include word count markers
📊 Visual Elements Requirement:
In the BODY section only (not intro or conclusion), insert ONE visual using **actual valid HTML**. Use whichever is most natural:

- A `<table>` with clean rows/columns — must include `<thead>` or `<tbody>`
- A `<ul>` or `<ol>` for examples or breakdowns
- Avoid ASCII diagrams

Use only valid HTML. Do not wrap it in code tags or `<pre>`. Do not describe the table — actually render it. 


";
if (!empty($focus_keyword)) {
    $prompt .= "\n\nSEO optimize this article with the focus keyword:\n" . $focus_keyword;
}
if (!empty($additional_context)) {
    $prompt .= "\n\nAdditional context to incorporate:\n" . $additional_context;
}
    $content = diyseo_generate_content($prompt);
    if (!is_wp_error($content)) {
        $blocks_content = diyseo_convert_to_blocks($content, $title);
        
        // Check if auto-generate meta is enabled
        $auto_generate_meta = isset($_POST['auto_generate_meta']) && filter_var(wp_unslash($_POST['auto_generate_meta']), FILTER_VALIDATE_BOOLEAN);
        
        if ($auto_generate_meta) {
            // Generate meta title
            $meta_title_prompt = "Generate a compelling SEO meta title (max 60 characters) for an article titled: " . $title;
            $meta_title = diyseo_generate_content($meta_title_prompt);
            if (!is_wp_error($meta_title)) {
                $meta_title = wp_strip_all_tags($meta_title);
                $meta_title = substr($meta_title, 0, 60);
            }
            
            // Generate meta description
            $meta_description_prompt = "Generate a compelling SEO meta description (max 160 characters) for an article titled: " . $title;
            $meta_description = diyseo_generate_content($meta_description_prompt);
            if (!is_wp_error($meta_description)) {
                $meta_description = wp_strip_all_tags($meta_description);
                $meta_description = substr($meta_description, 0, 160);
            }
        }
        
        // Return the content and meta information
        wp_send_json_success(array(
            'title' => $title,
            'content' => $blocks_content,
            'meta_title' => $auto_generate_meta && !is_wp_error($meta_title) ? $meta_title : '',
            'meta_description' => $auto_generate_meta && !is_wp_error($meta_description) ? $meta_description : ''
        ));
    } else {
        wp_send_json_error(array('message' => 'Failed to generate content'));
    }
}



// Save Meta Box Data when the Post is Saved
function diyseo_save_post_meta_box_data($post_id) { // Updated function name
    // Check if our nonce is set and verify it
    if (!isset($_POST['diyseo_meta_box_nonce']) || 
    !wp_verify_nonce(sanitize_key($_POST['diyseo_meta_box_nonce']), 'diyseo_meta_box_nonce')) {
    return;
}

    // Check if this is an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Sanitize and save fields
    if (isset($_POST['diyseo_post_title'])) {
        update_post_meta($post_id, '_diyseo_post_title', sanitize_text_field(wp_unslash($_POST['diyseo_post_title'])));
    }
    if (isset($_POST['diyseo_post_date'])) {
        update_post_meta($post_id, '_diyseo_post_date', sanitize_text_field(wp_unslash($_POST['diyseo_post_date'])));
    }
    if (isset($_POST['diyseo_post_category'])) {
        update_post_meta($post_id, '_diyseo_post_category', intval($_POST['diyseo_post_category']));
    }
    if (isset($_POST['diyseo_word_count'])) {
        update_post_meta($post_id, '_diyseo_word_count', intval($_POST['diyseo_word_count']));
    }

    // If the "Generate Article" button was clicked, generate the content
    if (isset($_POST['diyseo_generate_article'])) {
        diyseo_generate_custom_article($post_id);
    }
}
add_action('save_post', 'diyseo_save_post_meta_box_data'); // Updated hook with new function name

// Function to generate custom article content
function diyseo_generate_custom_article($post_id) {
    // Get post meta values
    $post_title = get_post_meta($post_id, '_diyseo_post_title', true);
    $word_count_requested = get_post_meta($post_id, '_diyseo_word_count', true);

    // Define the prompt for content generation

    $prompt = "Use html format. Write as if you are in between html body tags. Please avoid using overly formal or complex language and use an authoritative, conversational tone.Please be extremly lengthy. Use every detail possible. Use this template: Introduction:400 Words.[New Section]:600 Words. [New Section]:600 Words. [New Section]:600 Words. [New Section]:600 Words. [Conclusion]:400 Words. Search the web if necessary. Don't include a <html>, <head>, or <body> tag. All I need are h tags and p tags. Write the article about " . $post_title . " with approximately " . $word_count_requested . " words.";

    // Generate the content using OpenAI API
    $response = diyseo_generate_content($prompt);

    if (!is_wp_error($response)) {
        // Update post content
        $post_data = array(
            'ID'           => $post_id,
            'post_content' => wp_kses_post($response),
        );
        //wp_update_post($post_data);

        return $response; // Ensure the content is returned
    }

    return ''; // Return empty string if there is an error
}


//GENERATE META


// Add Meta Boxes for Meta Description and Meta Title
function diyseo_add_meta_boxes() {
    $post_types = array('post', 'page');
    
    foreach ($post_types as $post_type) {
         add_meta_box(
            'diyseo_meta_title_box',
            'DIY SEO Meta Title Generator',
            'diyseo_meta_title_box_callback',
            $post_type,
            'normal',
            'default'
        );
        add_meta_box(
            'diyseo_meta_description_box',
            'DIY SEO Meta Description Generator',
            'diyseo_meta_description_box_callback',
            $post_type,
            'normal',
            'default'
        );
       
    }
}
add_action('add_meta_boxes', 'diyseo_add_meta_boxes');

// Modify the AJAX handler for generating meta description
function diyseo_ajax_generate_meta_description() {
    check_ajax_referer('diyseo_generate_meta_description', 'nonce');
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $post = get_post($post_id);
    
    $post_title = $post->post_title;
    $post_content = wp_strip_all_tags($post->post_content);
    
    $prompt = "Generate a compelling 150-160 character SEO meta description for an article titled '{$post_title}'. 
    Here's a summary of the article content to help with context: " . substr($post_content, 0, 300) . "...
    
    The meta description should be engaging, include relevant keywords, and accurately summarize the article's main points.";
    
    $meta_description = diyseo_generate_content($prompt);
    
    if (!is_wp_error($meta_description)) {
        $meta_description = str_replace('"', '', $meta_description);
        $meta_description = wp_strip_all_tags($meta_description);
        $meta_description = trim($meta_description);

        update_post_meta($post_id, '_diyseo_meta_description', $meta_description);

        delete_post_meta($post_id, '_yoast_wpseo_metadesc');
        delete_post_meta($post_id, '_yoast_wpseo_opengraph-description');
        delete_post_meta($post_id, '_yoast_wpseo_twitter-description');

        wp_send_json_success($meta_description);
    } else {
        wp_send_json_error('Failed to generate meta description.');
    }
}
add_action('wp_ajax_diyseo_generate_meta_description', 'diyseo_ajax_generate_meta_description');

// Modify the AJAX handler for generating meta title
function diyseo_ajax_generate_meta_title() {
    check_ajax_referer('diyseo_generate_meta_title', 'nonce');
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $post = get_post($post_id);
    
    // Get the actual post content and title
    $post_title = $post->post_title;
    $post_content = wp_strip_all_tags($post->post_content);
    
    $prompt = "Generate a compelling SEO meta title for an article about {$post_title}. 
    Here's a summary of the article content to help with context: " . substr($post_content, 0, 300) . "...
    
    The meta title should be catchy, include relevant keywords, and accurately represent the article content.";
    
    $meta_title = diyseo_generate_content($prompt);
    
    if (!is_wp_error($meta_title)) {
        $meta_title = str_replace('"', '', $meta_title);
        $meta_title = wp_strip_all_tags($meta_title);
        $meta_title = trim($meta_title);

        update_post_meta($post_id, '_diyseo_meta_title', $meta_title);

        delete_post_meta($post_id, '_yoast_wpseo_title');
        delete_post_meta($post_id, '_yoast_wpseo_opengraph-title');
        delete_post_meta($post_id, '_yoast_wpseo_twitter-title');

        wp_send_json_success($meta_title);
    } else {
        wp_send_json_error('Failed to generate meta title.');
    }
}

add_action('wp_ajax_diyseo_generate_meta_title', 'diyseo_ajax_generate_meta_title');

// Add a function to display the saved meta information in the meta boxes
function diyseo_display_saved_meta($post) {
    $meta_description = get_post_meta($post->ID, '_diyseo_meta_description', true);
    $meta_title = get_post_meta($post->ID, '_diyseo_meta_title', true);
    
    if ($meta_description) {
        echo '<p><strong>Saved Meta Description:</strong> ' . esc_html($meta_description) . '</p>';
    }
    
    if ($meta_title) {
        echo '<p><strong>Saved Meta Title:</strong> ' . esc_html($meta_title) . '</p>';
    }
}
function diyseo_meta_description_box_callback($post) {
    wp_nonce_field('diyseo_save_meta_description', 'diyseo_meta_description_nonce');
    $meta_description = get_post_meta($post->ID, '_diyseo_meta_description', true);
    $has_license = diyseo_get_license_key();

    if (!$has_license) {
        echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #12163A; border-radius: 10px; border-left: 4px solid #ea188a;">
            <p style="color: #E0E0E0;">Get optimized meta descriptions and unlimited article generations with a license key from:</p>
            <a href="https://diyseo.ai" target="_blank" style="display: inline-block; background-color: #ea188a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 10px;">Get Your License Key</a>
        </div>';
    }
    ?>

    <div style="
        background-color: #080B53;
        padding: 24px;
        color: #E0E0E0;
        border-radius: 18px;
        font-family: 'Segoe UI', Roboto, sans-serif;
        border: 1px solid rgba(255, 255, 255, 0.05);
        box-shadow: 0 6px 24px rgba(0,0,0,0.4);
    ">

        <label for="diyseo_meta_description" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 8px;">
            🧾 Meta Description:
        </label>

        <textarea id="diyseo_meta_description" name="diyseo_meta_description" rows="3"
            style="
                width: 100%;
                padding: 12px;
                border-radius: 10px;
                border: 1px solid rgba(255,255,255,0.1);
                background-color: #12163A;
                color: #ffffff;
                font-size: 14px;
                resize: vertical;
                transition: border 0.3s ease;
            "
            placeholder="e.g. Learn how to generate SEO-optimized meta descriptions using AI…"><?php echo esc_textarea($meta_description); ?></textarea>

        <div style="margin-top: 16px;">
            <button type="button" id="diyseo_generate_meta_description" class="button button-primary"
                style="
                    background-color: #3b82f6;
                    color: #ffffff;
                    padding: 10px 20px;
                    font-weight: 600;
                    border: none;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: background-color 0.2s ease;
                "
                onmouseover="this.style.backgroundColor='#2563eb'"
                onmouseout="this.style.backgroundColor='#3b82f6'"
            >
                ✨ Generate Meta Description
            </button>

            <span id="diyseo_meta_description_loading" style="display: none; color: #60a5fa; margin-left: 10px; font-weight: 500;">
                Generating...
            </span>
        </div>

        <div style="margin-top: 20px; color: #93c5fd; font-size: 13px;">
            <?php diyseo_display_saved_meta($post); ?>
        </div>

        <?php 
        $logo_id = get_option('diyseo_logo_id');
        if ($logo_id) {
            echo wp_get_attachment_image(
                $logo_id,
                array(300, 150),
                false,
                array(
                    'alt' => 'DIY SEO Logo',
                    'style' => 'margin: 28px auto 20px; display: block; opacity: 1;'
                )
            );
        }
        ?>

        <div style="
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 13px;
            color: #cbd5e1;
        ">
            Page refreshes upon clicking generate.<br>💾 Save a draft of your post before continuing.<br>
            <a href="https://diyseo.ai/diyseo-user-feedback/" style="color: #93c5fd;">Feedback</a>
        </div>
    </div>

    <?php
    wp_enqueue_script('diyseo-meta-description', plugin_dir_url(__FILE__) . 'diyseo-meta-description.js', array('jquery'), '1.0.0', true);

    $script_data_array = array(
        'postId' => $post->ID,
        'nonce' => wp_create_nonce('diyseo_generate_meta_description')
    );
    wp_localize_script('diyseo-meta-description', 'diyseoMetaParams', $script_data_array);
}


function diyseo_meta_title_box_callback($post) {
    wp_nonce_field('diyseo_save_meta_title', 'diyseo_meta_title_nonce');
    $meta_title = get_post_meta($post->ID, '_diyseo_meta_title', true);
    $has_license = diyseo_get_license_key();

    if (!$has_license) {
        echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #12163A; border-radius: 10px; border-left: 4px solid #ea188a;">
            <p style="color: #E0E0E0;">Get optimized meta titles and unlimited article generations with a license key from:</p>
            <a href="https://diyseo.ai" target="_blank" style="display: inline-block; background-color: #ea188a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 10px;">Get Your License Key</a>
        </div>';
    }
    ?>

    <div style="
        background-color: #080B53;
        padding: 24px;
        color: #E0E0E0;
        border-radius: 18px;
        font-family: 'Segoe UI', Roboto, sans-serif;
        border: 1px solid rgba(255,255,255,0.05);
        box-shadow: 0 6px 24px rgba(0,0,0,0.4);
    ">

        <label for="diyseo_meta_title" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 8px;">
            🏷️ Meta Title:
        </label>

        <input type="text" id="diyseo_meta_title" name="diyseo_meta_title"
            value="<?php echo esc_attr($meta_title); ?>"
            placeholder="e.g. Top 10 SEO Tools for Small Businesses"
            style="
                width: 100%;
                padding: 12px;
                border-radius: 10px;
                border: 1px solid rgba(255,255,255,0.1);
                background-color: #12163A;
                color: #ffffff;
                font-size: 14px;
                margin-bottom: 16px;
            ">

        <button type="button" id="diyseo_generate_meta_title" class="button button-primary"
            style="
                background-color: #3b82f6;
                color: #ffffff;
                padding: 10px 20px;
                font-weight: 600;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                transition: background-color 0.2s ease;
            "
            onmouseover="this.style.backgroundColor='#2563eb'"
            onmouseout="this.style.backgroundColor='#3b82f6'"
        >
            ✨ Generate Meta Title
        </button>

        <span id="diyseo_meta_title_loading" style="display: none; color: #60a5fa; margin-left: 10px; font-weight: 500;">
            Generating...
        </span>

        <div style="margin-top: 20px; color: #93c5fd; font-size: 13px;">
            <?php diyseo_display_saved_meta($post); ?>
        </div>

        <?php 
        $logo_id = get_option('diyseo_logo_id');
        if ($logo_id) {
            echo wp_get_attachment_image(
                $logo_id,
                array(300, 150),
                false,
                array(
                    'alt' => 'DIY SEO Logo',
                    'style' => 'margin: 28px auto 20px; display: block; opacity: 1;'
                )
            );
        }
        ?>

        <div style="
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 13px;
            color: #cbd5e1;
        ">
            Page refreshes upon clicking generate.<br>💾 Be sure to save your post before generating.<br>
            <a href="https://diyseo.ai/diyseo-user-feedback/" style="color: #93c5fd;">Feedback</a>
        </div>
    </div>

    <?php
    wp_enqueue_script('diyseo-meta-title', plugin_dir_url(__FILE__) . 'diyseo-meta-title.js', array('jquery'), '1.0.0', true);

    $script_data_array = array(
        'postId' => $post->ID,
        'nonce' => wp_create_nonce('diyseo_generate_meta_title')
    );
    wp_localize_script('diyseo-meta-title', 'diyseoMetaTitleParams', $script_data_array);
}





//END GENERATE META




function diyseo_add_branding() {
    echo "<!-- This site is optimized with the DIYSEO SEO AI Content Writer plugin v1.0 - https://diyseo.ai/wordpress/ -->\n";
}

add_action('wp_head', 'diyseo_add_branding', 0);













// Add Meta Box for Featured Image Generation
function diyseo_add_featured_image_meta_box() {
    add_meta_box(
        'diyseo_featured_image_box',
        'DIY SEO Featured Image Generator',
        'diyseo_featured_image_box_callback',
        array('post', 'page'), // Changed from just 'post' to array of post types
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'diyseo_add_featured_image_meta_box');
// Add this function to enforce meta box order
function diyseo_force_meta_box_order() {
    global $wp_meta_boxes;

    $targets = array(
        'diyseo_calendar_meta_box',
        'diyseo_meta_title_box',
        'diyseo_meta_description_box',
        'diyseo_faq_box'
    );

    foreach (array('post', 'page') as $post_type) {
        if (!isset($wp_meta_boxes[$post_type]['normal'])) {
            continue;
        }

        foreach (array('high', 'default') as $priority) {
            if (!isset($wp_meta_boxes[$post_type]['normal'][$priority])) {
                continue;
            }

            $existing_boxes = $wp_meta_boxes[$post_type]['normal'][$priority];
            $ordered_boxes = array();

            foreach ($targets as $box_id) {
                if (isset($existing_boxes[$box_id])) {
                    $ordered_boxes[$box_id] = $existing_boxes[$box_id];
                    unset($existing_boxes[$box_id]);
                }
            }

            // Merge our prioritized boxes with the remaining boxes to preserve everything else
            $wp_meta_boxes[$post_type]['normal'][$priority] = $ordered_boxes + $existing_boxes;
        }
    }
}

// Hook into the appropriate action
add_action('add_meta_boxes_post', 'diyseo_force_meta_box_order', 999);

// Also clear user meta box order preferences
function diyseo_reset_user_meta_box_order() {
    $user_id = get_current_user_id();
    delete_user_meta($user_id, 'meta-box-order_post');
    delete_user_meta($user_id, 'closedpostboxes_post');
    delete_user_meta($user_id, 'metaboxhidden_post');
}
add_action('admin_init', 'diyseo_reset_user_meta_box_order');

function diyseo_ajax_generate_featured_image() {
    $nonce = diyseo_get_post_data('nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'diyseo_generate_featured_image')) {
        wp_send_json_error('Nonce verification failed');
        return;
    }

    $prompt = diyseo_get_post_data('prompt');
    if (!$prompt) {
        wp_send_json_error('No prompt provided');
        return;
    }

    $image_data = diyseo_generate_dalle_image($prompt);
    if (is_wp_error($image_data)) {
        wp_send_json_error($image_data->get_error_message());
    } elseif ($image_data && isset($image_data['url']) && isset($image_data['id'])) {
        wp_send_json_success($image_data);
    } else {
        wp_send_json_error('Failed to generate and save the image.');
    }
}
add_action('wp_ajax_diyseo_generate_featured_image', 'diyseo_ajax_generate_featured_image');

function diyseo_ajax_confirm_featured_image() {
    $nonce = diyseo_get_post_data('nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'diyseo_confirm_featured_image')) {
        wp_send_json_error('Nonce verification failed');
        return;
    }

    $post_id = intval(diyseo_get_post_data('post_id'));
    $attachment_id = intval(diyseo_get_post_data('attachment_id'));

    if ($post_id === 0) {
       //("DIYSEO: Invalid post ID: $post_id");
        wp_send_json_error('Invalid post ID');
        return;
    }

    if ($attachment_id === 0) {
       //("DIYSEO: Invalid attachment ID: $attachment_id");
        wp_send_json_error('Invalid attachment ID');
        return;
    }

    // Verify that the post exists
    $post = get_post($post_id);
    if (!$post) {
       //("DIYSEO: Post not found for ID: $post_id");
        wp_send_json_error('Post not found');
        return;
    }

    // Verify that the attachment exists
    $attachment = get_post($attachment_id);
    if (!$attachment) {
       //("DIYSEO: Attachment not found for ID: $attachment_id");
        wp_send_json_error('Attachment not found');
        return;
    }

    $result = set_post_thumbnail($post_id, $attachment_id);
    if ($result) {
        $image_url = wp_get_attachment_image_url($attachment_id, 'full');
       //("DIYSEO: Successfully set featured image. Post ID: $post_id, Attachment ID: $attachment_id");
        wp_send_json_success(array(
            'url' => $image_url,
            'attachment_id' => $attachment_id
        ));
    } else {
       //("DIYSEO: Failed to set post thumbnail. Post ID: $post_id, Attachment ID: $attachment_id");
        wp_send_json_error('Failed to set featured image.');
    }
}

add_action('wp_ajax_diyseo_confirm_featured_image', 'diyseo_ajax_confirm_featured_image');
function diyseo_ajax_save_to_media() {
    check_ajax_referer('diyseo_save_to_media', 'nonce');
    
$image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';

    if (empty($image_url)) {
        wp_send_json_error('No image URL provided');
        return;
    }

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Download image and save to media library
    $attachment_id = media_sideload_image($image_url, 0, null, 'id');
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error($attachment_id->get_error_message());
        return;
    }

    wp_send_json_success(array(
        'attachment_id' => $attachment_id,
        'url' => wp_get_attachment_url($attachment_id)
    ));
}
add_action('wp_ajax_diyseo_save_to_media', 'diyseo_ajax_save_to_media');
// Update the meta box callback function
function diyseo_featured_image_box_callback($post) {
    wp_nonce_field('diyseo_generate_featured_image', 'diyseo_featured_image_nonce');
    ?>
    <div style="
        position: relative;
        overflow: hidden;
        border-radius: 18px;
        padding: 24px;
        background: linear-gradient(135deg, #0f172a, #1e293b);
        color: #f9fafb;
        font-family: 'Segoe UI', Roboto, sans-serif;
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    ">
        <!-- Animated Aurora SVG Background -->
        <svg style="position: absolute; top: -25%; left: -25%; width: 150%; height: 150%; z-index: 0;" viewBox="0 0 800 600" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <radialGradient id="aurora" cx="50%" cy="50%" r="50%">
                    <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.2"/>
                    <stop offset="100%" stop-color="#9333ea" stop-opacity="0"/>
                </radialGradient>
            </defs>
            <circle cx="400" cy="300" r="400" fill="url(#aurora)">
                <animateTransform attributeName="transform" type="rotate" values="0 400 300;360 400 300" dur="60s" repeatCount="indefinite"/>
            </circle>
        </svg>

        <!-- Foreground Content -->
        <div style="position: relative; z-index: 1;">

            <label for="diyseo_image_prompt" style="display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px;">
                🖼️ Describe Your Image
            </label>

            <textarea id="diyseo_image_prompt" name="diyseo_image_prompt" rows="3" placeholder="e.g. A neon-lit startup office at night"
                style="
                    width: 100%;
                    padding: 12px;
                    border-radius: 10px;
                    border: 1px solid rgba(255,255,255,0.12);
                    background: rgba(17,24,39,0.9);
                    color: #f9fafb;
                    font-size: 14px;
                    resize: vertical;
                "
            ></textarea>

            <div style="margin-top: 16px;">
                <button type="button" id="diyseo_generate_featured_image" class="button button-primary"
                    style="
                        background-color: #3b82f6;
                        color: #ffffff;
                        border: none;
                        padding: 10px 16px;
                        font-size: 14px;
                        font-weight: 600;
                        border-radius: 8px;
                        cursor: pointer;
                        transition: background-color 0.2s ease;
                    "
                    onmouseover="this.style.backgroundColor='#2563eb'"
                    onmouseout="this.style.backgroundColor='#3b82f6'"
                >
                    🎨 Generate Image
                </button>

                <button type="button" id="diyseo_regenerate_image" class="button"
                    style="
                        display: none;
                        background-color: rgba(255,255,255,0.1);
                        color: #e0e0e0;
                        border: 1px solid rgba(255,255,255,0.15);
                        padding: 10px 16px;
                        font-size: 14px;
                        font-weight: 500;
                        border-radius: 8px;
                        margin-left: 10px;
                        cursor: pointer;
                    "
                >
                    🔁 Generate New Image
                </button>

                <span id="diyseo_featured_image_loading" style="display: none; color: #93c5fd; margin-left: 12px; font-weight: 500;">
                    Generating...
                </span>
            </div>

            <div id="diyseo_featured_image_preview" style="margin-top: 20px;"></div>

            <!-- Loading Overlay -->
            <style>
                .image-loading-container {
                    position: fixed;
                    top: 0; left: 0;
                    width: 100%; height: 100%;
                    background: rgba(15, 23, 42, 0.9);
                    backdrop-filter: blur(6px);
                    display: none;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    z-index: 9999;
                }

                .loading-spinner {
                    width: 48px;
                    height: 48px;
                    border: 5px solid rgba(255,255,255,0.15);
                    border-top: 5px solid #3b82f6;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                    margin-bottom: 20px;
                }

                .loading-progress {
                    width: 200px;
                    height: 4px;
                    background: rgba(255,255,255,0.1);
                    border-radius: 2px;
                    margin-bottom: 16px;
                    overflow: hidden;
                }

                .progress-bar {
                    width: 0%;
                    height: 100%;
                    background: #3b82f6;
                    animation: progressBar 2.8s ease-in-out infinite;
                }

                .loading-status {
                    font-size: 16px;
                    color: #e2e8f0;
                    text-align: center;
                }

                @keyframes spin {
                    to { transform: rotate(360deg); }
                }

                @keyframes progressBar {
                    0% { width: 0%; }
                    50% { width: 75%; }
                    100% { width: 100%; }
                }
            </style>

            <div class="image-loading-container" id="imageLoadingContainer">
                <div class="loading-spinner"></div>
                <div class="loading-progress"><div class="progress-bar"></div></div>
                <div class="loading-status">
                    <p id="imageLoadingMessage">Generating your image…</p>
                    <p id="imageLoadingSubMessage" style="font-size: 13px; opacity: 0.7;"></p>
                </div>
            </div>

         <?php 
$logo_id = get_option('diyseo_logo_id');
if ($logo_id) {
    echo '<div style="
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 28px 0 16px;
    ">';
    echo wp_get_attachment_image(
        $logo_id, 
        array(300, 300), // keeps it balanced in sidebar
        false, 
        array(
            'alt' => 'DIY SEO Logo',
            'style' => '
                max-width: 100%;
                height: auto;
                display: block;
                filter: drop-shadow(0 0 3px rgba(255,255,255,0.1));
            '
        )
    );
    echo '</div>';
}
?>

            <div style="
                margin-top: 24px;
                padding-top: 12px;
                border-top: 1px solid rgba(255,255,255,0.08);
                font-size: 13px;
                color: #cbd5e1;
            ">
                All images are saved to your Media Library.<br>
                Default resolution is <strong>1024x1024</strong>. 
                <a href="https://diyseo.ai/diyseo-user-feedback/" style="color: #60a5fa; text-decoration: none;">Feedback</a>
            </div>
        </div>
    </div>

    <?php
    wp_enqueue_script('diyseo-featured-image', plugin_dir_url(__FILE__) . 'diyseo-featured-image.js', array('jquery'), '1.0.1', true);

    $script_data_array = array(
        'generateNonce' => wp_create_nonce('diyseo_generate_featured_image'),
        'ajaxurl' => admin_url('admin-ajax.php')
    );
    wp_localize_script('diyseo-featured-image', 'diyseoFeaturedImageParams', $script_data_array);
}

function diyseo_calendar_meta_box_callback($post) {
    wp_nonce_field('diyseo_save_calendar_meta_box_data', 'diyseo_calendar_meta_box_nonce');

    $has_license = diyseo_get_license_key();
    $remaining_generations = diyseo_get_remaining_generations();
    $sub_level = get_option('diyseo_user_sub');

    if (!$has_license) {
        echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #12163A; border-radius: 10px; border-left: 4px solid #ea188a;">
            <h3 style="color: #ea188a; margin-top: 0;">Product Not Activated</h3>
            <p style="color: #E0E0E0;">Unlock content generations and access to content calendars by obtaining a FREE license key (no card required) from: diyseo.ai/register</p>
            <a href="https://diyseo.ai/register" target="_blank" style="display: inline-block; background-color: #ea188a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 10px;">Get Your License Key</a>
        </div>';
        if ($remaining_generations == 0) {
            echo '<div style="margin-bottom: 20px; padding: 15px; background-color: #12163A; border-radius: 10px; border-left: 4px solid #ea188a;">
                <p style="color: #E0E0E0;">Monthly Free Article Generation Limit Reached</p>
            </div>';
            return;
        }
    }

    echo '<style>
        #diyseo_article_title option.completed-article {
            color: #28a745;
            font-weight: bold;
        }
        .completed-article-icon {
            color: #28a745;
            margin-right: 5px;
        }
    </style>';

    ?>

    <div style="background-color: #080B53; padding: 20px; color: #E0E0E0; border-radius: 15px;">

        <!-- Intro -->
        <div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            Use the DIYSEO AI Content Writer to write SEO content directly in WP. Add a custom title (or select one from your content calendar), set a publish date, and provide context to shape the output.
            <a href="https://diyseo.ai/diyseo-user-feedback/" style="color: #93c5fd;">Feedback</a>
        </div>

        <!-- Toggle -->
        <div style="margin-bottom: 20px;">
            <label class="switch" style="position: relative; display: inline-block; width: 80px; height: 40px;">
                <input type="checkbox" id="content_source_toggle" checked style="opacity: 0; width: 0; height: 0;">
                <span class="slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #808080; border-radius: 40px; transition: .4s;">
                    <span class="slider-before" style="position: absolute; height: 32px; width: 32px; left: 4px; bottom: 4px; background-color: #FFFFFF; border-radius: 50%; transition: .4s; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></span>
                </span>
            </label>
            <span style="margin-left: 15px; font-size: 16px;">Use Custom Title</span>
        </div>

        <!-- Custom Title -->
        <div id="custom_title_field" style="margin-bottom: 15px;">
            <label for="diyseo_custom_title">Custom Article Title:</label><br>
            <input type="text" id="diyseo_custom_title" name="diyseo_custom_title" placeholder="Enter your article title"
                   style="width: 100%; padding: 10px; border-radius: 5px; background-color: #12163A; color: #E0E0E0; border: none; box-shadow: 0px 0px 5px #ffffff;">
        </div>

        <!-- Calendar Fields -->
        <div id="calendar_fields" style="display: none;">
            <div id="no_calendar_message" style="display: none; margin-bottom: 15px; padding: 20px; background-color: #12163A; border-radius: 10px;">
                <h3 style="color: #ea188a; margin-top: 0;">No Content Calendar Found</h3>
                <p style="margin-bottom: 15px;">DIYSEO Content Calendars let you:</p>
                <ul style="margin-bottom: 15px; list-style-type: none; padding-left: 0;">
                    <li style="margin-bottom: 8px;">✓ Plan your strategy</li>
                    <li style="margin-bottom: 8px;">✓ Schedule content</li>
                    <li style="margin-bottom: 8px;">✓ Organize by category</li>
                </ul>
                <a href="https://diyseo.ai" target="_blank" style="display: inline-block; background-color: #ea188a; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;">Create Your Content Calendar</a>
            </div>

            <?php
            $fields = [
                'diyseo_content_calendar' => 'Select Content Calendar:',
                'diyseo_category' => 'Category:',
                'diyseo_article_title' => 'Article Title:'
            ];
            foreach ($fields as $id => $label) {
                $field_id = esc_attr($id);
                $field_label = esc_html($label);
                echo '<div style="margin-bottom: 15px;">
                    <label for="'.esc_attr($field_id).'">'.esc_html($field_label).'</label><br>
                    <select id="'.esc_attr($field_id).'" name="'.esc_attr($field_id).'" style="width: 100%; padding: 10px; border-radius: 5px; background-color: #12163A; color: #E0E0E0; border: none;">
                        <option value="">Select</option>
                    </select>
                </div>';
            }
            ?>
        </div>

        <!-- Common Fields -->
        <div style="margin-bottom: 15px;">
            <label for="diyseo_post_date">Post Date:</label><br>
            <input type="date" id="diyseo_post_date" name="diyseo_post_date"
                   style="width: 100%; padding: 10px; border-radius: 5px; background-color: #12163A; color: #E0E0E0; border: none;">
        </div>

        <div style="margin-bottom: 15px;">
            <label for="diyseo_word_count">Word Count:</label><br>
            <select id="diyseo_word_count" name="diyseo_word_count"
                    style="width: 100%; padding: 10px; border-radius: 5px; background-color: #12163A; color: #E0E0E0; border: none;">
                <option value="750">~750 words</option>
                <option value="1200">~1200 words</option>
                <option value="1500">~1500 words</option>
                <option value="1750">~1750 words</option>
            </select>
        </div>

        <div style="margin-bottom: 15px;">
            <label for="diyseo_additional_context">Additional Context:</label><br>
            <textarea id="diyseo_additional_context" name="diyseo_additional_context" rows="4"
                      placeholder="Add any specific instructions for GPT"
                      style="width: 100%; padding: 10px; border-radius: 5px; background-color: #12163A; color: #E0E0E0; border: none;"></textarea>
        </div>

        <div style="margin-bottom: 15px;">
            <label for="diyseo_focus_keyword">Focus Keyword:</label><br>
            <input type="text" id="diyseo_focus_keyword" name="diyseo_focus_keyword" placeholder="e.g. content marketing"
                   style="width: 100%; padding: 10px; border-radius: 5px; background-color: #12163A; color: #E0E0E0; border: none; box-shadow: 0px 0px 5px #ffffff;">
        </div>

        <!-- CTA -->
        <button type="button" id="diyseo_generate_content" class="button button-primary"
                style="background-color: #2867ec; border: none; padding: 12px 20px; border-radius: 5px; color: #fff;">
            🚀 Generate Content
        </button>

        <!-- Loading Overlay -->
        <style>
            .article-loading-container {
                position: fixed;
                top: 0; left: 0;
                width: 100%; height: 100%;
                background: rgba(15, 23, 42, 0.9);
                backdrop-filter: blur(6px);
                display: none;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            }

            .loading-spinner {
                width: 48px;
                height: 48px;
                border: 5px solid rgba(255,255,255,0.15);
                border-top: 5px solid #3b82f6;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin-bottom: 20px;
            }

            .loading-progress {
                width: 200px;
                height: 4px;
                background: rgba(255,255,255,0.1);
                border-radius: 2px;
                margin-bottom: 16px;
                overflow: hidden;
            }

            .progress-bar {
                width: 0%;
                height: 100%;
                background: #3b82f6;
                animation: progressBar 2.8s ease-in-out infinite;
            }

            .loading-status {
                font-size: 16px;
                color: #e2e8f0;
                text-align: center;
            }

            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            @keyframes progressBar {
                0% { width: 0%; }
                50% { width: 75%; }
                100% { width: 100%; }
            }
        </style>

        <div class="article-loading-container" id="articleLoadingContainer">
            <div class="loading-spinner"></div>
            <div class="loading-progress"><div class="progress-bar"></div></div>
            <div class="loading-status">
                <p id="loadingMessage">Generating your content…</p>
                <p id="loadingSubMessage" style="font-size: 13px; opacity: 0.7;"></p>
            </div>
        </div>

        <!-- Footer -->
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); color: #E0E0E0;">
            Next, generate a Meta Title and Meta Description, and consider using the AI Image Generator.
            <div style="margin-top: 10px;">
                <a href="https://diyseo.ai/marketplace/" target="_blank" style="color: #60a5fa; text-decoration: none; margin-right: 15px;">Marketplace</a> |
                <a href="https://diyseo.ai/diyseo-user-feedback/" target="_blank" style="color: #60a5fa; text-decoration: none; margin-left: 15px;">Feedback</a>
            </div>
        </div>
    </div>

    <?php
}
function diyseo_refine_image_prompt($initial_prompt) {
    // Simplified but focused prompt structure
    $refined_prompt = implode(" ", [
        // Core style setting - specifically targeting modern vector look
        "Create a modern, vector-style digital illustration with clean lines and smooth gradients.",
        
        // Original prompt
        $initial_prompt . ".",
        
        // Quality and style specifics
        "Use a modern, professional vector art style similar to contemporary commercial illustrations.",
        
        // Color and rendering guidance
        "Employ smooth color transitions, subtle shadows, and professional lighting.",
        
        
    ]);
    
    return $refined_prompt;
}
// Function to generate image using DALL-E API
function diyseo_generate_dalle_image($prompt) {
    $api_key = diyseo_get_api_key();
    if (!$api_key) {
        return new WP_Error('api_key_error', 'Failed to retrieve API key. Please check your license key or try refreshing the page.');
    }

    $enhanced_prompt = diyseo_refine_image_prompt($prompt);
    $enhanced_prompt .= " Make sure the full title is clearly visible, centered, and not cut off. Use generous padding around the text and avoid placing it near the edges. Reserve space in the layout so no part of the text is clipped or obscured. Poster should look clean and readable.";

    $url  = 'https://api.openai.com/v1/images/generations';
    $args = array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => wp_json_encode(
            array(
                'prompt'  => $enhanced_prompt,
                'n'       => 1,
                'size'    => '1536x1024',
                'quality' => 'medium',
                'model'   => 'gpt-image-1',
            )
        ),
        'timeout' => 300,
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        return new WP_Error('remote_post_error', $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    if (!$body) {
        return new WP_Error('empty_body', 'Empty response body.');
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_decode_error', 'Failed to decode JSON: ' . json_last_error_msg());
    }

    if (!isset($data['data'][0]['b64_json'])) {
        return new WP_Error('no_image_data', 'DALL-E did not return image data.');
    }

    $image_data = base64_decode($data['data'][0]['b64_json']);
    if ($image_data === false) {
        return new WP_Error('base64_decode_error', 'Failed to decode image.');
    }

    $upload_dir = wp_upload_dir();
    if (!empty($upload_dir['error'])) {
        return new WP_Error('upload_dir_error', $upload_dir['error']);
    }
    $filename = 'diyseo-image-' . time() . '.png';
    $file     = trailingslashit($upload_dir['path']) . $filename;

    // Initialize WP_Filesystem
    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!WP_Filesystem()) {
        return new WP_Error('filesystem_init_error', 'Failed to initialize WP_Filesystem.');
    }

    global $wp_filesystem;
    if (!$wp_filesystem->put_contents($file, $image_data)) {
        return new WP_Error('file_write_error', 'Unable to write image file.');
    }

    $wp_filetype = wp_check_filetype($filename, null);
    $attachment  = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    $attach_id  = wp_insert_attachment($attachment, $file);
    if (is_wp_error($attach_id)) {
        return new WP_Error('insert_attachment_error', 'Failed to insert attachment.');
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attach_id, $file);
    wp_update_attachment_metadata($attach_id, $attach_data);

    $local_url = wp_get_attachment_url($attach_id);
    if (!$local_url) {
        return new WP_Error('attachment_url_error', 'Could not retrieve attachment URL.');
    }

    return array('url' => $local_url, 'id' => $attach_id);
}






// Add FAQ Meta BoxO camt evemt tjoml


// FAQ Meta Box Callback
function diyseo_faq_box_callback($post) {
    wp_nonce_field('diyseo_generate_faq', 'diyseo_faq_nonce');
    $faq_content = get_post_meta($post->ID, '_diyseo_faq_content', true);

    // Clean display version of saved content
    $display_content = str_replace(
        ['<h2>', '</h2>', '<p>', '</p>', '<div>', '</div>', '<h3>', '</h3>'], 
        ['', '', '', "\n", '', '', '', ''], 
        $faq_content
    );
    ?>
    <div style="
        background-color: #080B53;
        padding: 24px;
        color: #E0E0E0;
        border-radius: 18px;
        font-family: 'Segoe UI', Roboto, sans-serif;
        border: 1px solid rgba(255,255,255,0.05);
        box-shadow: 0 6px 24px rgba(0,0,0,0.4);
    ">

        <p style="margin-bottom: 18px; font-size: 15px; color: #CBD5E1;">
            Generate a helpful FAQ section based on your article. It will be appended at the end in a search-optimized format.
        </p>

        <label for="diyseo_faq_content" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 8px;">
            🙋 FAQ Content:
        </label>

        <textarea id="diyseo_faq_content" name="diyseo_faq_content" rows="10"
            style="
                width: 100%;
                padding: 12px;
                border-radius: 10px;
                border: 1px solid rgba(255,255,255,0.1);
                background-color: #12163A;
                color: #ffffff;
                font-size: 14px;
                margin-bottom: 16px;
                font-family: inherit;
            "
        ><?php echo esc_textarea($display_content); ?></textarea>

        <button type="button" id="diyseo_generate_faq" class="button button-primary"
            style="
                background-color: #22d3ee;
                color: #080B53;
                padding: 10px 20px;
                font-weight: 600;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                transition: background-color 0.2s ease;
            "
            onmouseover="this.style.backgroundColor='#06b6d4'"
            onmouseout="this.style.backgroundColor='#22d3ee'"
        >
            ➕ Generate FAQ Section
        </button>

        <span id="diyseo_faq_loading" style="display: none; color: #22d3ee; margin-left: 12px; font-weight: 500;">
            Generating FAQs...
        </span>

        <?php 
        $logo_id = get_option('diyseo_logo_id');
        if ($logo_id) {
            echo wp_get_attachment_image(
                $logo_id, 
                array(300, 150),
                false, 
                array(
                    'alt' => 'DIY SEO Logo',
                    'style' => 'margin-top: 30px; display: block; margin-left: auto; margin-right: auto;'
                )
            );
        }
        ?>

        <div style="
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 13px;
            color: #cbd5e1;
        ">
            ✅ FAQs will be injected in a schema-compatible format.<br>
            <a href="https://diyseo.ai/diyseo-user-feedback/" style="color: #22d3ee;">Send Feedback</a>
        </div>
    </div>

    <?php
    wp_enqueue_script('diyseo-faq-generator', plugin_dir_url(__FILE__) . 'js/diyseo-faq.js', array('jquery'), '1.0.0', true);
    wp_localize_script('diyseo-faq-generator', 'diyseoFaqParams', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('diyseo_generate_faq')
    ));
}


// AJAX handler for FAQ generation
function diyseo_ajax_generate_faq() {
    check_ajax_referer('diyseo_generate_faq', 'nonce');
    
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $post = get_post($post_id);
    
    if (!$post) {
        wp_send_json_error('Post not found');
        return;
    }

    $title = $post->post_title;
    $content = wp_strip_all_tags($post->post_content);

    $prompt = "Generate 5 frequently asked questions and detailed answers about this topic: '{$title}'. 
    start with 'Frequently Asked Questions' Use the following content as context: '" . substr($content, 0, 500) . "Format: Write each question followed by its answer.Use html format. Write as if you are in between html body tags. Please avoid using overly formal or complex language and use an authoritative, conversational tone.Please be extremly lengthy. Use every detail possible.
6. Format Guidelines:
- Use <h4> tags for section headers
- Use <p> tags for paragraphs
- Do not include doctype, html, head, or body tags
- Do not include any raw 'html' text in the output
- Do not include word count markerss";

    $raw_faq_content = diyseo_generate_content($prompt);

    if (!is_wp_error($raw_faq_content)) {
        // Format the raw content into HTML
        $formatted_content = $raw_faq_content;
        
        // Store the formatted FAQ content as post meta
        update_post_meta($post_id, '_diyseo_faq_content', $formatted_content);

        // Create the schema markup
        $faq_schema = diyseo_generate_faq_schema($formatted_content);
        update_post_meta($post_id, '_diyseo_faq_schema', $faq_schema);

        wp_send_json_success(array(
            'content' => $formatted_content,
            'schema' => $faq_schema
        ));
    } else {
        wp_send_json_error('Failed to generate FAQ content');
    }
}

// Add this new function to handle the formatting
function diyseo_format_faq_content($raw_content) {
    // Split the content into QA pairs
    $lines = explode("\n", $raw_content);
    $html = '<div class="faq-section">';
    
    $in_answer = false;
    $current_qa = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === '0') {
            continue;
        }
        
        // If line starts with a number or "Q:", it's a question
        if (preg_match('/^(\d+\.|\Q:|Question:)/i', $line)) {
            // If we were building a previous QA pair, close it
            if ($current_qa !== '' && $current_qa !== '0') {
                $html .= $current_qa . '</div>';
            }
            
            // Start new QA pair
            $current_qa = '<div class="faq-item">';
            // Clean up the question format
            $question = preg_replace('/^(\d+\.|\Q:|Question:)\s*/i', '', $line);
            $current_qa .= '<h3 class="faq-question">' . esc_html($question) . '</h3>';
            $in_answer = true;
        } elseif (preg_match('/^(A:|Answer:)/i', $line)) {
            // If line starts with "A:", it's the start of an answer
            $answer = preg_replace('/^(A:|Answer:)\s*/i', '', $line);
            $current_qa .= '<p class="faq-answer">' . esc_html($answer);
        } elseif ($in_answer) {
            // Continue building the answer
            $current_qa .= ' ' . esc_html($line);
        }
    }
    
    // Close the last QA pair if exists
    if ($current_qa !== '' && $current_qa !== '0') {
        $html .= $current_qa . '</p></div>';
    }
    
    return $html . '</div>';
}
add_action('wp_ajax_diyseo_generate_faq', 'diyseo_ajax_generate_faq');

function diyseo_generate_faq_schema($faq_html) {
    $dom = new DOMDocument();

    libxml_use_internal_errors(true); // prevent warnings on broken HTML
    $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $faq_html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Match all <h4> elements as questions
    $questions = $xpath->query('//h4');
    $faq_items = [];

    foreach ($questions as $question) {
        $question_text = trim($question->textContent);

        // Find the first <p> that comes after this <h4>
        $next = $question->nextSibling;
        while ($next && ($next->nodeType !== XML_ELEMENT_NODE || strtolower($next->nodeName) !== 'p')) {
            $next = $next->nextSibling;
        }

        if ($next && strtolower($next->nodeName) === 'p') {
            $answer_text = trim($next->textContent);

            if (!empty($question_text) && !empty($answer_text)) {
                $faq_items[] = [
                    '@type' => 'Question',
                    'name' => $question_text,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer_text
                    ]
                ];
            }
        }
    }

    return json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faq_items
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
add_action('wp_footer', 'diyseo_output_faq_schema', 100);
function diyseo_output_faq_schema() {
    if (is_singular()) {
        global $post;
        $schema = get_post_meta($post->ID, '_diyseo_faq_schema', true);
        
        if (!empty($schema)) {
            $schema_data = json_decode($schema, true);
            // Determine exactly what data we are encoding
            $data_to_print = $schema_data ? $schema_data : $schema;

            // FIX: Split the output so the variable is handled by an escaping function at the moment of echo
            if ( $data_to_print ) {
                echo '<script type="application/ld+json">';
                echo wp_json_encode( $data_to_print );
                echo '</script>';
            }
        }
    }
}


// Add FAQ content and schema to the post content
function diyseo_add_faq_to_content($content) {
    // Only add FAQs if we're viewing a singular post/page and the content doesn't already contain FAQ content
    if (is_singular() && in_the_loop() && is_main_query()) {
        $post_id = get_the_ID();
        $faq_content = get_post_meta($post_id, '_diyseo_faq_content', true);
        $faq_schema = get_post_meta($post_id, '_diyseo_faq_schema', true);
        
        // Check if the content already contains the FAQ section
        if ($faq_content && strpos($content, 'Frequently Asked Questions') === false) {
            $content .= $faq_content;
            
            if ($faq_schema) {
                $content .= '<script type="application/ld+json">' . wp_json_encode($faq_schema) . '</script>';
            }
        }
    }
    
    return $content;
}
add_filter('the_content', 'diyseo_add_faq_to_content');
function diyseo_save_faq_content($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!isset($_POST['diyseo_faq_nonce']) || 
        !wp_verify_nonce(sanitize_key($_POST['diyseo_faq_nonce']), 'diyseo_generate_faq')) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['diyseo_faq_content'])) {
        $content = wp_kses_post(wp_unslash($_POST['diyseo_faq_content']));
        update_post_meta($post_id, '_diyseo_faq_content', $content);
        
        // Regenerate schema when content is updated
        $faq_schema = diyseo_generate_faq_schema($content);
        update_post_meta($post_id, '_diyseo_faq_schema', $faq_schema);
    }
}
add_action('save_post', 'diyseo_save_faq_content');
function diyseo_add_faq_meta_box() {
    // Explicitly add to 'post' type
    add_meta_box(
        'diyseo_faq_box',
        'DIY SEO FAQ Generator',
        'diyseo_faq_box_callback',
        'post',
        'normal',
        'default'
    );
    
    // Also add to 'page' type
    add_meta_box(
        'diyseo_faq_box',
        'DIY SEO FAQ Generator',
        'diyseo_faq_box_callback',
        'page',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'diyseo_add_faq_meta_box');

// Make sure the meta box order is enforced
add_action('add_meta_boxes', 'diyseo_force_meta_box_order', 999);





function diyseo_convert_to_blocks($html_content, $title) {
    // Remove Word Count and Duplicate Title
    $html_content = preg_replace('/\[?Word(?:s)? Count:?\s*\d+\]?/i', '', $html_content);
    $html_content = preg_replace('/<h1[^>]*>' . preg_quote($title, '/') . '<\/h1>/i', '', $html_content);
    $html_content = preg_replace('/<h2[^>]*>' . preg_quote($title, '/') . '<\/h2>/i', '', $html_content);
    $html_content = preg_replace('/^' . preg_quote($title, '/') . '\s*/', '', $html_content);

    $blocks_content = '';
    $sections = preg_split('/<h2.*?>(.*?)<\/h2>/i', $html_content, -1, PREG_SPLIT_DELIM_CAPTURE);

    foreach ($sections as $index => $section) {
        if ($index % 2 === 0) {
            // Paragraph + Visual
            $paragraphs = preg_split('/<\/?p[^>]*>/', $section);
            foreach ($paragraphs as $p) {
                $p = trim($p);
                if ($p === '' || $p === '0') continue;

                if (preg_match('/^<ul[\s>]/i', $p)) {
                    $blocks_content .= "\n<!-- wp:list -->\n" . trim($p) . "\n<!-- /wp:list -->\n";
                } elseif (preg_match('/^<ol[\s>]/i', $p)) {
                    $blocks_content .= "\n<!-- wp:list {\"ordered\":true} -->\n" . trim($p) . "\n<!-- /wp:list -->\n";
                } elseif (preg_match('/<table[\s>]/i', $p)) {
                    // Ensure <tbody> is present
                    if (!preg_match('/<tbody>/i', $p)) {
                        $p = preg_replace('/<table([^>]*)>/i', '<table$1><tbody>', $p);
                        $p = preg_replace('/<\/table>/i', '</tbody></table>', $p);
                    }
                    // Wrap in figure
                    $table_block = '<figure class="wp-block-table">' . trim($p) . '</figure>';
                    $blocks_content .= "\n<!-- wp:table -->\n" . $table_block . "\n<!-- /wp:table -->\n";
                } else {
                    $blocks_content .= "<!-- wp:paragraph --><p>$p</p><!-- /wp:paragraph -->";
                }
            }
        } else {
            $section = trim($section);
            if ($section !== '' && $section !== $title) {
                $blocks_content .= '<!-- wp:heading --><h2>' . $section . '</h2><!-- /wp:heading -->';
            }
        }
    }

    return $blocks_content;
}


// Optional: Add support for custom block patterns
function diyseo_register_block_patterns() {
    if (function_exists('register_block_pattern')) {
        register_block_pattern(
            'diyseo/article-pattern',
            array(
                'title' => __('DIY SEO Article Pattern', 'diyseo-ai-powered-seo-content-generator'),
                'content' => '<!-- wp:heading --><h2>Introduction</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Introduction content</p><!-- /wp:paragraph -->',
                'categories' => array('text'),
                'description' => __('Standard article pattern with headings and paragraphs', 'diyseo-ai-powered-seo-content-generator'),
            )
        );
    }
}
add_action('init', 'diyseo_register_block_patterns');
// Add this function to diyseo.php


// If using Yoast SEO, you might want to use this alternative approach:



// Add this function to handle saving meta values
function diyseo_save_meta_values($post_id) {
    // If this is an autosave, don't do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check if our nonce is set and verify it
    // For meta title nonce
if (!isset($_POST['diyseo_meta_title_nonce']) || 
    !wp_verify_nonce(sanitize_key(wp_unslash($_POST['diyseo_meta_title_nonce'])), 'diyseo_save_meta_title')) {
    return;
}

// For meta description nonce
if (!isset($_POST['diyseo_meta_description_nonce']) || 
    !wp_verify_nonce(sanitize_key(wp_unslash($_POST['diyseo_meta_description_nonce'])), 'diyseo_save_meta_description')) {
    return;
}

    // Check user permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save meta title if it's set
    if (isset($_POST['diyseo_meta_title'])) {
          $meta_title = isset($_POST['diyseo_meta_title']) ? sanitize_text_field(wp_unslash($_POST['diyseo_meta_title'])) : '';
        update_post_meta($post_id, '_diyseo_meta_title', $meta_title);
    }

    // Save meta description if it's set
    if (isset($_POST['diyseo_meta_description'])) {
             $meta_description = isset($_POST['diyseo_meta_description']) ? sanitize_textarea_field(wp_unslash($_POST['diyseo_meta_description'])) : '';
            update_post_meta($post_id, '_diyseo_meta_description', $meta_description);
    }
}
add_action('save_post', 'diyseo_save_meta_values');
function diyseo_add_settings_link($links) {
    // Build the settings page URL
    $settings_link = '<a href="' . admin_url('admin.php?page=diyseo_settings_page') . '">Settings</a>';
    
    // Add the settings link to the beginning of the array
    array_unshift($links, $settings_link);
    
    return $links;
}

// Add filter for plugin action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'diyseo_add_settings_link');

// Add a function to remove Yoast meta tags from the head
function diyseo_remove_yoast_meta() {
    // Check if we're on a single post or page AND Yoast is active
    if (!defined('WPSEO_VERSION') || !diyseo_post_has_seo_data()) {
        return;
    }

    // Remove all Yoast head actions
    if (class_exists('WPSEO_Frontend')) {
        $wpseo_frontend = WPSEO_Frontend::get_instance();
        remove_action('wpseo_head', array($wpseo_frontend, 'head'), 1);
    }

    // Remove all Yoast meta tag related actions
    if (class_exists('WPSEO_Meta')) {
        remove_all_filters('wpseo_title');
        remove_all_filters('wpseo_metadesc');
        remove_all_filters('wpseo_robots');
        remove_all_filters('wpseo_canonical');
        remove_all_filters('wpseo_opengraph_title');
        remove_all_filters('wpseo_opengraph_description');
        remove_all_filters('wpseo_twitter_title');
        remove_all_filters('wpseo_twitter_description');
    }

    // Remove schema
    add_filter('wpseo_json_ld_output', '__return_false', 99);

    // Remove specific meta tags
    remove_all_actions('wpseo_opengraph');
    remove_all_actions('wpseo_twitter');

    // Remove Yoast SEO classes from links
    add_filter('wpseo_link_rel_canonical', '__return_false');
    add_filter('wpseo_canonical', '__return_false');
}
add_action('template_redirect', 'diyseo_remove_yoast_meta', 999);

// Add a function to remove Yoast's JSON-LD schema
function diyseo_remove_yoast_schema() {
    if (defined('WPSEO_VERSION') && diyseo_post_has_seo_data()) {
        add_filter('wpseo_json_ld_output', '__return_false');
    }
}
add_action('wp_head', 'diyseo_remove_yoast_schema', 0);

// Modify your existing integrate with Yoast functions to have higher priority
function diyseo_integrate_with_yoast($title) {
    if (is_single() || is_page()) { // Changed from just is_single()
        $post_id = get_the_ID();
        $meta_title = get_post_meta($post_id, '_diyseo_meta_title', true);
        if (!empty($meta_title)) {
            remove_action('wp_head', '_wp_render_title_tag', 1);
            return $meta_title;
        }
    }
    return $title;
}
add_filter('wp_title', 'diyseo_integrate_with_yoast', 999);
add_filter('pre_get_document_title', 'diyseo_integrate_with_yoast', 999);

// Also update the description integration
function diyseo_integrate_with_yoast_desc($description) {
    if (is_single()) {
        $post_id = get_the_ID();
        $meta_description = get_post_meta($post_id, '_diyseo_meta_description', true);
        if (!empty($meta_description)) {
            // Remove Yoast's description for this post
            delete_post_meta($post_id, '_yoast_wpseo_metadesc');
            return $meta_description;
        }
    }
    return $description;
}
add_filter('wpseo_metadesc', 'diyseo_integrate_with_yoast_desc', 999);

//IMAGE LOADING
function diyseo_setup_screenshots() {
    // Only run this if the screenshot IDs aren't set
    if (!get_option('diyseo_dashboard_screenshot_id')) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $upload_dir = wp_upload_dir();
        
        // Dashboard screenshot
        $dashboard_image = plugin_dir_url(__FILE__) . 'assets/images/Screen-Shot-2024-10-21-at-12.53.18-PM-1024x409-1.png';
        $dashboard_id = media_sideload_image($dashboard_image, 0, 'DIYSEO Dashboard Screenshot', 'id');
        if (!is_wp_error($dashboard_id)) {
            update_option('diyseo_dashboard_screenshot_id', $dashboard_id);
        }
        
        // Plugin interface screenshot
        $interface_image = plugin_dir_url(__FILE__) . 'assets/images/form-screenshot-1024x534.png';
        $interface_id = media_sideload_image($interface_image, 0, 'DIYSEO Plugin Interface Screenshot', 'id');
        if (!is_wp_error($interface_id)) {
            update_option('diyseo_interface_screenshot_id', $interface_id);
        }
        
        // Calendar Step 1 screenshot
        $calendar_step1_image = plugin_dir_url(__FILE__) . 'assets/images/Screenshot-2024-12-09-102912.png';
        $calendar_step1_id = media_sideload_image($calendar_step1_image, 0, 'DIYSEO Calendar Step 1 Screenshot', 'id');
        if (!is_wp_error($calendar_step1_id)) {
            update_option('diyseo_calendar_step1_id', $calendar_step1_id);
        }
        
        // Calendar Step 2 screenshot
        $calendar_step2_image = plugin_dir_url(__FILE__) . 'assets/images/Screenshot-2024-12-09-103047.png';
        $calendar_step2_id = media_sideload_image($calendar_step2_image, 0, 'DIYSEO Calendar Step 2 Screenshot', 'id');
        if (!is_wp_error($calendar_step2_id)) {
            update_option('diyseo_calendar_step2_id', $calendar_step2_id);
        }
        
        // Calendar Step 3 screenshot
        $calendar_step3_image = plugin_dir_url(__FILE__) . 'assets/images/Screenshot-2024-12-09-103140.png';
        $calendar_step3_id = media_sideload_image($calendar_step3_image, 0, 'DIYSEO Calendar Step 3 Screenshot', 'id');
        if (!is_wp_error($calendar_step3_id)) {
            update_option('diyseo_calendar_step3_id', $calendar_step3_id);
        }
        
        // Calendar Main screenshot
        $calendar_main_image = plugin_dir_url(__FILE__) . 'assets/images/Screenshot-2024-12-09-103627.png';
        $calendar_main_id = media_sideload_image($calendar_main_image, 0, 'DIYSEO Calendar Main Screenshot', 'id');
        if (!is_wp_error($calendar_main_id)) {
            update_option('diyseo_calendar_main_id', $calendar_main_id);
        }
    }
}
register_activation_hook(__FILE__, 'diyseo_setup_screenshots');

// Add cleanup on deactivation
function diyseo_cleanup_screenshots() {
    $screenshot_ids = array(
        get_option('diyseo_dashboard_screenshot_id'),
        get_option('diyseo_interface_screenshot_id'),
        get_option('diyseo_calendar_step1_id'),
        get_option('diyseo_calendar_step2_id'),
        get_option('diyseo_calendar_step3_id'),
        get_option('diyseo_calendar_main_id')
    );
    
    foreach ($screenshot_ids as $id) {
        if ($id) {
            wp_delete_attachment($id, true);
            delete_option('diyseo_' . $id . '_id');
        }
    }
}

register_deactivation_hook(__FILE__, 'diyseo_cleanup_screenshots');
function diyseo_setup_logo() {
    if (!get_option('diyseo_logo_id')) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $logo_url = plugin_dir_url(__FILE__) . 'assets/images/logo-1.png';
        $logo_id = media_sideload_image($logo_url, 0, 'DIY SEO Logo', 'id');
        
        if (!is_wp_error($logo_id)) {
            update_option('diyseo_logo_id', $logo_id);
        }
    }
}
register_activation_hook(__FILE__, 'diyseo_setup_logo');
function diyseo_cleanup_logo() {
    $logo_id = get_option('diyseo_logo_id');
    if ($logo_id) {
        wp_delete_attachment($logo_id, true);
        delete_option('diyseo_logo_id');
    }
}
register_deactivation_hook(__FILE__, 'diyseo_cleanup_logo');

?>