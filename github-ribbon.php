<?php
/**
Plugin Name: Github Ribbon
Plugin URI: http://sudarmuthu.com/wordpress/github-ribbon
Description: Adds "Fork me on Github" ribbons to your WordPress posts.
Author: Sudar
Version: 0.6
Donate Link: http://sudarmuthu.com/if-you-wanna-thank-me
Author URI: http://sudarmuthu.com/
Text Domain: github-ribbon

=== RELEASE NOTES ===
2010-09-04 - v0.1 - Initial Release
2010-11-08 - v0.2 - Added option to use CSS3 ribbons
2011-01-23 - v0.3 - Added Dutch Translations
2011-09-07 - v0.4 - Added CSS style to hide ribbon on printed pages
2011-11-28 - v0.5 - Added Spanish Translations
2012-07-10 - v0.6 - (Dev time: 0.5 hour)
                  - Added Hindi translations
*/

/*  Copyright 2010  Sudar Muthu  (email : sudar@sudarmuthu.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Github Ribbon Plugin Class
 */
class GithubRibbon {

    private $ribbon_placed = false; //flag to see if the ribbon is already placed

    /**
     * Initalize the plugin by registering the hooks
     */
    function __construct() {

        // Load localization domain
        load_plugin_textdomain( 'github-ribbon', false, dirname(plugin_basename(__FILE__)) . '/languages' );

        // Register hooks

        // Settings hooks
        add_action( 'admin_menu', array(&$this, 'register_settings_page') );
        add_action( 'admin_init', array(&$this, 'add_settings') );

        /* Use the admin_menu action to define the custom boxes */
        add_action('admin_menu', array(&$this, 'add_custom_box'));

        /* Use the save_post action to do something with the data entered */
        add_action('save_post', array(&$this, 'save_postdata'));

        // Enqueue the script
        add_action('template_redirect', array(&$this, 'add_style'));

        // Register filters
        add_filter('the_content', array(&$this, 'add_ribbon') , 99);

        $plugin = plugin_basename(__FILE__);
        add_filter("plugin_action_links_$plugin", array(&$this, 'add_action_links'));

    }

    /**
     * Register the settings page
     */
    function register_settings_page() {
        add_options_page( __('Github Ribbon', 'github-ribbon'), __('Github Ribbon', 'github-ribbon'), 8, 'github-ribbon', array(&$this, 'settings_page') );
    }

    /**
     * add options
     */
    function add_settings() {
        // Register options
        register_setting( 'github-ribbon-options', 'github-ribbon-options', array(&$this, 'validate_settings'));

        //Global Options section
        add_settings_section('gr_global_section', __('Global Settings', 'github-ribbon'), array(&$this, 'print_gr_global_section_text'), __FILE__);

        add_settings_field('enable-ribbon', __('Show Github Ribbons', 'github-ribbon'), array(&$this, 'gr_enable_ribbon_callback'), __FILE__, 'gr_global_section');
        add_settings_field('github-url', __('Github URL', 'github-ribbon'), array(&$this, 'gr_github_url_callback'), __FILE__, 'gr_global_section');
        add_settings_field('ribbon-type', __('Ribbon Type', 'github-ribbon'), array(&$this, 'gr_ribbon_type_callback'), __FILE__, 'gr_global_section');
        add_settings_field('ribbon-button-type', __('Ribbon Button Type', 'github-ribbon'), array(&$this, 'gr_ribbon_button_type_callback'), __FILE__, 'gr_global_section');

    }

    /**
     * Adds the custom section in the Post and Page edit screens
     */
    function add_custom_box() {

        add_meta_box( 'github_ribbon_box', __( 'Github Ribbon', 'github-ribbon' ),
                    array(&$this, 'inner_custom_box'), 'post', 'side' );
        add_meta_box( 'github_ribbon_box', __( 'Github Ribbon', 'github-ribbon' ),
                    array(&$this, 'inner_custom_box'), 'page', 'side' );
    }

    /**
     * Prints the inner fields for the custom post/page section
     */
    function inner_custom_box() {
        global $post;
        $post_id = $post->ID;
        
        $gr_options = get_option('github-ribbon-options');

        if ($post_id > 0) {
            $gr_overridden = get_post_meta($post_id, 'gr_overridden', true);
            if ($gr_overridden == '1') {
                $gr_options = get_post_meta($post_id, 'gr_options', true);
            }
        }
        // Use nonce for verification
?>
        <input type="hidden" name="gr_noncename" id="gr_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) );?>" />
        <p>
            <label><input type="checkbox" name="gr_overridden" value = "1" <?php checked('1', $gr_overridden); ?> /> <?php _e('Override global option', 'github-ribbon'); ?></label>
        </p>

        <p>
<?php
        $items = array("Show", "Hide");
        foreach($items as $item) {
            echo "<label><input " . checked($item, $gr_options['enable-ribbon'], false) . " value='$item' name='enable-ribbon' type='radio' /> $item</label> ";
        }
?>
        </p>
        <p>
            <label><?php _e('Github Url:', 'github-ribbon'); ?><input type ="text" name="github-url" value ="<?php echo $gr_options['github-url'];?>" /></label>
        </p>

        <p>
            <label><?php _e('Ribbon type:', 'github-ribbon'); ?>
<?php
        $ribbon_class = new ReflectionClass('GithubRibbonType');
        $ribbon_types = $ribbon_class->getConstants();
        $ribbon_types = array_flip($ribbon_types);

        echo "<select id='github-ribbon-types' name='ribbon-type'>";
        foreach($ribbon_types as $item) {
            echo "<option value='" . $ribbon_class->getConstant($item) . "' " . selected($ribbon_class->getConstant($item), $gr_options['ribbon-type'], false) . " >$item</option>";
        }
        echo "</select>";
?>
            </label></p>
<?php
    }

    /**
     * When the post is saved, saves our custom data
     * @param string $post_id
     * @return string return post id if nothing is saved
     */
    function save_postdata( $post_id ) {

        // verify this came from the our screen and with proper authorization,
        // because save_post can be triggered at other times

        if ( !wp_verify_nonce( $_POST['gr_noncename'], plugin_basename(__FILE__) )) {
            return $post_id;
        }

        if ( 'page' == $_POST['post_type'] ) {
            if ( !current_user_can( 'edit_page', $post_id ))
                return $post_id;
        } else {
            if ( !current_user_can( 'edit_post', $post_id ))
                return $post_id;
        }

        // OK, we're authenticated: we need to find and save the data

        if (isset($_POST['gr_overridden'])) {
            $choice = $_POST['gr_overridden'];
            $choice = ($choice == '1')? '1' : '0';
            update_post_meta($post_id, 'gr_overridden', $choice);

            $enable_ribbon = ($_POST['enable-ribbon'] == 'Hide') ? 'Hide' : 'Show' ;
            $github_url = esc_url($_POST['github-url'], array('http', 'https'));
            $ribbon_type = absint($_POST['ribbon-type']);

            $gr_options = array(
                'enable-ribbon' => $enable_ribbon,
                'github-url' => $github_url,
                'ribbon-type' => $ribbon_type
            );
            update_post_meta($post_id, 'gr_options', $gr_options);
        } else {
            $choice = $_POST['gr_overridden'];
            $choice = ($choice == '1')? '1' : '0';
            update_post_meta($post_id, 'gr_overridden', $choice);
        }
    }

    /**
     * hook to add action links
     * @param <type> $links
     * @return <type>
     */
    function add_action_links( $links ) {
        // Add a link to this plugin's settings page
        $settings_link = '<a href="options-general.php?page=github-ribbon">' . __("Settings", 'github-ribbon') . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Adds Footer links.
     *
     * Based on http://striderweb.com/nerdaphernalia/2008/06/give-your-wordpress-plugin-credit/
     */
    function add_footer_links() {
        $plugin_data = get_plugin_data( __FILE__ );
        printf('%1$s ' . __("plugin", 'github-ribbon') .' | ' . __("Version", 'github-ribbon') . ' %2$s | '. __('by', 'github-ribbon') . ' %3$s<br />', $plugin_data['Title'], $plugin_data['Version'], $plugin_data['Author']);
    }

    /**
     * Dipslay the Settings page
     */
    function settings_page() {
?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2><?php _e( 'Github Ribbon Settings', 'github-ribbon' ); ?></h2>

            <form id="smer_form" method="post" action="options.php">
                <?php settings_fields('github-ribbon-options'); ?>
        		<?php do_settings_sections(__FILE__); ?>

                <p class="submit">
                    <input type="submit" name="github-ribbon-submit" class="button-primary" value="<?php _e('Save Changes', 'github-ribbon') ?>" />
                </p>
            </form>
        </div>
<?php
        // Display credits in Footer
        add_action( 'in_admin_footer', array(&$this, 'add_footer_links'));
    }

    /**
     * Include CSS3 Ribbon styles
     *
     */
    function add_style() {
        $options = get_option('github-ribbon-options');
        if ($options['ribbon-button-type'] == 'CSS3 ribbons') {
            wp_enqueue_style('github-ribbon', plugin_dir_url(__FILE__) . 'styles/github-ribbon.css');
            wp_enqueue_style('github-ribbon-print', plugin_dir_url(__FILE__) . 'styles/github-ribbon-print.css', array(), false, 'print');
        }
    }

    /**
     * Add the github ribbon
     * 
     * @global object $post Current post
     * @param string $content Post content
     * @return string modifiyed content
     */
    function add_ribbon($content) {

        if (!is_feed()) {
            global $post;
            $options = get_option('github-ribbon-options');

            $gr_overridden = get_post_meta($post->ID, 'gr_overridden', true);

            if ($gr_overridden == "1") {
                // if option per post/page is set
                $gr_options = get_post_meta($post->ID, 'gr_options', true);
                if (is_single() && $gr_options['enable-ribbon'] == "Show") {
                    // Ribbon is enabled
                    $content = $this->append_ribbon($content, $gr_options);
                }
            } else {
                //Option per post/page is not set
                if ($options['enable-ribbon'] == "Show") {
                    $content = $this->append_ribbon($content, $options);
                }
            }
        }
        return $content;
    }

    /**
     * Helper function for add_ribbon
     *
     * @param string $content The post content
     * @param array $options Options
     * @return string Modifiyed content
     */
    function append_ribbon($content, $options) {
        if (!$this->ribbon_placed) {
            $ribbon = github_ribbon($options['ribbon-type'], $options['github-url'], $options['ribbon-button-type'], false);
            $content = $content . $ribbon;
            $this->ribbon_placed = true;
        }

        return $content;
    }

    // ---------------------------Callback functions ----------------------------------------------------------

    /**
     * Validate the options entered by the user
     *
     * @param <type> $input
     * @return <type>
     */
    function validate_settings($input) {
        $input['enable-ribbon'] = ($input['enable-ribbon'] == 'Hide') ? 'Hide' : 'Show' ;
        $input['github-url'] = esc_url($input['github-url'], array('http', 'https'));
        $input['ribbon-type'] = absint($input['ribbon-type']);

        return $input;
    }

    /**
     * Print global section text
     */
    function  print_gr_global_section_text() {
        echo '<p>' . __('Enter the global configuration options for Github Ribbon.', 'github-ribbon') . '</p>';
    }

    /**
     * Callback for printing radio setting
     */
    function gr_enable_ribbon_callback() {
        $options = get_option('github-ribbon-options');
        $items = array("Show", "Hide");
        foreach($items as $item) {
            echo "<label><input " . checked($item, $options['enable-ribbon'], false) . " value='$item' name='github-ribbon-options[enable-ribbon]' type='radio' /> $item</label> ";
        }
    }

    /**
     * Callback for printing github url Setting
     */
    function gr_github_url_callback() {
        $options = get_option('github-ribbon-options');
        echo "<input id='github-url' name='github-ribbon-options[github-url]' size='40' type='text' value='{$options['github-url']}' />";
    }

    /**
     * Callback for Ribbon type Setting
     */
    function gr_ribbon_type_callback() {
        $options = get_option('github-ribbon-options');
        $ribbon_class = new ReflectionClass('GithubRibbonType');
        $ribbon_types = $ribbon_class->getConstants();
        $ribbon_types = array_flip($ribbon_types);

        echo "<select id='github-ribbon-types' name='github-ribbon-options[ribbon-type]'>";
        foreach($ribbon_types as $item) {
            echo "<option value='" . $ribbon_class->getConstant($item) . "' " . selected($ribbon_class->getConstant($item), $options['ribbon-type'], false) . " >$item</option>";
        }
        echo "</select>";
    }

    /**
     * Callback for Ribbon button type
     *
     */
    function gr_ribbon_button_type_callback() {
        $options = get_option('github-ribbon-options');

        $ribbon_button_type = $options['ribbon-button-type'];
        $ribbon_button_type = ($ribbon_button_type == 'CSS3 ribbons') ? $ribbon_button_type : 'Image ribbons' ;

        $items = array("Image ribbons", "CSS3 ribbons");
        foreach($items as $item) {
            echo "<label><input " . checked($item, $ribbon_button_type , false) . " value='$item' name='github-ribbon-options[ribbon-button-type]' type='radio' /> $item</label> ";
        }
        _e("(Will not work in IE)", 'github-ribbon');
    }

    // PHP4 compatibility
    function GithubRibbon() {
        $this->__construct();
    }
}

// Start this plugin once all other plugins are fully loaded
add_action( 'init', 'GithubRibbon' ); function GithubRibbon() { global $GithubRibbon; $GithubRibbon = new GithubRibbon(); }

/**
 * Class to store the ribbon types
 */
Class GithubRibbonType {
    const RED_LEFT = 0;
    const RED_RIGHT = 1;

    const GREEN_LEFT = 2;
    const GREEN_RIGHT = 3;

    const BLACK_LEFT = 4;
    const BLACK_RIGHT = 5;

    const ORANGE_LEFT = 6;
    const ORANGE_RIGHT = 7;

    const GREY_LEFT = 8;
    const GREY_RIGHT = 9;

    const WHITE_LEFT = 10;
    const WHITE_RIGHT = 11;

    /**
     * Get the ribbom image tag based on the image type
     *
     * @param <type> $ribbon_type
     * @return <type>
     */
    static function get_ribbon_image ($ribbon_type) {
        switch($ribbon_type) {
            case self::RED_LEFT:
                return <<<EOD
<img style="position: fixed; top: 0; left: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_left_red_aa0000.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::RED_RIGHT:
                return <<<EOD
<img style="position: fixed; top: 0; right: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_right_red_aa0000.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::GREEN_LEFT:
                return <<<EOD
<img style="position: fixed; top: 0; left: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_left_green_007200.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::GREEN_RIGHT:
                return <<<EOD
<img style="position: fixed; top: 0; right: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_right_green_007200.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::BLACK_LEFT:
                return <<<EOD
<img style="position: fixed; top: 0; left: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_left_darkblue_121621.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::BLACK_RIGHT:
                return <<<EOD
<img style="position: fixed; top: 0; right: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_right_darkblue_121621.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::ORANGE_LEFT:
                return <<<EOD
<img style="position: fixed; top: 0; left: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_left_orange_ff7600.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::ORANGE_RIGHT:
                return <<<EOD
<img style="position: fixed; top: 0; right: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_right_orange_ff7600.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::GRAY_LEFT:
                return <<<EOD
<img style="position: fixed; top: 0; left: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_left_gray_6d6d6d.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::GRAY_RIGHT:
                return <<<EOD
<img style="position: fixed; top: 0; right: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_right_gray_6d6d6d.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::WHITE_LEFT:
                return <<<EOD
<img style="position: fixed; top: 0; left: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_left_white_ffffff.png" alt="Fork me on GitHub" />
EOD;
                break;

            case self::WHITE_RIGHT:
                return <<<EOD
<img style="position: fixed; top: 0; right: 0; border: 0;" src="http://s3.amazonaws.com/github/ribbons/forkme_right_white_ffffff.png" alt="Fork me on GitHub" />
EOD;
                break;

        }
    }
}

/**
 * Template function to add the retweet button
 *
 * @param GithubRibbonType $ribbon_type
 * @param string $github_url
 * @param boolean $display
 * @return either return the ribbon tags or print it based on display parameter
 */
function github_ribbon($ribbon_type, $github_url, $ribbon_button_type = 'Image ribbons', $display = TRUE) {

    $output = '';
    
    if ($ribbon_button_type == 'CSS3 ribbons') {
        $ribbon_class = new ReflectionClass('GithubRibbonType');
        $ribbon_types = $ribbon_class->getConstants();
        $ribbon_types = array_flip($ribbon_types);

        $ribbon_options = split('_', $ribbon_types[$ribbon_type]);
        $ribbon_color = strtolower($ribbon_options[0]);
        $ribbon_pos   = strtolower($ribbon_options[1]);
        
        $output = <<<EOD

      <div class = "github-ribbon" class="$ribbon_pos ribbon-holder">
        <a href="$github_url" class="$ribbon_color ribbon">
          <span class="text">Fork me on GitHub</span>
        </a>
      </div>

EOD;
    } else {
        $output = '<a class = "github-ribbon" href="' . $github_url . '">' . GithubRibbonType::get_ribbon_image($ribbon_type) . '</a>';
    }
    
    if ($display) {
        echo $output;
    } else {
        return $output;
    }
}
?>
