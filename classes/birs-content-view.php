<?php

abstract class BIRS_Content_View extends BIRS_Admin_View {

    private $process_errors = array();

    function __construct() {
        add_action('init', array(&$this, 'init'));
        add_action('add_meta_boxes', array(&$this, 'create_admin_panel'));

        $pages = array('post.php', 'post-new.php');
        foreach ($pages as $page) {
            add_action('admin_print_styles-' . $page, array(&$this, 'add_admin_css'));
            add_action('admin_print_scripts-' . $page, array(&$this, 'add_admin_scripts'));
        }

        add_action('save_post', array(&$this, 'save_content'), 1, 2);
        add_action('admin_notices', array(&$this, 'render_saving_errors'));
        add_filter('post_updated_messages', array(&$this, 'get_updated_messages'));
        add_filter('wp_insert_post_data', array(&$this, 'pre_save_content_ex'));
        add_filter('manage_edit-' . $this->get_content_type() . '_columns', array(&$this, 'get_edit_columns'));
        add_action('manage_' . $this->get_content_type() . '_posts_custom_column', array(&$this, 'render_custom_columns'), 2);
    }

    function init() {
        global $birchschedule;
        $admin_scripts = $this->get_admin_scripts();

        foreach ($admin_scripts as $key => $value) {
            wp_register_script($key, $birchschedule->plugin_url() . '/assets/js/admin/' . $value['source'], $value['depends'], $value['version']);
        }
    }

    function save_content($post_id, $post) {
        if (empty($post_id) || empty($post) || empty($_POST))
            return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        if (is_int(wp_is_post_revision($post)))
            return;
        if (is_int(wp_is_post_autosave($post)))
            return;
        if (!current_user_can('edit_post', $post_id))
            return;
        if ($post->post_type != $this->get_content_type())
            return;

        $this->process_content($post_id, $post);
    }

    abstract function process_content($post_id, $post);

    function pre_save_content($data) {
        return $data;
    }

    function pre_save_content_ex($data) {
        if (isset($_POST['post_type']) && $_POST['post_type'] == $this->get_content_type()) {
            return $this->pre_save_content($data);
        } else {
            return $data;
        }
    }

    function handle_errors() {
        if (sizeof($this->process_errors) > 0) {
            update_option('birchschedule_errors', $this->process_errors);
            $this->process_errors = array();
        }
    }

    function render_select_options($options, $current_value) {
        foreach ($options as $value => $text) {
            if ($value == $current_value) {
                echo "<option value=\"$value\" selected=\"selected\">$text</option>";
            } else {
                echo "<option value=\"$value\">$text</option>";
            }
        }
    }

    function render_saving_errors() {
        $errors = $this->get_errors();
        if ($errors && sizeof($errors) > 0) {
            echo '<div id="birchschedule_errors" class="error fade">';
            foreach ($errors as $error) {
                echo '<p>' . $error . '</p>';
            }
            echo '</div>';
            update_option('birchschedule_errors', '');
        }
    }

    function get_errors() {
        return maybe_unserialize(get_option('birchschedule_errors'));
    }

    function has_errors() {
        $errors = $this->get_errors();
        if ($errors && sizeof($errors) > 0) {
            return true;
        } else {
            return false;
        }
    }

    function create_admin_panel() {
        $screen = $this->get_content_type();
        remove_meta_box('slugdiv', $screen, 'normal');
    }

    function get_typenow() {
        global $post;

        return $post->post_type;
    }

    function add_admin_css() {
        global $birchschedule;

        $typenow = $this->get_typenow();

        if ($typenow == '' || $typenow == $this->get_content_type()) {
            wp_enqueue_style('birchschedule_admin_styles', $birchschedule->plugin_url() . '/assets/css/admin.css');
        }
    }

    function add_admin_scripts() {
        $typenow = $this->get_typenow();

        if ($typenow == '' || $typenow == $this->get_content_type()) {
            $scripts = $this->get_admin_scripts();
            foreach ($scripts as $key => $value) {
                wp_enqueue_script($key);
            }
        }
    }

    function get_admin_scripts() {
        return array(
            'birs_admin_common' => array(
                'source' => 'common.js',
                'depends' => array('jquery'),
                'version' => '1.0'
            )
        );
    }

    function render_actions($post) {
        $post_id = $post->ID;
        $post_type = $this->get_content_type();
        $delete_url = 'post.php?post=' . $post_id . '&action=delete';
        $delete_url = wp_nonce_url($delete_url, 'delete-' . $post_type . '_' . $post_id);
        ?>
        <div class="submitbox">
            <div id="major-publishing-actions">
                <div id="publishing-action">
                    <input type="submit" class="button-primary tips" name="publish"
                           value="<?php _e('Save', 'birchschedule'); ?>"
                           alt="<?php _e('Save Data', 'birchschedule'); ?>" />
                </div>
                <div id="delete-action">
                    <a class="submitdelete deletion" href="<?php echo $delete_url; ?>"><?php _e('Delete', 'birchschedule'); ?>
                    </a>
                </div>
                <div class="clear"></div>
            </div>
        </div>
        <?php
    }

    abstract function get_content_type();

    abstract function get_updated_messages($messages);

    function render_custom_columns($column) {
        global $post;

        if($column == 'description') {
            the_content();
            return;
        }
        $value = get_post_meta($post->ID, '_' . $column, true);

        echo $value;
    }

    function get_edit_columns($columns) {
        return $columns;
    }

    function save_content_relations($post_id, $post_type, $key, $reverse_key) {
        $this->save_field_array($post_id, $key);
        if (isset($_POST[$key])) {
            $assigned_services = $_POST[$key];
        } else {
            $assigned_services = array();
        }
        $services = get_posts(array('post_type' => $post_type));
        foreach ($services as $service) {
            $assigned_staff = get_post_meta($service->ID, '_' . $reverse_key, true);
            $assigned_staff = unserialize($assigned_staff);
            if (array_key_exists($service->ID, $assigned_services)) {
                $assigned_staff[$post_id] = 'on';
            } else {
                unset($assigned_staff[$post_id]);
            }
            update_post_meta($service->ID, '_' . $reverse_key, serialize($assigned_staff));
        }
    }

}