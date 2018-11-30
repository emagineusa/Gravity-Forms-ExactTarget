<?php
/*
Plugin Name: Gravity Forms ExactTarget Add-On
Plugin URI: https://katz.co/plugins/gravity-forms-exacttarget/
Description: Integrates Gravity Forms with ExactTarget allowing form submissions to be automatically sent to your ExactTarget account
Version: 1.0.7
Author: Katz Web Services, Inc., PrecisionDialogue
Author URI: http://www.katzwebservices.com, http://www.precisiondialogue.com

------------------------------------------------------------------------
Copyright 2011 Katz Web Services, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFExactTarget', 'init'));
register_activation_hook( __FILE__, array("GFExactTarget", "add_permissions"));

class GFExactTarget {

    private static $path = "gravity-forms-exacttarget/exacttarget.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravity-forms-exacttarget";
    private static $version = "1.0";
    private static $min_gravityforms_version = "1.3.9";

    //Plugin starting point. Will load appropriate files
    public static function init(){
        global $pagenow;
        if($pagenow == 'plugins.php' || defined('RG_CURRENT_PAGE') && RG_CURRENT_PAGE == "plugins.php"){
            //loading translations
            load_plugin_textdomain('gravity-forms-exacttarget', FALSE, '/gravity-forms-exacttarget/languages' );

            add_action('after_plugin_row_' . self::$path, array('GFExactTarget', 'plugin_row') );

            add_filter('plugin_action_links', array('GFExactTarget', 'settings_link'), 10, 2 );

        }

        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravity-forms-exacttarget', FALSE, '/gravity-forms-exacttarget/languages' );

            add_filter("transient_update_plugins", array('GFExactTarget', 'check_update'));
            #add_filter("site_transient_update_plugins", array('GFExactTarget', 'check_update'));

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_exacttarget")){
                RGForms::add_settings_page("ExactTarget", array("GFExactTarget", "settings_page"), self::get_base_url() . "/images/exacttarget_wordpress_icon_32.png");
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFExactTarget", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFExactTarget', 'create_menu'));

        if(self::is_exacttarget_page()){

            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

            //loading data lib
            require_once(self::get_base_path() . "/data.php");


            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFExactTarget', 'tooltips'));

            //runs the setup when version changes
            self::setup();

         }
         else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFExactTarget', 'update_feed_active'));
            add_action('wp_ajax_gf_select_exacttarget_form', array('GFExactTarget', 'select_exacttarget_form'));

        }
        else{
             //handling post submission.
            add_action("gform_post_submission", array('GFExactTarget', 'export'), 10, 2);
        }
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFExactTargetData::get_feed($id);
        GFExactTargetData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //--------------   Automatic upgrade ---------------------------------------------------

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("%sGravity Forms%s is required. Activate it now or %spurchase it today!%s"), "<a href='http://wordpressformplugin.com/?r=et'>", "</a>", "<a href='http://wordpressformplugin.com/?r=et'>", "</a>");
            self::display_plugin_message($message, true);
        }
    }

    function settings_link( $links, $file ) {
        static $this_plugin;
        if( ! $this_plugin ) $this_plugin = plugin_basename(__FILE__);
        if ( $file == $this_plugin ) {
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_exacttarget' ) . '" title="' . __('Select the Gravity Form you would like to integrate with ExactTarget. Contacts generated by this form will be automatically added to your ExactTarget account.', 'gravity-forms-exacttarget') . '">' . __('Feeds', 'gravity-forms-exacttarget') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_settings&addon=ExactTarget' ) . '" title="' . __('Configure your ExactTarget settings.', 'gravity-forms-exacttarget') . '">' . __('Settings', 'gravity-forms-exacttarget') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
        }
        return $links;
    }

    public static function display_plugin_message($message, $is_error = false){
        $style = '';
        if($is_error)
            $style = 'style="background-color: #ffebe8;"';

        echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
    }


    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_exacttarget_page(){
        global $plugin_page; $current_page = '';
        $exacttarget_pages = array("gf_exacttarget");

        if(isset($_GET['page'])) {
            $current_page = trim(strtolower($_GET["page"]));
        }

        return (in_array($plugin_page, $exacttarget_pages) || in_array($current_page, $exacttarget_pages));
    }


    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_exacttarget_version") != self::$version)
            GFExactTargetData::update_table();

        update_option("gf_exacttarget_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $exacttarget_tooltips = array(
            "exacttarget_contact_list" => "<h6>" . __("ExactTarget List", "gravity-forms-exacttarget") . "</h6>" . __("Select the ExactTarget list you would like to add your contacts to.", "gravity-forms-exacttarget"),
            "exacttarget_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-exacttarget") . "</h6>" . __("Select the Gravity Form you would like to integrate with ExactTarget. Contacts generated by this form will be automatically added to your ExactTarget account.", "gravity-forms-exacttarget"),
            "exacttarget_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-exacttarget") . "</h6>" . __("Associate your ExactTarget attributes to the appropriate Gravity Form fields by selecting.", "gravity-forms-exacttarget"),
            "exacttarget_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-exacttarget") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to ExactTarget when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-exacttarget"),

        );
        return array_merge($tooltips, $exacttarget_tooltips);
    }

    //Creates ExactTarget left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_exacttarget");
        if(!empty($permission))
            $menus[] = array("name" => "gf_exacttarget", "label" => __("ExactTarget", "gravity-forms-exacttarget"), "callback" =>  array("GFExactTarget", "exacttarget_page"), "permission" => $permission);

        return $menus;
    }

    public static function settings_page(){

        if(isset($_POST["uninstall"])){
            check_admin_referer("uninstall", "gf_exacttarget_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms ExactTarget Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-exacttarget")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_exacttarget_submit"])){
            check_admin_referer("update", "gf_exacttarget_update");
            $settings = array(
                "username" => stripslashes($_POST["gf_exacttarget_username"]),
                "password" => stripslashes($_POST["gf_exacttarget_password"]),
                "mid" => stripslashes((int)$_POST["gf_exacttarget_mid"]),
                "debug" => isset($_POST["gf_exacttarget_debug"]),
                "subscriberkey" => isset($_POST["gf_exacttarget_subscriberkey"]),
                "s4" => isset($_POST["gf_exacttarget_s4"]),
                "instance" => stripslashes(($_POST["gf_exacttarget_instance"])),
                "addtype" => stripslashes($_POST["gf_exacttarget_addtype"]),
                "runscope" => stripslashes($_POST["gf_exacttarget_runscope"]),
                "enforceRequired" => stripslashes($_POST["gf_exacttarget_enforceRequired"])
            );
            update_option("gf_exacttarget_settings", $settings);
        }
        else{
            $settings = get_option("gf_exacttarget_settings");
        }

        $settings = wp_parse_args($settings, array(
                "username" => '',
                "password" => '',
                "mid" => '',
                "subscriberkey" => false,
                "debug" => false,
                "s4" => false,
                "instance" => "",
                "addtype" => 'api',
                "runscope" => "",
                "enforceRequired" => true
            ));

        $api = self::get_api();
        $message = '';

        if(!empty($settings["username"]) || !empty($settings["password"])){
            $api->TestAPI();
        }

        $new = false;
        if(!empty($settings["username"]) && !empty($settings["password"]) && empty($api->lastError)){
            $message = sprintf(__("Valid username and API key. Now go %sconfigure form integration with ExactTarget%s!", "gravity-forms-exacttarget"), '<a href="'.admin_url('admin.php?page=gf_exacttarget').'">', '</a>');
            $class = "updated valid_credentials";
            $valid = true;
            $new = false;
        } else if(!empty($settings["username"]) || !empty($settings["password"])){
            $message = __("Invalid username and/or password. Please try another combination. (Message from ExactTarget: &ldquo;".$api->lastError.'&rdquo;)', "gravity-forms-exacttarget");
            $valid = false;
            $class = "error invalid_credentials";
            $new = false;
        } else if (empty($settings["username"]) && empty($settings["password"])) {
            $new = true;
            $valid = false;
            $class = 'updated notice';

        }

        if($message) {
            ?>
            <div id="message" class="<?php echo $class ?>"><?php echo wpautop($message); ?></div>
            <?php
        }
        /* <?php _e("", "gravity-forms-exacttarget"); ?> */

        ?>

        <?php if($new) { @include_once('register.php'); } ?>

        <form method="post" action="<?php echo remove_query_arg(array('refresh', 'retrieveListNames', '_wpnonce')); ?>" <?php if($new) { echo ' class="alignleft" style="width:60%; clear:left;"'; } ?>>
            <?php wp_nonce_field("update", "gf_exacttarget_update") ?>

            <h2><?php _e("ExactTarget Account Information", "gravity-forms-exacttarget") ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_exacttarget_username"><?php _e("ExactTarget Account Username", "gravity-forms-exacttarget"); ?></label> </th>
                    <td><input type="text" id="gf_exacttarget_username" name="gf_exacttarget_username" size="30" value="<?php echo empty($settings["username"]) ? '' : esc_attr($settings["username"]); ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_password"><?php _e("Password", "gravity-forms-exacttarget"); ?></label> </th>
                    <td><input type="password" id="gf_exacttarget_password" name="gf_exacttarget_password" size="40" value="<?php echo !empty($settings["password"]) ? esc_attr($settings["password"]) : ''; ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_instance"><?php _e("ExactTarget instance", "gravity-forms-exacttarget"); ?></label></th>
                    <td>
                        <select id="gf_exacttarget_instance" name="gf_exacttarget_instance">
                            <option value="s1" id="s1" <?php if($settings["instance"] == "s1") echo "selected"; ?>>S1</option>
                            <option value="s4" id="s4" <?php if($settings["instance"] == "s4") echo "selected"; ?>>S4</option>
                            <option value="s6" id="s6" <?php if($settings["instance"] == "s6") echo "selected"; ?>>S6</option>
                            <option value="s7" id="s7" <?php if($settings["instance"] == "s7") echo "selected"; ?>>S7</option>
                        </select>
                    <span class="howto"><?php _e("When you log in to ExactTarget, does the URL start with <code>https://members.<strong>s1</strong>.exacttarget.com</code>, <code>https://members.<strong>s4</strong>.exacttarget.com</code> or <code>https://members.<strong>s6</strong>.exacttarget.com</code>? Please select the matching value from the list. Need more help?", "gravity-forms-exacttarget"); ?> <a rel="external" target="_blank" href="http://wiki.memberlandingpages.com/010_ExactTarget/010_Getting_Started/The_Getting_Started_Guide/Set_Up_Your_Account#How_To_Determine_What_Instance_You're_On"><?php _e("How to Determine What Instance You're On", "gravity-forms-exacttarget"); ?></a>.</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_mid"><?php _e("Member ID", "gravity-forms-exacttarget"); ?></label></th>
                    <td valign="top"><input type="text" id="gf_exacttarget_mid" name="gf_exacttarget_mid" size="10" value="<?php echo !empty($settings["mid"]) ? esc_attr($settings["mid"]) : ''; ?>"/><span class="howto"><?php _e("You can find your member ID in the upper-right corner of your ExactTarget application, next to your account name.", "gravity-forms-exacttarget"); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_subscriberkey"><?php _e("Subscriber Key is Required For This Account", "gravity-forms-exacttarget"); ?></label> </th>
                    <td><input type="checkbox" id="gf_exacttarget_subscriberkey" name="gf_exacttarget_subscriberkey" value="1" <?php checked($settings["subscriberkey"], true); ?>/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_enforceRequired"><?php _e("Enforce mapping of all required fields", "gravity-forms-exacttarget"); ?></label> </th>
                    <td><input type="checkbox" id="gf_exacttarget_enforceRequired" name="gf_exacttarget_enforceRequired" value="1" <?php checked($settings["enforceRequired"], true); ?>/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_debug"><?php _e("Debug Form Submissions for Administrators", "gravity-forms-exacttarget"); ?></label> </th>
                    <td><input type="checkbox" id="gf_exacttarget_debug" name="gf_exacttarget_debug" value="1" <?php checked($settings["debug"], true); ?>/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_exacttarget_runscope"><?php _e("Runscope bucket key", "gravity-forms-exacttarget"); ?></label></th>
                    <td valign="top"><input type="text" id="gf_exacttarget_runscope" name="gf_exacttarget_runscope" size="10" value="<?php echo !empty($settings["runscope"]) ? esc_attr($settings["runscope"]) : ''; ?>"/>
                    <span class="howto"><?php _e("This is an ExactTarget API call debugging option. If you are having issues with API calls and want to use <a href='https://www.runscope.com' target='_blank'>Runscope</a> to analyze the calls, enter the bucket key here.", "gravity-forms-exacttarget"); ?></span></td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_exacttarget_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-exacttarget") ?>" /></td>
                </tr>

            </table>
     <?php if($valid) { ?>

           <div id="listnames">
            <div class="hr-divider"></div>
                <h3><?php _e("Retrieve List Names", "gravity-forms-exacttarget") ?></h3>
                <?php

                if(isset($_REQUEST['retrieveListNames']) && isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'retrieveListNames')) {

                $lists_raw = $api->Lists(true);

                $i = 0; $count = count($lists_raw);
                echo '<h4>'.__(sprintf('Retrieving list names for %d lists', $count), "gravity-forms-exacttarget").'</h4>';
                echo '<ol class="lists_loading ol-decimal" style="list-style:decimal outside!important; margin-left:1.8em!important;">';
                    foreach($lists_raw as $list ) {
                    $i++;
                    echo '<li style="margin-bottom:.5em!important; list-style: decimal outside;">List #'.$list['list_id'];
                    if(strtolower($list['list_type']) == 'public') {
                        $lists[$list['list_id']] = $list;
                        echo ': &ldquo;<strong>'.$list['list_name'].'</strong>&rdquo;';
                    }
                    echo ' ('.$i.' of '.$count.' / '. round(($i/$count * 100), 1).'% Completed)</li>';
                    flush();
                }

                echo '</ol>';
                @set_transient('extr_lists_all', $lists, 5);// 60*60*24*365);
                } else {
                ?>
                <p>
                <a class="submit button button-secondary" href="<?php echo add_query_arg(array('retrieveListNames' =>true, '_wpnonce' => wp_create_nonce('retrieveListNames'))); ?>"><?php _e("Retrieve List Names", "gravity-forms-exacttarget") ?></a></p>
                <p><span class="howto"><?php _e("If you have many lists and only the list IDs are shown when setting up your forms, click the button above to retrieve full list name information.", "gravity-forms-exacttarget") ?></span></p>

                <?php } ?>

            </div>
        </form>
    <?php } ?>
        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_exacttarget_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_exacttarget_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall ExactTarget Add-On", "gravity-forms-exacttarget") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL ExactTarget Feeds.", "gravity-forms-exacttarget") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall ExactTarget Add-On", "gravity-forms-exacttarget") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL ExactTarget Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-exacttarget") . '\');"/>';
                    echo apply_filters("gform_exacttarget_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    public static function exacttarget_page(){
        $view = isset($_GET["view"]) ? $_GET["view"] : '';
        if($view == "edit")
            self::edit_page($_GET["id"]);
        else
            self::list_page();
    }

    //Displays the ExactTarget feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("The ExactTarget Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-exacttarget"));
        }

        if(isset($_POST["action"]) && $_POST["action"] == "delete"){
            check_admin_referer("list_action", "gf_exacttarget_list");

            $id = absint($_POST["action_argument"]);
            GFExactTargetData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-exacttarget") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_exacttarget_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFExactTargetData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-exacttarget") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("ExactTarget Feeds", "gravity-forms-exacttarget") ?>" src="<?php echo self::get_base_url()?>/images/exacttarget_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php _e("ExactTarget Feeds", "gravity-forms-exacttarget"); ?>
            <a class="button add-new-h2" href="admin.php?page=gf_exacttarget&view=edit&id=0"><?php _e("Add New", "gravity-forms-exacttarget") ?></a>
            </h2>

            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=gf_settings&addon=ExactTarget'); ?>">ExactTarget Settings</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=gf_exacttarget'); ?>" class="current">ExactTarget Feeds</a></li>
            </ul>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_exacttarget_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px; 0">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-exacttarget") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-exacttarget") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-exacttarget") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-exacttarget") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-exacttarget") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-exacttarget") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-exacttarget") ?></th>
                            <th scope="col" class="manage-column"><?php _e("ExactTarget Lists", "gravity-forms-exacttarget") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-exacttarget") ?></th>
                            <th scope="col" class="manage-column"><?php _e("ExactTarget Lists", "gravity-forms-exacttarget") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFExactTargetData::get_feeds();
                        if(is_array($settings) && !empty($settings)){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-exacttarget") : __("Inactive", "gravity-forms-exacttarget");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-exacttarget") : __("Inactive", "gravity-forms-exacttarget");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_exacttarget&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-exacttarget") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting" href="admin.php?page=gf_exacttarget&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-exacttarget") ?>"><?php _e("Edit", "gravity-forms-exacttarget") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravity-forms-exacttarget") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-exacttarget") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-exacttarget") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-exacttarget")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><ul class="ul-disc"><li><?php echo implode('</li><li>', explode(',', $setting["meta"]["contact_list_name"])) ?></li></ul></td>
                                </tr>
                                <?php
                            }
                        }
                        else {
                            $api = self::get_api();
                            if(!empty($api) && empty($api->lastError)){
                                ?>
                                <tr>
                                    <td colspan="4" style="padding:20px;">
                                        <?php _e(sprintf("You don't have any ExactTarget feeds configured. Let's go %screate one%s!", '<a href="'.admin_url('admin.php?page=gf_exacttarget&view=edit&id=0').'">', "</a>"), "gravity-forms-exacttarget"); ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            else{
                                ?>
                                <tr>
                                    <td colspan="4" style="padding:20px;">
                                        <?php _e(sprintf("To get started, please configure your %sExactTarget Settings%s.", '<a href="admin.php?page=gf_settings&addon=ExactTarget">', "</a>"), "gravity-forms-exacttarget"); ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-exacttarget") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-exacttarget") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-exacttarget") ?>').attr('alt', '<?php _e("Active", "gravity-forms-exacttarget") ?>');
                }

                var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-exacttarget" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    public static function get_api(){
        if(!class_exists("ExactTarget"))
            require_once("api/ExactTarget.class.php");

        return new ExactTarget();
    }

    private static function edit_page(){
        ?>
        <style type="text/css">
            label span.howto { cursor: default; }
            .exacttarget_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .exacttarget_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px; padding-right: 20px;}
            .margin_vertical_10{margin: 20px 0;}
            #gf_exacttarget_list_list { margin-left:220px; padding-top: 1px }
            #exacttarget_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <img alt="<?php _e("ExactTarget", "gravity-forms-exacttarget") ?>" style="margin: .75em 7px 0pt 0pt; float: left;" src="<?php echo self::get_base_url() ?>/images/exacttarget_wordpress_icon_32.png"/>
            <h2><?php _e("ExactTarget Feed", "gravity-forms-exacttarget") ?></h2>

        <?php
        //getting ExactTarget API
        $api = self::get_api();

        //ensures valid credentials were entered in the settings page
        if(!empty($api->lastError)){
            ?>
            <div class="error" id="message" style="margin-top:20px;"><?php echo wpautop(sprintf(__("We are unable to login to ExactTarget with the provided username and API key. Please make sure they are valid in the %sSettings Page%s", "gravity-forms-exacttarget"), "<a href='?page=gf_settings&addon=ExactTarget'>", "</a>")); ?></div>
            <?php
            return;
        }

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["exacttarget_setting_id"]) ? $_POST["exacttarget_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFExactTargetData::get_feed($id);


        //getting merge vars
        $merge_vars = array();

        //updating meta information
        if(isset($_POST["gf_exacttarget_submit"])){
            $list_ids = $list_names = array();
            foreach($_POST["gf_exacttarget_list"] as $list){
                list($list_id, $list_name) = explode("|:|", stripslashes($list));
                $list_ids[] = $list_id;
                $list_names[] = $list_name;
            }
#           print_r(array('id' => $list_ids, 'name' => $list_names));
            $config["meta"]["contact_list_id"] = empty($list_ids) ? 0 : implode(',', $list_ids);
            $config["meta"]["contact_list_name"] = implode(',', $list_names);
            $config["form_id"] = absint($_POST["gf_exacttarget_form"]);

            $is_valid = true;
            $merge_vars = $api->Attributes($config["meta"]["contact_list_id"]);

            $field_map = array();
            foreach($merge_vars as $key => $var){
                $field_name = "exacttarget_map_field_" . $key;
                $mapped_field = isset($_POST[$field_name]) ? stripslashes($_POST[$field_name]) : '';
                if(!empty($mapped_field)){
                    $field_map[$key] = $mapped_field;
                }
                else{
                    unset($field_map[$key]);
                    if($var["IsRequired"] && $api->enforceRequired)
                    $is_valid = false;
                }
                unset($_POST["{$field_name}"]);
            }

            // Go through the items that were not in the field map;
            // the Custom Fields
            foreach($_POST as $k => $v) {
                if(preg_match('/exacttarget\_map\_field\_/', $k)) {
                    $tag = str_replace('exacttarget_map_field_', '', $k);
                    $field_map[$tag] = stripslashes($_POST[$k]);
                }
            }

            $config["meta"]["field_map"] = $field_map;
            #$config["meta"]["double_optin"] = !empty($_POST["exacttarget_double_optin"]) ? true : false;
            #$config["meta"]["welcome_email"] = !empty($_POST["exacttarget_welcome_email"]) ? true : false;

            $config["meta"]["optin_enabled"] = !empty($_POST["exacttarget_optin_enable"]) ? true : false;
            $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? isset($_POST["exacttarget_optin_field_id"]) ? $_POST["exacttarget_optin_field_id"] : '' : "";
            $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? isset($_POST["exacttarget_optin_operator"]) ? $_POST["exacttarget_optin_operator"] : '' : "";
            $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? $_POST["exacttarget_optin_value"] : "";



            if($is_valid){
                $id = GFExactTargetData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div id="message" class="updated fade" style="margin-top:10px;"><p><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-exacttarget"), "<a href='?page=gf_exacttarget'>", "</a>") ?></p>
                    <input type="hidden" name="exacttarget_setting_id" value="<?php echo $id ?>"/>
                </div>
                <?php
            }
            else{
                ?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-exacttarget") ?></div>
                <?php
            }
        }
        if(!function_exists('gform_tooltip')) {
            require_once(GFCommon::get_base_path() . "/tooltips.php");
        }

        ?>
        <form method="post" action="<?php echo remove_query_arg('refresh'); ?>">
            <input type="hidden" name="exacttarget_setting_id" value="<?php echo $id ?>"/>
            <div class="margin_vertical_10">
                <h2><?php _e('1. Select the lists to merge with.', "gravity-forms-exacttarget"); ?></h2>
                <label for="gf_exacttarget_list" class="left_header"><?php _e("ExactTarget List", "gravity-forms-exacttarget"); ?> <?php gform_tooltip("exacttarget_contact_list") ?> <span class="howto"><?php _e(sprintf("%sRefresh lists%s", '<a href="'.add_query_arg('refresh', 'lists').'">','</a>'), "gravity-forms-exacttarget"); ?></span></label>

                <?php
                $trans = get_transient('extr_lists');
                if(!isset($_POST["gf_exacttarget_submit"]) && (!$trans || ($trans && isset($_REQUEST['refresh']) && $_REQUEST['refresh'] === 'lists'))) { ?>
                    <p class="lists_loading hide-if-no-js" style='padding:5px;'><img src="<?php echo GFExactTarget::get_base_url() ?>/images/loading.gif" id="exacttarget_wait" style="padding-right:5px;" width="16" height="16" /> <?php _e('Lists are being loaded', 'gravity-forms-exacttarget'); ?></p>
               <?php
                   }

                //getting all contact lists
                $lists = $api->Lists();

                if (!$lists){
                    echo __("Could not load ExactTarget contact lists. <br/>Error: ", "gravity-forms-exacttarget");
                    echo isset($api->lastError) ? $api->lastError : '';
                }
                else{
                    if(isset($config["meta"]["contact_list_id"])) {
                        $contact_lists = explode(',' , $config["meta"]["contact_list_id"]);
                    } else {
                        $contact_lists = array();
                    }
                    ?>
                 <?php
                    if(!get_transient('extr_lists_all')) {
                        echo sprintf('%sYour list size is large; only list ID\'s are shown. %sRetrieve List Names%s%s', '<p>','<a href="'.add_query_arg(array('retrieveListNames' =>true, '_wpnonce' => wp_create_nonce('retrieveListNames')), admin_url('admin.php?page=gf_settings&addon=ExactTarget')).'" class="button-secondary button">', '</a>','</p>');
                     }
                 ?>
                 <ul id="gf_exacttarget_list_list" class="hide-if-js">
                    <?php
                    foreach ($lists as $key => $list){
                        $selected = in_array($key, $contact_lists) ? "checked='checked'" : "";
                        ?>
                        <li><label style="display:block;" for="gf_exacttarget_list_<?php echo esc_html($key); ?>"><input type="checkbox" name="gf_exacttarget_list[]" id="gf_exacttarget_list_<?php echo esc_html($key); ?>" value="<?php echo esc_html($key) . "|:|" . esc_html($list['list_name']) ?>" <?php echo $selected ?> /> <?php echo esc_html($list['list_name']) ?></label></li>
                        <?php
                    }
                    ?>
                  </ul>
                  <script type="text/javascript">
                    if(jQuery('.lists_loading').length && jQuery('#gf_exacttarget_list_list').length) {
                        jQuery('.lists_loading').fadeOut(function() { jQuery('#gf_exacttarget_list_list').fadeIn(); });
                     } else if(jQuery('#gf_exacttarget_list_list').length) {
                        jQuery('#gf_exacttarget_list_list').show();
                     }
                 </script>
                <?php
                }
                ?>
                <div class="clear"></div>
            </div>
            <?php flush(); ?>
            <div id="exacttarget_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["contact_list_id"]) ? "style='display:none;'" : "" ?>>
                <h2><?php _e('2. Select the form to tap into.', "gravity-forms-exacttarget"); ?></h2>
                <label for="gf_exacttarget_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-exacttarget"); ?> <?php gform_tooltip("exacttarget_gravity_form") ?></label>

                <select id="gf_exacttarget_form" name="gf_exacttarget_form" onchange="SelectForm(jQuery('#gf_exacttarget_list_list input').serialize(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-exacttarget"); ?> </option>
                <?php
                $forms = RGFormsModel::get_forms();
                foreach($forms as $form){
                    $selected = absint($form->id) == $config["form_id"] ? "selected='selected'" : "";
                    ?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
                }
                ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFExactTarget::get_base_url() ?>/images/loading.gif" id="exacttarget_wait" style="display: none;"/>
            </div>
            <div class="clear"></div>
            <div id="exacttarget_field_group" valign="top" <?php echo empty($config["meta"]["contact_list_id"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="exacttarget_field_container" valign="top" class="margin_vertical_10" >
                    <h2><?php _e('3. Map form fields to ExactTarget attributes.', "gravity-forms-exacttarget"); ?></h2>
                    <p class="description" style="margin-bottom:1em;"><?php _e(sprintf('If you don&rsquo;t see an attribute listed, you need to create it in ExactTarget first under %sSubscribers > Profile Management%s.%sOnly mapped fields will be added to ExactTarget.', '<em style="font-style:normal;">', '</em>', '<br />'), "gravity-forms-exacttarget"); ?></p>
                    <label for="exacttarget_fields" class="left_header"><?php _e("Map Fields", "gravity-forms-exacttarget"); ?> <?php gform_tooltip("exacttarget_map_fields") ?> <span class="howto"><?php _e(sprintf("%sRefresh fields%s", '<a href="'.add_query_arg('refresh', 'attributes').'">','</a>'), "gravity-forms-exacttarget"); ?></span></label>

                    <div id="exacttarget_field_list">
                    <?php
                    if(!empty($config["form_id"])){

                        //getting list of all ExactTarget merge variables for the selected contact list
                        if(empty($merge_vars))
                            $merge_vars = $api->Attributes();

                        //getting field map UI
                        echo self::get_field_mapping($config, $config["form_id"], $merge_vars);

                        //getting list of selection fields to be used by the optin
                        $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                        $selection_fields = GFCommon::get_selection_fields($form_meta, $config["meta"]["optin_field_id"]);
                    }
                    ?>
                    </div>
                    <div class="clear"></div>
                </div>

                <div id="exacttarget_optin_container" valign="top" class="margin_vertical_10">
                    <label for="exacttarget_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-exacttarget"); ?> <?php gform_tooltip("exacttarget_optin_condition") ?></label>
                    <div id="exacttarget_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="exacttarget_optin_enable" name="exacttarget_optin_enable" value="1" onclick="if(this.checked){jQuery('#exacttarget_optin_condition_field_container').show('slow');} else{jQuery('#exacttarget_optin_condition_field_container').hide('slow');}" <?php echo !empty($config["meta"]["optin_enabled"]) ? "checked='checked'" : ""?>/>
                                    <label for="exacttarget_optin_enable"><?php _e("Enable", "gravity-forms-exacttarget"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="exacttarget_optin_condition_field_container" <?php echo empty($config["meta"]["optin_enabled"]) ? "style='display:none'" : ""?>>
                                        <div id="exacttarget_optin_condition_fields" <?php echo empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("Export to ExactTarget if ", "gravity-forms-exacttarget") ?>

                                            <select id="exacttarget_optin_field_id" name="exacttarget_optin_field_id" class='optin_select' onchange='jQuery("#exacttarget_optin_value").html(GetFieldValues(jQuery(this).val(), "", 20));'><?php echo $selection_fields ?></select>
                                            <select id="exacttarget_optin_operator" name="exacttarget_optin_operator" />
                                                <option value="is" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "is") ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-exacttarget") ?></option>
                                                <option value="isnot" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "isnot") ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-exacttarget") ?></option>
                                            </select>
                                            <select id="exacttarget_optin_value" name="exacttarget_optin_value" class='optin_select'>
                                            </select>

                                        </div>
                                        <div id="exacttarget_optin_condition_message" <?php echo !empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("To create an Opt-In condition, your form must have a drop down, checkbox or multiple choice field.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <script type="text/javascript">
                        <?php
                        if(!empty($config["form_id"])){
                            ?>
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            //initializing drop downs
                            jQuery(document).ready(function(){
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                SetOptin(selectedField, selectedValue);
                            });
                        <?php
                        }
                        ?>
                    </script>
                </div>

                <div id="exacttarget_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_exacttarget_submit" value="<?php echo empty($id) ? __("Save Feed", "gravity-forms-exacttarget") : __("Update Feed", "gravity-forms-exacttarget"); ?>" class="button-primary"/>
                </div>
            </div>
        </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#gf_exacttarget_list_list').live('load change', function() {
                $('.lists_loading').hide();
            });
            $("#gf_exacttarget_list_list input").bind('click change', function() {
                if($("#gf_exacttarget_list_list input:checked").length > 0) {
                    SelectList(1);
                } else {
                    SelectList(false);
                    jQuery("#gf_exacttarget_form").val("");
                }
            });

            <?php if(isset($_REQUEST['id'])) { ?>
            $('#exacttarget_field_list').live('load', function() {
                $('.exacttarget_field_cell select').each(function() {
                    var $select = $(this);
                    if($().prop) {
                        var label = $.trim($('label[for='+$(this).prop('name')+']').text());
                    } else {
                        var label = $.trim($('label[for='+$(this).attr('name')+']').text());
                    }
                    label = label.replace(' *', '');

                    if($select.val() === '') {
                        $('option', $select).each(function() {
                            if($(this).text() === label) {
                                if($().prop) {
                                    $('option:contains('+label+')', $select).prop('selected', true);
                                } else {
                                    $('option:contains('+label+')', $select).prop('selected', true);
                                }
                            }
                        });
                    }
                });
            });
            <?php } ?>
        });
        </script>
        <script type="text/javascript">

            function SelectList(listId){
                if(listId){
                    jQuery("#exacttarget_form_container").slideDown();
                   // jQuery("#gf_exacttarget_form").val("");
                }
                else{
                    jQuery("#exacttarget_form_container").slideUp();
                    EndSelectForm("");
                }
            }

            function SelectForm(listId, formId){
                if(!formId){
                    jQuery("#exacttarget_field_group").slideUp();
                    return;
                }

                jQuery("#exacttarget_wait").show();
                jQuery("#exacttarget_field_group").slideUp();

                var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_exacttarget_form" );
                mysack.setVar( "gf_select_exacttarget_form", "<?php echo wp_create_nonce("gf_select_exacttarget_form") ?>" );
                mysack.setVar( "list_ids", listId);
                mysack.setVar( "form_id", formId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#exacttarget_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-exacttarget") ?>' )};
                mysack.runAJAX();
                return true;
            }

            function SetOptin(selectedField, selectedValue){

                //load form fields
                jQuery("#exacttarget_optin_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#exacttarget_optin_field_id").val();

                if(optinConditionField){
                    jQuery("#exacttarget_optin_condition_message").hide();
                    jQuery("#exacttarget_optin_condition_fields").show();
                    jQuery("#exacttarget_optin_value").html(GetFieldValues(optinConditionField, selectedValue, 20));
                }
                else{
                    jQuery("#exacttarget_optin_condition_message").show();
                    jQuery("#exacttarget_optin_condition_fields").hide();
                }
            }

            function EndSelectForm(fieldList, form_meta){
                //setting global form object
                form = form_meta;

                if(fieldList){

                    SetOptin("","");

                    jQuery("#exacttarget_field_list").html(fieldList);
                    jQuery("#exacttarget_field_group").slideDown();
                    jQuery('#exacttarget_field_list').trigger('load');
                }
                else{
                    jQuery("#exacttarget_field_group").slideUp();
                    jQuery("#exacttarget_field_list").html("");
                }
                jQuery("#exacttarget_wait").hide();
            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field || !field.choices)
                    return "";

                var isAnySelected = false;

                for(var i=0; i<field.choices.length; i++){
                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                    var isSelected = fieldValue == selectedValue;
                    var selected = isSelected ? "selected='selected'" : "";
                    if(isSelected)
                        isAnySelected = true;

                    str += "<option value='" + fieldValue.replace("'", "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                }

                if(!isAnySelected && selectedValue){
                    str += "<option value='" + selectedValue.replace("'", "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if(inputType == "checkbox" || inputType == "radio" || inputType == "select"){
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

        </script>

        <?php

    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_exacttarget");
        $wp_roles->add_cap("administrator", "gravityforms_exacttarget_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_exacttarget", "gravityforms_exacttarget_uninstall"));
    }

    public static function disable_exacttarget(){
        delete_option("gf_exacttarget_settings");
    }

    public static function select_exacttarget_form(){
        check_ajax_referer("gf_select_exacttarget_form", "gf_select_exacttarget_form");

        $api = self::get_api();

        if(!empty($api->lastError) || !isset($_POST["list_ids"])) {
            die("EndSelectForm();");
        }

        parse_str($_POST["list_ids"], $lists);

        $form_id =  intval($_POST["form_id"]);

        $setting_id =  0;

        //getting list of all ExactTarget merge variables for the selected contact list
        $merge_vars = $api->Attributes();

        //getting configuration
        $config = GFExactTargetData::get_feed($setting_id);

        //getting field map UI
        $str = self::get_field_mapping($config, $form_id, $merge_vars);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        //$fields = $form["fields"];
        die("EndSelectForm('" . str_replace("'", "\'", $str) . "', " . GFCommon::json_encode($form) . ");");
    }

    private static function get_field_mapping($config, $form_id, $merge_vars){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);
        $form = RGFormsModel::get_form_meta($form_id);

        $usedFields = $customFields = array();

        $str = '';

        $str .= "<table cellpadding='0' cellspacing='0'><tr><td class='exacttarget_col_heading'>" . __("Attribute", "gravity-forms-exacttarget") . "</td><td class='exacttarget_col_heading'>" . __("Form Fields", "gravity-forms-exacttarget") . "</td></tr>";

        foreach($merge_vars as $key => $var){
            $selected_field = (isset($config["meta"]) && isset($config["meta"]["field_map"]) && isset($config["meta"]["field_map"][$key])) ? $config["meta"]["field_map"][$key] : '';
            $required = $var["IsRequired"] == "true" ? "<span class='gfield_required'>*</span>" : "";
            $error_class = $var["IsRequired"] == "true" && empty($selected_field) && !empty($_POST["gf_exacttarget_submit"]) ? " feeds_validation_error" : "";
            $str .= "<tr class='$error_class'><td class='exacttarget_field_cell'><label for='exacttarget_map_field_".$key."'>" . $var["Name"]  . " $required</label></td><td class='exacttarget_field_cell'>" . self::get_mapped_field_list($key, $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private function getNewTag($tag, $used = array()) {
        if(isset($used[$tag])) {
            $i = 1;
            while($i < 1000) {
                if(!isset($used[$tag.'_'.$i])) {
                    return $tag.'_'.$i;
                }
                $i++;
            }
        }
        return $tag;
    }

    public static function get_form_fields($form_id){
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravity-forms-exacttarget")));
        array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravity-forms-exacttarget")));
        array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravity-forms-exacttarget")));

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"]) && $field['type'] !== 'checkbox' && $field['type'] !== 'select'){

                    //If this is an address field, add full name to the list
                    if(RGFormsModel::get_input_type($field) == "address")
                        $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravity-forms-exacttarget") . ")");

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(empty($field["displayOnly"])){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function get_address($entry, $field_id){
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
        $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
        $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
        $zip_value = trim($entry[$field_id . ".5"]);
        $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

        $address = $street_value;
        $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
        $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
        $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
        $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
        $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

        return $address;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "exacttarget_map_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''>" . __("", "gravity-forms-exacttarget") . "</option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = $field[1];

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function get_mapped_field_checkbox($variable_name, $selected_field, $field){
        $field_name = "exacttarget_map_field_" . $variable_name;
        $field_id = $field[0];
        $str =  "<input name='$field_name' id='$field_name' type='checkbox' value='$field_id'";
        $selected = $field_id == $selected_field ? " checked='checked'" : false;
        if($selected) {
            $str .= $selected;
        }

        $str .= " />";
        return $str;
    }

    public static function export($entry, $form){
        //Login to ExactTarget
        $api = self::get_api();
        if(!empty($api->lastError))
            return;

        //loading data class
        require_once(self::get_base_path() . "/data.php");

        //getting all active feeds
        $feeds = GFExactTargetData::get_feed_by_form($form["id"], true);
        foreach($feeds as $feed){
            //only export if user has opted in
            if(self::is_optin($form, $feed))
                self::export_feed($entry, $form, $feed, $api);
        }
    }

    public static function export_feed($entry, $form, $feed, $api){
        #print_r($feed); die();
        $double_optin = false; // $feed["meta"]["double_optin"] ? true : false;
        $send_welcome = false; // $feed["meta"]["welcome_email"] ? true : false;
        if(isset($feed["meta"]["field_map"]["email_address"])) {
            $email_field_id = $feed["meta"]["field_map"]["email_address"];
        }
        if(isset($feed["meta"]["field_map"]["emailaddress"])) {
            $email_field_id = $feed["meta"]["field_map"]["emailaddress"];
        }
        $email = $entry[$email_field_id];

        $merge_vars = array('');
        foreach($feed["meta"]["field_map"] as $var_tag => $field_id){

            $field = RGFormsModel::get_field($form, $field_id);

            if($var_tag == 'address_full') {
                $merge_vars[$var_tag] = self::get_address($entry, $field_id);
            } else if($var_tag  == 'country') {
#               echo $entry[$field_id]; die();
                $merge_vars[$var_tag] = empty($entry[$field_id]) ? '' : GFCommon::get_country_code(trim($entry[$field_id]));
            } else if($var_tag != "email") {
                if(!empty($entry[$field_id])) {
                    $merge_vars[$var_tag] = $entry[$field_id];
                } else {
                    foreach($entry as $key => $value) {
                        if(floor($key) == floor($field_id) && !empty($value)) {
                            $merge_vars[$var_tag][] = $value;
                        }
                    }
                }
            }
        }

        if(apply_filters('gf_exacttarget_add_source', true) && isset($form['title'])) {
            $merge_vars['source_form'] = $form['title'];
        }

        $api->listSubscribe($feed["meta"]["contact_list_id"], $email, $merge_vars);
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFExactTarget::has_access("gravityforms_exacttarget_uninstall"))
            die(__("You don't have adequate permission to uninstall ExactTarget Add-On.", "gravity-forms-exacttarget"));

        //droping all tables
        GFExactTargetData::drop_tables();

        //removing options
        delete_option("gf_exacttarget_settings");
        delete_option("gf_exacttarget_version");

        //Deactivating plugin
        $plugin = "gravity-forms-exacttarget/exacttarget.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    public static function is_optin($form, $settings){
        $config = $settings["meta"];
        $operator = $config["optin_operator"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = is_array($field_value) ? in_array($config["optin_value"], $field_value) : $field_value == $config["optin_value"];

        return  !$config["optin_enabled"] || empty($field) || ($operator == "is" && $is_value_match) || ($operator == "isnot" && !$is_value_match);
    }


    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    private function simpleXMLToArray($xml,
                    $flattenValues=true,
                    $flattenAttributes = true,
                    $flattenChildren=true,
                    $valueKey='@value',
                    $attributesKey='@attributes',
                    $childrenKey='@children'){

        $return = array();
        if(!($xml instanceof SimpleXMLElement)){return $return;}
        $name = $xml->getName();
        $_value = trim((string)$xml);
        if(strlen($_value)==0){$_value = null;};

        if($_value!==null){
            if(!$flattenValues){$return[$valueKey] = $_value;}
            else{$return = $_value;}
        }

        $children = array();
        $first = true;
        foreach($xml->children() as $elementName => $child){
            $value = self::simpleXMLToArray($child, $flattenValues, $flattenAttributes, $flattenChildren, $valueKey, $attributesKey, $childrenKey);
            if(isset($children[$elementName])){
                if($first){
                    $temp = $children[$elementName];
                    unset($children[$elementName]);
                    $children[$elementName][] = $temp;
                    $first=false;
                }
                $children[$elementName][] = $value;
            }
            else{
                $children[$elementName] = $value;
            }
        }
        if(count($children)>0){
            if(!$flattenChildren){$return[$childrenKey] = $children;}
            else{$return = array_merge($return,$children);}
        }

        $attributes = array();
        foreach($xml->attributes() as $name=>$value){
            $attributes[$name] = trim($value);
        }
        if(count($attributes)>0){
            if(!$flattenAttributes){$return[$attributesKey] = $attributes;}
            else{$return = array_merge($return, $attributes);}
        }

        return $return;
    }

    private function convert_xml_to_object($response) {
        $response = @simplexml_load_string($response);  // Added @ 1.2.2
        if(is_object($response)) {
            return $response;
        } else {
            return false;
        }
    }

    private function convert_xml_to_array($response) {
        $response = self::convert_xml_to_object($response);
        $response = self::simpleXMLToArray($response);
        if(is_array($response)) {
            return $response;
        } else {
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }


}

?>