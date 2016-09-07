<?php
GFForms::include_feed_addon_framework();

class GFMailUp extends GFFeedAddOn {

    protected $_version = GF_MAILUP_VERSION;
    protected $_min_gravityforms_version = '1.8.17';
    protected $_slug = 'gravityformsmailup';
    protected $_path = 'gravityformsmailup/mailup.php';
    protected $_full_path = __FILE__;
    protected $_url = 'http://www.gravityforms.com';
    protected $_title = 'Gravity Forms MailUp Add-On';
    protected $_short_title = 'MailUp';
    // Members plugin integration
    protected $_capabilities = array('gravityforms_mailup', 'gravityforms_mailup_uninstall');
    // Permissions
    protected $_capabilities_settings_page = 'gravityforms_mailup';
    protected $_capabilities_form_settings = 'gravityforms_mailup';
    protected $_capabilities_uninstall = 'gravityforms_mailup_uninstall';

    private static $api;
    private static $_instance = null;
    protected $mailup_client_id = '5a9b0bfb-ffc7-4441-ba9c-e05081dc24a0';
    protected $mailup_client_secret = 'b9f60942-233f-47ce-a88a-ebbf7790b22e';
    protected $mailup_callback_uri = '';
    private $mailup_access_token = '';
    private $mailup_refresh_token = '';

    public static function get_instance() {
        if (self::$_instance == null) {
            self::$_instance = new GFMailUp();
        }



        return self::$_instance;
    }

    public function init() {

        parent::init();

        $this->mailup_access_token = get_option('mailup_access_token');
        $this->mailup_refresh_token = get_option('mailup_refresh_token');
    }

    public function init_ajax() {
        parent::init_ajax();

        add_action('wp_ajax_gf_dismiss_mailup_menu', array($this, 'ajax_dismiss_menu'));
    }

    public function init_admin() {

        parent::init_admin();

        $this->mailup_access_token = get_option('mailup_access_token');
        $this->mailup_refresh_token = get_option('mailup_refresh_token');

        $this->mailup_callback_uri = admin_url(urlencode('admin.php?page=gf_settings&subview=gravityformsmailup'));
        $mailUp = $this->get_api();



        if (isset($_REQUEST['gform-settings-save']) && $_REQUEST['page'] == 'gf_settings' && $_REQUEST['subview'] == 'gravityformsmailup') {
            $this->log_debug(__METHOD__ . "(): Validating authentication.");
            $mailUp->logOn();
        }

        if (isset($_REQUEST["code"]) && $_REQUEST['page'] == 'gf_settings' && $_REQUEST['subview'] == 'gravityformsmailup') { // code returned by MailUp
            $this->log_debug(__METHOD__ . "(): Retrieve Token.");
            $mailUp->retreiveAccessTokenWithCode($_REQUEST["code"]);
        }



        $this->ensure_upgrade();

        add_filter('gform_addon_navigation', array($this, 'maybe_create_menu'));
    }

    function settings_save($field, $echo = true) {

        $field['type'] = 'submit';
        $field['name'] = 'gform-settings-save';
        $field['class'] = 'button-primary gfbutton';

        if (!rgar($field, 'value')) {
            $field['value'] = esc_html__('Update Settings', 'gravityforms');
        }

        $attributes = $this->get_field_attributes($field);

        $html = '<input
					type="' . esc_attr($field['type']) . '"
					name="' . esc_attr($field['name']) . '"
					value="' . esc_attr($field['value']) . '" ' . implode(' ', $attributes) . ' />';


        if ($echo) {
            echo $html;
        }

        return $html;
    }

    // ------- Plugin settings -------

    public function plugin_settings_fields() {

        return array(
            array(
                'title' => __('MailUp Account Information', 'gravityformsmailup'),
                'description' => sprintf(
                        __('MailUp makes it easy to send email newsletters to your customers, manage your subscriber lists, and track campaign performance. Use Gravity Forms to collect customer information and automatically add them to your MailUp subscriber list. If you don\'t have a MailUp account, you can %1$s sign up for one here.%2$s', 'gravityformsmailup'), '<a href="http://www.mailup.com/" target="_blank">', '</a>'
                ),
                'fields' => array(
                    array(
                        'type' => 'mailup_connection',
                        'name' => 'mailup_connection',
                        'label' => __('MailUp Connection:'),
                    ),
                )
            ),
        );
    }

    public function settings_mailup_connection() {
        if ($this->mailup_access_token) {
            echo '<span style="color:green">' . __('Authorized', 'gravityformsmailup') . '</span>';
        } else {
            echo '<span style="color:#CC0000">' . __('Unauthorized', 'gravityformsmailup') . '</span>';
        }
    }

    public function feed_settings_fields() {
        return array(
            array(
                'title' => __('MailUp Feed Settings', 'gravityformsmailup'),
                'description' => '',
                'fields' => array(
                    array(
                        'name' => 'feedName',
                        'label' => __('Name', 'gravityformsmailup'),
                        'type' => 'text',
                        'required' => true,
                        'class' => 'medium',
                        'tooltip' => '<h6>' . __('Name', 'gravityformsmailup') . '</h6>' . __('Enter a feed name to uniquely identify this setup.', 'gravityformsmailup')
                    ),
                    array(
                        'name' => 'mailupList',
                        'label' => __('MailUp List', 'gravityformsmailup'),
                        'type' => 'mailup_list',
                        'required' => true,
                        'tooltip' => '<h6>' . __('MailUp List', 'gravityformsmailup') . '</h6>' . __('Select the MailUp list you would like to add your contacts to.', 'gravityformsmailup'),
                    ),
                )
            ),
            array('title' => __('List Groups', 'gravityformsmailup'),
                'description' => __('Warning: if Double Opt-in is ENABLED the recipient will be subscribed only in one group, the first one that satisfies the conditions.<br><br>', 'gravityformsmailup'),
                'dependency' => 'mailupList',
                'fields' => array(
                    array(
                        'name' => 'groups',
                        'label' => __('Groups', 'gravityformsmailup'),
                        'dependency' => array($this, 'has_mailup_groups'),
                        'type' => 'mailup_groups',
                        'tooltip' => '<h6>' . __('Groups', 'gravityformsmailup') . '</h6>' . __('When one or more groups are enabled, users will be assigned to the groups in addition to being subscribed to the MailUp list. When disabled, users will not be assigned to groups.', 'gravityformsmailup'),
                    ))),
            array('title' => __('Default Mailup Fields', 'gravityformsmailup'),
                'description' => __('Email is mandatory, mobile number is optional.<br><br>', 'gravityformsmailup'),
                'dependency' => 'mailupList',
                'fields' => array(
                    array(
                        'name' => 'mailupFields',
                        'label' => __('Map Fields', 'gravityformsmailup'),
                        'type' => 'field_map',
                        'field_map' => $this->merge_vars_field_mailup(),
                        'tooltip' => '<h6>' . __('Map Fields', 'gravityformsmailup') . '</h6>' . __('Associate your MailUp merge variables to the appropriate Gravity Form fields by selecting.', 'gravityformsmailup'),
                    ))),
            array(
                'title' => __('Personal Data Fields', 'gravityformsmailup'),
                'description' => __('Personal data fields are updated in any case, regardless the previous subscription status (if any).<br><br>', 'gravityformsmailup'),
                'dependency' => 'mailupList',
                'fields' => array(
                    array(
                        'name' => 'mappedFields',
                        'label' => __('Map Fields', 'gravityformsmailup'),
                        'type' => 'field_map',
                        'field_map' => $this->merge_vars_field_map(),
                        'tooltip' => '<h6>' . __('Map Fields', 'gravityformsmailup') . '</h6>' . __('Associate your MailUp merge variables to the appropriate Gravity Form fields by selecting.', 'gravityformsmailup'),
                    )
                )
            ),
            //aaaaa
            array('title' => __('Other Options', 'gravityformsmailup'),
                'description' => '',
                'dependency' => 'mailupList',
                'fields' => array(
                    array(
                        'name' => 'optinCondition',
                        'label' => __('Opt-In Condition', 'gravityformsmailup'),
                        'type' => 'feed_condition',
                        'checkbox_label' => __('Enable', 'gravityformsmailup'),
                        'instructions' => __('Export to MailUp if', 'gravityformsmailup'),
                        'tooltip' => '<h6>' . __('Opt-In Condition', 'gravityformsmailup') . '</h6>' . __('When the opt-in condition is enabled, form submissions will only be exported to MailUp when the conditions are met. When disabled all form submissions will be exported.', 'gravityformsmailup'),
                    ),
                    array(
                        'name' => 'options',
                        'label' => __('Options', 'gravityformsmailup'),
                        'type' => 'checkbox',
                        'choices' => array(
                            array(
                                'name' => 'double_optin',
                                'label' => __('Double Opt-In', 'gravityformsmailup'),
                                'default_value' => 1,
                                'tooltip' => '<h6>' . __('Double Opt-In', 'gravityformsmailup') . '</h6>' . __('When the double opt-in option is enabled, MailUp will send a confirmation email to the user and will only add them to your MailUp list upon confirmation.', 'gravityformsmailup'),
                                'onclick' => 'if(this.checked){jQuery("#mailup_doubleoptin_warning").hide();} else{jQuery("#mailup_doubleoptin_warning").show();}',
                            ),
                            array(
                                'name' => 'sendWelcomeEmail',
                                'label' => __('Send Welcome Email', 'gravityformsmailup'),
                                'tooltip' => '<h6>' . __('Send Welcome Email', 'gravityformsmailup') . '</h6>' . __('When this option is enabled, users will receive an automatic welcome email from MailUp upon being added to your MailUp list.', 'gravityformsmailup'),
                            ),
                        )
                    ),
                    array('type' => 'save')
                )),
        );
    }

    public function checkbox_input_double_optin($choice, $attributes, $value, $tooltip) {
        $markup = $this->checkbox_input($choice, $attributes, $value, $tooltip);

        if ($value) {
            $display = 'none';
        } else {
            $display = 'block-inline';
        }

        $markup .= '<span id="mailup_doubleoptin_warning" style="padding-left: 10px; font-size: 10px; display:' . $display . '">(' . __('Abusing this may cause your MailUp account to be suspended.', 'gravityformsmailup') . ')</span>';

        return $markup;
    }

    //-------- Form Settings ---------
    public function feed_edit_page($form, $feed_id) {

        // getting MailUp API
        // ensures valid credentials were entered in the settings page
        if (!$this->mailup_access_token) {
            ?>
            <div><?php
                echo sprintf(
                        __('We are unable to login to MailUp with the provided credentials. Please make sure they are valid in the %sSettings Page%s', 'gravityformsmailup'), "<a href='" . esc_url($this->get_plugin_settings_url()) . "'>", '</a>'
                );
                ?>
            </div>

            <?php
            return;
        }

        echo '<script type="text/javascript">var form = ' . GFCommon::json_encode($form) . ';</script>';

        parent::feed_edit_page($form, $feed_id);
    }

    public function feed_list_columns() {
        return array(
            'feedName' => __('Name', 'gravityformsmailup'),
            'mailup_list_name' => __('MailUp List', 'gravityformsmailup')
        );
    }

    public function get_column_value_mailup_list_name($feed) {
        return $this->get_list_name($feed['meta']['mailupList']);
    }

    public function settings_mailup_list($field, $setting_value = '', $echo = true) {

        $mailUp = $this->get_api();

        $html = '';

        // getting all contact lists
        $this->log_debug(__METHOD__ . '(): Retrieving contact lists.');
        try {
            $url = $mailUp->getConsoleEndpoint() . "/Console/User/Lists?PageSize=100&orderby=Name+asc";
            $lists = $mailUp->callMethod($url, "GET", null, "JSON");
            $lists = json_decode($lists);

            //print_R($lists);
        } catch (MailUpException $e) {
            $this->log_error(__METHOD__ . '(): Could not load MailUp contact lists. Error ' . $e->getCode() . ' - ' . $e->getMessage());
        }

        if (empty($lists)) {
            echo __('Could not load MailUp contact lists. <br/>Error: ', 'gravityformsmailup') . $e->getMessage();
        } else if (empty($lists->Items)) {
            if ($lists->TotalElementsCount == 0) {
                //no lists found
                echo __('Could not load MailUp contact lists. <br/>Error: ', 'gravityformsmailup') . 'No lists found.';
                $this->log_error(__METHOD__ . '(): Could not load MailUp contact lists. Error ' . 'No lists found.');
            } else {
                echo __('Could not load MailUp contact lists. <br/>Error: ', 'gravityformsmailup') . rgar($lists['errors'][0], 'error');
                $this->log_error(__METHOD__ . '(): Could not load MailUp contact lists. Error ' . rgar($lists['errors'][0], 'error'));
            }
        } else {
            if (isset($lists->Items) && isset($lists->TotalElementsCount)) {
                $lists = $lists->Items;
                $this->log_debug(__METHOD__ . '(): Number of lists: ' . count($lists));
            }

            $options = array(
                array(
                    'label' => __('Select a MailUp List', 'gravityformsmailup'),
                    'value' => ''
                )
            );





            foreach ($lists as $list) {
                $options[] = array(
                    'label' => esc_html($list->Name),
                    'value' => esc_attr($list->idList)
                );
            }

            $field['type'] = 'select';
            $field['choices'] = $options;
            $field['onchange'] = 'jQuery(this).parents("form").submit();';

            $html = $this->settings_select($field, $setting_value, false);
        }

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_mailup_groups($field, $setting_value = '', $echo = true) {

        $groupings = $this->get_mailup_groups();



        if (empty($groupings)) {
            $this->log_debug(__METHOD__ . '(): No groups found.');

            return;
        }

        $str = "
		<style>
			.gaddon-mailup-groupname {font-weight: bold;}
			.gaddon-setting-checkbox {margin: 5px 0 0 0;}
			.gaddon-mailup-group .gf_animate_sub_settings {padding-left: 10px;}
		</style>

		<div id='gaddon-mailup_groups'>";






        $str .= "<div class='gaddon-mailup-group'>";
        //$str.= "<div class='gf_animate_sub_settings'>";


        foreach ($groupings->Items as $group) {




            $setting_key_root = $this->get_group_setting_key($group->idGroup, $group->Name);
            $choice_key_enabled = "{$setting_key_root}_enabled";


            $str .= $this->settings_checkbox(
                    array(
                'name' => $group->Name,
                'type' => 'checkbox',
                'choices' => array(
                    array(
                        'name' => $choice_key_enabled,
                        'label' => $group->Name,
                    ),
                ),
                'onclick' => "if(this.checked){jQuery('#{$setting_key_root}_condition_container').slideDown();} else{jQuery('#{$setting_key_root}_condition_container').slideUp();}",
                    ), false
            );

            $str .= $this->group_condition($setting_key_root);
        }

        $str .= '</div>';

        if ($echo) {
            echo $str;
        }

        return $str;
    }

    public function merge_vars_field_mailup() {




        $field_map[] = array(
            'name' => 'Email',
            'label' => 'Email',
            'required' => true,
        );

        $field_map[] = array(
            'name' => 'MobileNumber',
            'label' => 'MobileNumber'
        );


        return $field_map;
    }

    public function field_map_table_header() {
        return '<thead>
					<tr>
						<th></th>
						<th></th>
					</tr>
				</thead>';
    }

    public function merge_vars_field_map() {


        $list_id = $this->get_setting('mailupList');
        $field_map = array();

        if (!empty($list_id)) {
            try {


                $mailUp = $this->get_api();
                $url = $mailUp->getConsoleEndpoint() . "/Console/Recipient/DynamicFields?PageNumber=0&PageSize=100&orderby=Id+asc";
                $lists = $mailUp->callMethod($url, "GET", null, "JSON");
                $lists = json_decode($lists);

                //print_R($lists);
            } catch (MailUpException $e) {
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());

                return $field_map;
            }

            if (empty($lists->Items)) {
                $this->log_error(__METHOD__ . '(): Unable to retrieve list due to ' . $lists['errors'][0]['code'] . ' - ' . $lists['errors'][0]['error']);

                return $field_map;
            }
            $merge_vars = $lists->Items;



            foreach ($merge_vars as $merge_var) {
                $field_map[] = array(
                    'name' => $merge_var->Id,
                    'label' => $merge_var->Description
                );
            }
        }

        return $field_map;
    }

    public function has_mailup_groups() {
        $groupings = $this->get_mailup_groups();


        return !empty($groupings);
    }

    public function group_condition($setting_name_root) {

        $condition_enabled_setting = "{$setting_name_root}_enabled";
        $is_enabled = $this->get_setting($condition_enabled_setting) == '1';
        $container_style = !$is_enabled ? "style='display:none;'" : '';

        $str = "<div id='{$setting_name_root}_condition_container' {$container_style} class='condition_container'>" .
                __('Assign to group:', 'gravityformsmailup') . ' ';

        $str .= $this->settings_select(
                array(
            'name' => "{$setting_name_root}_decision",
            'type' => 'select',
            'choices' => array(
                array(
                    'value' => 'always',
                    'label' => __('Always', 'gravityformsmailup')
                ),
                array(
                    'value' => 'if',
                    'label' => __('If', 'gravityformsmailup')
                ),
            ),
            'onchange' => "if(jQuery(this).val() == 'if'){jQuery('#{$setting_name_root}_decision_container').show();}else{jQuery('#{$setting_name_root}_decision_container').hide();}",
                ), false
        );

        $decision = $this->get_setting("{$setting_name_root}_decision");
        if (empty($decision)) {
            $decision = 'always';
        }

        $conditional_style = $decision == 'always' ? "style='display:none;'" : '';

        $str .= '   <span id="' . $setting_name_root . '_decision_container" ' . $conditional_style . '><br />' .
                $this->simple_condition($setting_name_root, $is_enabled) .
                '   </span>' .
                '</div>';

        return $str;
    }

    public function get_conditional_logic_fields() {
        $form = $this->get_current_form();
        $fields = array();
        foreach ($form['fields'] as $field) {
            $type = GFFormsModel::get_input_type($field);
            $conditional_logic_fields = array(
                'checkbox',
                'radio',
                'select',
                'text',
                'website',
                'textarea',
                'email',
                'hidden',
                'number',
                'phone',
                'multiselect',
                'post_title',
                'post_tags',
                'post_custom_field',
                'post_content',
                'post_excerpt',
            );
            if (in_array($type, $conditional_logic_fields)) {
                $fields[] = array('value' => $field['id'], 'label' => $field['label']);
            }
        }

        return $fields;
    }

    //------ Core Functionality ------

    public function process_feed($feed, $entry, $form) {
        $this->log_debug(__METHOD__ . '(): Processing feed.');

        $mailUp = $this->get_api();


        try {
            $url = $mailUp->getConsoleEndpoint() . "/Console/Authentication/Info";
            $getAuthInfo = $mailUp->callMethod($url, "GET", null, "JSON");
            $getAuthInfo = json_decode($getAuthInfo);
        } catch (Exception $ex) {
            $this->log_debug(__METHOD__ . '(): Connection Error: ' . $ex->getStatusCode() . ': ' . $ex->getMessage());
        }


        if (!$getAuthInfo)
            return;


        $feed_meta = $feed['meta'];

        $double_optin = $feed_meta['double_optin'] == true;
        $send_welcome = $feed_meta['sendWelcomeEmail'] == true;
        $mailup_field_map = $this->get_field_map_fields($feed, 'mailupFields');
        $personal_field_map = $this->get_field_map_fields($feed, 'mappedFields');


        $override_empty_fields = apply_filters("gform_mailup_override_empty_fields_{$form['id']}", apply_filters('gform_mailup_override_empty_fields', true, $form, $entry, $feed), $form, $entry, $feed);

        if (!$override_empty_fields) {
            $this->log_debug(__METHOD__ . '(): Empty fields will not be overridden.');
        }


        $merge_vars['personal_field_map'] = $this->process_merge_vars($feed, $entry, $form, $override_empty_fields, $personal_field_map);
        $merge_vars['mailup_field_map'] = $this->process_merge_vars($feed, $entry, $form, $override_empty_fields, $mailup_field_map);


        $email = $merge_vars['mailup_field_map']['Email'];

        $groupings = $this->get_mailup_groups($feed_meta['mailupList']);





        if ($groupings !== false) {

            $groups = array();



            foreach ($groupings->Items as $group) {

                $group_settings = $this->get_group_settings($group, $group->idGroup, $feed);

                if (!$this->is_group_condition_met($group_settings, $form, $entry)) {
                    continue;
                }

                $groups[] = $group->idGroup;
            }

        }




        if (!empty($groups)) {
            $merge_vars['groupings'] = $groups;
        }

        $this->log_debug(__METHOD__ . "(): Checking to see if $email is already on the list.");
        $list_id = $feed_meta['mailupList'];

        $member_info = $this->checkRecipient($list_id, $email);


        if (empty($member_info)) {
            $this->log_error(__METHOD__ . '(): There was an error while trying to retrieve member information. Unable to process feed.');

            return;
        }

        if ($member_info['found'] == 0 || $member_info['status'] != 'Subscribed') {

            $allow_resubscription = apply_filters('gform_mailup_allow_resubscription', apply_filters("gform_mailup_allow_resubscription_{$form['id']}", true, $form, $entry, $feed), $form, $entry, $feed);
            if ($member_info['status'] == 'unsubscribed' && !$allow_resubscription) {
                $this->log_debug(__METHOD__ . '(): User is unsubscribed and resubscription is not allowed.');

                return true;
            }

            // adding member to list, statuses of $member_status != 'subscribed', 'pending', 'cleaned' need to be
            // 're-subscribed' to send out confirmation email
            $this->log_debug(__METHOD__ . "(): {$email} is either not on the list or on the list but the status is not subscribed - status: " . $member_info['status'] . '; adding to list.');
            $transaction = 'Subscribe';
            try {

                $personal_fields_map = array();
                foreach ($merge_vars['personal_field_map'] as $key => $value) {
                    $personal_fields_map[] = array('Id' => $key, 'Value' => $value);
                }

                $params = array(
                    'Fields' => $personal_fields_map,
                    'Name' => '',
                    'Email' => $merge_vars['mailup_field_map']['Email'],
                    'MobileNumber' => $merge_vars['mailup_field_map']['MobileNumber'],
                    'MobilePrefix' => ''
                );


                $this->log_debug(__METHOD__ . '(): Calling - subscribe, Parameters ' . print_r($params, true));

                $this->importRecipient($params, $list_id, $merge_vars, $double_optin, $send_welcome);
            } catch (Exception $e) {
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
            }
        } else {


            $this->log_debug(__METHOD__ . "(): {$email} is either not on the list or on the list but the status is not subscribed - status: " . $member_info['status'] . '; adding to list.');
            $transaction = 'Subscribe';
            try {

                $personal_fields_map = array();
                foreach ($merge_vars['personal_field_map'] as $key => $value) {
                    $personal_fields_map[] = array('Id' => $key, 'Value' => $value);
                }

                $params = array(
                    'Fields' => $personal_fields_map,
                    'Name' => '',
                    'Email' => $merge_vars['mailup_field_map']['Email'],
                    'MobileNumber' => $merge_vars['mailup_field_map']['MobileNumber'],
                    'MobilePrefix' => ''
                );


                $this->log_debug(__METHOD__ . '(): Calling - subscribe, Parameters ' . print_r($params, true));

                $this->importRecipient($params, $list_id, $merge_vars, $double_optin, $send_welcome);
            } catch (Exception $e) {
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
            }
        }

    }

    public function importRecipient($params, $list_id, $merge_vars, $double_optin, $send_welcome) {

        $mailUp = $this->get_api();

        if (isset($merge_vars['groupings']) && $merge_vars['groupings'] && $double_optin) {

            foreach ($merge_vars['groupings'] as $idGroup) {
                $url = $mailUp->getConsoleEndpoint() . "/Console/Group/$idGroup/Recipient";
                break;
            }
        } else {
            $url = $mailUp->getConsoleEndpoint() . "/Console/List/$list_id/Recipient";
        }





        if ($double_optin) {
            $url.= '?ConfirmEmail=true';
        }

        $body = json_encode($params);

        try {
            $importRecipient = $mailUp->callMethod($url, "POST", $body, "JSON");
        } catch (Exception $ex) {

            print_r($ex);

        }

        if (isset($importRecipient)) {

            if (isset($merge_vars['groupings']) && $merge_vars['groupings'] && !$double_optin) {

                foreach ($merge_vars['groupings'] as $idGroup) {
                    $url = $mailUp->getConsoleEndpoint() . "/Console/Group/$idGroup/Subscribe/$importRecipient";
                    $importRecipientInGroup = $mailUp->callMethod($url, "POST", NULL, "JSON");
                }
            }
        }


    }

    public function checkRecipient($list_id, $email) {
        $mailUp = $this->get_api();
        $recipientFound = 0;
        $recipientStatus = '';

        //check subscribed
        $url = $mailUp->getConsoleEndpoint() . "/Console/List/$list_id/Recipients/subscribed?filterby=\"Email.Contains('$email')\"";
        $recipientExists = $mailUp->callMethod($url, "GET", null, "JSON");
        $recipientExists = json_decode($recipientExists);

        $recipientFound = $recipientExists->TotalElementsCount;
        $recipientStatus = 'Subscribed';


        if ($recipientFound == 0) {
            //check pending
            $url = $mailUp->getConsoleEndpoint() . "/Console/List/$list_id/Recipients/pending?filterby=\"Email.Contains('$email')\"";
            $recipientExists = $mailUp->callMethod($url, "GET", null, "JSON");
            $recipientExists = json_decode($recipientExists);
            $recipientFound = $recipientExists->TotalElementsCount;
            $recipientStatus = 'Pending';
        }

        if ($recipientFound == 0) {
            //check unsubscribed
            $url = $mailUp->getConsoleEndpoint() . "/Console/List/$list_id/Recipients/unsubscribed?filterby=\"Email.Contains('$email')\"";
            $recipientExists = $mailUp->callMethod($url, "GET", null, "JSON");
            $recipientExists = json_decode($recipientExists);
            $recipientFound = $recipientExists->TotalElementsCount;
            $recipientStatus = 'Unsubscribed';
        }


        if ($recipientFound) {
            $result['found'] = 1;
            $result['status'] = $recipientStatus;
            $result['info'] = $recipientExists;
        } else {
            $result['found'] = 0;
            $result['status'] = '';
            $result['info'] = $recipientExists;
        }




        return $result;
    }

    public function process_merge_vars($feed, $entry, $form, $override_empty_fields, $field_map) {

        foreach ($field_map as $name => $field_id) {

            if ($name == 'EMAIL') {
                continue;
            }

            // $field_id can also be a string like 'date_created'
            switch (strtolower($field_id)) {
                case 'form_title':
                    $merge_vars[$name] = rgar($form, 'title');
                    break;

                case 'date_created':
                case 'ip':
                case 'source_url':
                    $merge_vars[$name] = rgar($entry, strtolower($field_id));
                    break;

                default :
                    $field = RGFormsModel::get_field($form, $field_id);
                    $is_integer = $field_id == intval($field_id);
                    $input_type = RGFormsModel::get_input_type($field);
                    $field_value = rgar($entry, $field_id);

                    // handling full address
                    if ($is_integer && $input_type == 'address') {
                        $field_value = $this->get_address($entry, $field_id);
                    } // handling full name
                    elseif ($is_integer && $input_type == 'name') {
                        $field_value = $this->get_name($entry, $field_id);
                    } // handling phone
                    elseif ($is_integer && $input_type == 'phone' && $field['phoneFormat'] == 'standard') {
                        // reformat phone to go to mailup when standard format (US/CAN)
                        // needs to be in the format NPA-NXX-LINE 404-555-1212 when US/CAN
                        $phone = $field_value;
                        if (preg_match('/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $phone, $matches)) {
                            $field_value = sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
                        }
                    } // send selected checkboxes as a concatenated string
                    elseif ($is_integer && $input_type == 'checkbox') {
                        $selected = array();
                        foreach ($field['inputs'] as $input) {
                            $index = (string) $input['id'];
                            if (!rgempty($index, $entry)) {
                                $selected[] = apply_filters('gform_mailup_field_value', rgar($entry, $index), $form['id'], $field_id, $entry, $name);
                            }
                        }
                        $field_value = join(', ', $selected);
                    }

                    if (empty($field_value) && !$override_empty_fields) {
                        break;
                    } else {
                        $merge_vars[$name] = apply_filters('gform_mailup_field_value', $field_value, $form['id'], $field_id, $entry, $name);
                    }
            }
        }

        return $merge_vars;
    }

    public function process_feed_old($feed, $entry, $form) {
        $this->log_debug(__METHOD__ . '(): Processing feed.');

        // login to MailUp
        $api = $this->get_api();
        if (!is_object($api)) {
            $this->log_error(__METHOD__ . '(): Failed to set up the API.');

            return;
        }

        $feed_meta = $feed['meta'];

        $double_optin = $feed_meta['double_optin'] == true;
        $send_welcome = $feed_meta['sendWelcomeEmail'] == true;

        // retrieve name => value pairs for all fields mapped in the 'mappedFields' field map
        $field_map = $this->get_field_map_fields($feed, 'mappedFields');
        $email = rgar($entry, $field_map['EMAIL']);

        $override_empty_fields = apply_filters("gform_mailup_override_empty_fields_{$form['id']}", apply_filters('gform_mailup_override_empty_fields', true, $form, $entry, $feed), $form, $entry, $feed);
        if (!$override_empty_fields) {
            $this->log_debug(__METHOD__ . '(): Empty fields will not be overridden.');
        }

        $merge_vars = array('');
        foreach ($field_map as $name => $field_id) {

            if ($name == 'EMAIL') {
                continue;
            }

            // $field_id can also be a string like 'date_created'
            switch (strtolower($field_id)) {
                case 'form_title':
                    $merge_vars[$name] = rgar($form, 'title');
                    break;

                case 'date_created':
                case 'ip':
                case 'source_url':
                    $merge_vars[$name] = rgar($entry, strtolower($field_id));
                    break;

                default :
                    $field = RGFormsModel::get_field($form, $field_id);
                    $is_integer = $field_id == intval($field_id);
                    $input_type = RGFormsModel::get_input_type($field);
                    $field_value = rgar($entry, $field_id);

                    // handling full address
                    if ($is_integer && $input_type == 'address') {
                        $field_value = $this->get_address($entry, $field_id);
                    } // handling full name
                    elseif ($is_integer && $input_type == 'name') {
                        $field_value = $this->get_name($entry, $field_id);
                    } // handling phone
                    elseif ($is_integer && $input_type == 'phone' && $field['phoneFormat'] == 'standard') {
                        // reformat phone to go to mailup when standard format (US/CAN)
                        // needs to be in the format NPA-NXX-LINE 404-555-1212 when US/CAN
                        $phone = $field_value;
                        if (preg_match('/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $phone, $matches)) {
                            $field_value = sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
                        }
                    } // send selected checkboxes as a concatenated string
                    elseif ($is_integer && $input_type == 'checkbox') {
                        $selected = array();
                        foreach ($field['inputs'] as $input) {
                            $index = (string) $input['id'];
                            if (!rgempty($index, $entry)) {
                                $selected[] = apply_filters('gform_mailup_field_value', rgar($entry, $index), $form['id'], $field_id, $entry, $name);
                            }
                        }
                        $field_value = join(', ', $selected);
                    }

                    if (empty($field_value) && !$override_empty_fields) {
                        break;
                    } else {
                        $merge_vars[$name] = apply_filters('gform_mailup_field_value', $field_value, $form['id'], $field_id, $entry, $name);
                    }
            }
        }

        $mc_groupings = $this->get_mailup_groups($feed_meta['mailupList']);
        $groupings = array();

        if ($mc_groupings !== false) {
            foreach ($mc_groupings as $grouping) {

                if (!is_array($grouping['groups'])) {
                    continue;
                }

                $groups = array();

                foreach ($grouping['groups'] as $group) {

                    $group_settings = $this->get_group_settings($group, $grouping['id'], $feed);
                    if (!$this->is_group_condition_met($group_settings, $form, $entry)) {
                        continue;
                    }

                    $groups[] = $group['name'];
                }

                if (!empty($groups)) {
                    $groupings[] = array(
                        'name' => $grouping['name'],
                        'groups' => $groups,
                    );
                }
            }
        }


        if (!empty($groupings)) {
            $merge_vars['GROUPINGS'] = $groupings;
        }

        $this->log_debug(__METHOD__ . "(): Checking to see if $email is already on the list.");
        $list_id = $feed_meta['mailupList'];
        try {
            $params = array(
                'id' => $list_id,
                'emails' => array(
                    array('email' => $email),
                )
            );
            $member_info = $api->call('lists/member-info', $params);
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
        }

        if (empty($member_info)) {
            $this->log_error(__METHOD__ . '(): There was an error while trying to retrieve member information. Unable to process feed.');

            return;
        }

        $subscribe_or_update = false;
        $member_not_found = absint(rgar($member_info, 'error_count')) > 0;
        $member_status = rgars($member_info, 'data/0/status');

        if ($member_not_found || $member_status != 'subscribed') {
            $allow_resubscription = apply_filters('gform_mailup_allow_resubscription', apply_filters("gform_mailup_allow_resubscription_{$form['id']}", true, $form, $entry, $feed), $form, $entry, $feed);
            if ($member_status == 'unsubscribed' && !$allow_resubscription) {
                $this->log_debug(__METHOD__ . '(): User is unsubscribed and resubscription is not allowed.');

                return true;
            }

            // adding member to list, statuses of $member_status != 'subscribed', 'pending', 'cleaned' need to be
            // 're-subscribed' to send out confirmation email
            $this->log_debug(__METHOD__ . "(): {$email} is either not on the list or on the list but the status is not subscribed - status: " . $member_status . '; adding to list.');
            $transaction = 'Subscribe';
            try {
                $params = array(
                    'id' => $list_id,
                    'email' => array('email' => $email),
                    'merge_vars' => $merge_vars,
                    'email_type' => 'html',
                    'double_optin' => $double_optin,
                    'update_existing' => false,
                    'replace_interests' => true,
                    'send_welcome' => $send_welcome,
                );
                $this->log_debug(__METHOD__ . '(): Calling - subscribe, Parameters ' . print_r($params, true));
                $subscribe_or_update = $api->call('lists/subscribe', $params);
            } catch (Exception $e) {
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
            }
        } else {
            // updating member
            $this->log_debug(__METHOD__ . "(): {$email} is already on the list; updating info.");

            // retrieve existing groups for subscribers
            $current_groups = rgar($member_info['data'][0]['merges'], 'GROUPINGS');

            $keep_existing_groups = apply_filters("gform_mailup_keep_existing_groups_{$form['id']}", apply_filters('gform_mailup_keep_existing_groups', true, $form, $entry, $feed), $form, $entry, $feed);
            if (is_array($current_groups) && $keep_existing_groups) {
                // add existing groups to selected groups from form so that existing groups are maintained for that subscriber
                $merge_vars = $this->append_groups($merge_vars, $current_groups);
            }

            $transaction = 'Update';

            $params = apply_filters("gform_mailup_args_pre_subscribe_{$form['id']}", apply_filters('gform_mailup_args_pre_subscribe', $params, $form, $entry, $feed), $form, $entry, $feed);

            try {
                $params = array(
                    'id' => $list_id,
                    'email' => array('email' => $email),
                    'merge_vars' => $merge_vars,
                    'email_type' => 'html',
                    'replace_interests' => true,
                );
                $this->log_debug(__METHOD__ . '(): Calling - update-member, Parameters ' . print_r($params, true));
                $subscribe_or_update = $api->call('lists/update-member', $params);
            } catch (Exception $e) {
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
            }
        }

        if (rgar($subscribe_or_update, 'email')) {
            //email will be returned if successful
            $this->log_debug(__METHOD__ . "(): {$transaction} successful.");
        } else {
            $this->log_error(__METHOD__ . "(): {$transaction} failed.");
        }
    }

    //------- Helpers ----------------

    private function get_api() {




        if (self::$api) {

            return self::$api;
        }




        $api = null;


            if (!class_exists('MailUpClient')) {
                require_once( 'api/MailUpClient.php' );

            }


            $this->log_debug(__METHOD__ . '(): Retrieving API Info for key ' . $this->mailup_access_token);

            try {

                $api = new MailUpClient($this->mailup_client_id, $this->mailup_client_secret, $this->mailup_callback_uri);
            } catch (Exception $e) {
                $this->log_error(__METHOD__ . '(): Failed to set up the API.');
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());

                return null;
            }


        if (!is_object($api)) {
            $this->log_error(__METHOD__ . '(): Failed to set up the API.');

            return null;
        }

        $this->log_debug(__METHOD__ . '(): Successful API response received.');
        self::$api = $api;

        return self::$api;
    }

    private function get_list_name($list_id) {


        if (!$list_id) {
            return __('No list related', 'gravityformsmailup');
        } else {
            try {
                $mailUp = $this->get_api();

                $url = $mailUp->getConsoleEndpoint() . "/Console/User/Lists?filterby=[idList==$list_id]";
                $current_list = $mailUp->callMethod($url, "GET", null, "JSON");
                $current_list = json_decode($current_list);

                //print_r($groups);
            } catch (MailUpException $e) {
                $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
                $current_list = array();
                return ($e->getCode() . ' - ' . $e->getMessage());
            }


            if ($current_list->TotalElementsCount == 0) {
                return __('No list related', 'gravityformsmailup');
            } else {
                return ($current_list->Items[0]->Name);
            }
        }
    }

    public function get_current_mailup_list() {
        return $this->get_setting('mailupList');
    }

    private function get_mailup_groups($mailup_list = false) {

        $this->log_debug(__METHOD__ . '(): Retrieving groups.');



        if (!$mailup_list) {
            $mailup_list = $this->get_current_mailup_list();
        }



        if (!$mailup_list) {
            $this->log_error(__METHOD__ . '(): Could not find mailup list.');

            return false;
        }

        try {
            $mailUp = $this->get_api();
            $url = $mailUp->getConsoleEndpoint() . "/Console/List/$mailup_list/Groups?orderby=\"Name+asc\"";
            $groups = $mailUp->callMethod($url, "GET", null, "JSON");
            $groups = json_decode($groups);

            //print_r($groups);
        } catch (MailUpException $e) {
            echo $e->getCode() . ': ' . $e->getMessage();
            $this->log_error(__METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage());
            $groups = array();
        }


        return $groups;
    }

    private function get_group_settings($group, $grouping_id, $feed) {

        $prefix = $this->get_group_setting_key($grouping_id, $group->Name) . '_';
        $props = array('enabled', 'decision', 'field_id', 'operator', 'value');

        $settings = array();
        foreach ($props as $prop) {
            $settings[$prop] = rgar($feed['meta'], "{$prefix}{$prop}");
        }

        return $settings;
    }

    public function get_group_setting_key($grouping_id, $group_name) {

        $plugin_settings = GFCache::get('mailup_plugin_settings');
        if (empty($plugin_settings)) {
            $plugin_settings = $this->get_plugin_settings();
            GFCache::set('mailup_plugin_settings', $plugin_settings);
        }

        $key = 'group_key_' . $grouping_id . '_' . str_replace('%', '', sanitize_title_with_dashes($group_name));

        if (!isset($plugin_settings[$key])) {
            $group_key = 'mc_group_' . uniqid();
            $plugin_settings[$key] = $group_key;
            $this->update_plugin_settings($plugin_settings);
            GFCache::set('mailup_plugin_settings', $plugin_settings);
        }

        return $plugin_settings[$key];
    }

    public static function is_group_condition_met($group, $form, $entry) {

        $field = RGFormsModel::get_field($form, $group['field_id']);
        $field_value = RGFormsModel::get_lead_field_value($entry, $field);

        $is_value_match = RGFormsModel::is_value_match($field_value, $group['value'], $group['operator'], $field);

        if (!$group['enabled']) {
            return false;
        } else if ($group['decision'] == 'always' || empty($field)) {
            return true;
        } else {
            return $is_value_match;
        }
    }



    public static function get_existing_groups($grouping_name, $current_groupings) {

        foreach ($current_groupings as $grouping) {
            if (strtolower($grouping['name']) == strtolower($grouping_name)) {
                return $grouping['groups'];
            }
        }

        return array();
    }

    private function get_address($entry, $field_id) {
        $street_value = str_replace('  ', ' ', trim(rgar($entry, $field_id . '.1')));
        $street2_value = str_replace('  ', ' ', trim(rgar($entry, $field_id . '.2')));
        $city_value = str_replace('  ', ' ', trim(rgar($entry, $field_id . '.3')));
        $state_value = str_replace('  ', ' ', trim(rgar($entry, $field_id . '.4')));
        $zip_value = trim(rgar($entry, $field_id . '.5'));
        $country_value = trim(rgar($entry, $field_id . '.6'));

        if (!empty($country_value)) {
            $country_value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_country_code($country_value) : GFCommon::get_country_code($country_value);
        }

        $address = array(
            !empty($street_value) ? $street_value : '-',
            $street2_value,
            !empty($city_value) ? $city_value : '-',
            !empty($state_value) ? $state_value : '-',
            !empty($zip_value) ? $zip_value : '-',
            $country_value,
        );

        return implode('  ', $address);
    }

    private function get_name($entry, $field_id) {

        //If field is simple (one input), simply return full content
        $name = rgar($entry, $field_id);
        if (!empty($name)) {
            return $name;
        }

        //Complex field (multiple inputs). Join all pieces and create name
        $prefix = trim(rgar($entry, $field_id . '.2'));
        $first = trim(rgar($entry, $field_id . '.3'));
        $middle = trim(rgar($entry, $field_id . '.4'));
        $last = trim(rgar($entry, $field_id . '.6'));
        $suffix = trim(rgar($entry, $field_id . '.8'));

        $name = $prefix;
        $name .=!empty($name) && !empty($first) ? ' ' . $first : $first;
        $name .=!empty($name) && !empty($middle) ? ' ' . $middle : $middle;
        $name .=!empty($name) && !empty($last) ? ' ' . $last : $last;
        $name .=!empty($name) && !empty($suffix) ? ' ' . $suffix : $suffix;

        return $name;
    }

    //------ Temporary Notice for Main Menu --------------------//

    public function maybe_create_menu($menus) {

        $current_user = wp_get_current_user();
        $dismiss_mailup_menu = get_metadata('user', $current_user->ID, 'dismiss_mailup_menu', true);
        if ($dismiss_mailup_menu != '1') {
            $menus[] = array(
                'name' => $this->_slug,
                'label' => $this->get_short_title(),
                'callback' => array($this, 'temporary_plugin_page'),
                'permission' => $this->_capabilities_form_settings
            );
        }

        return $menus;
    }

    public function ajax_dismiss_menu() {

        $current_user = wp_get_current_user();
        update_metadata('user', $current_user->ID, 'dismiss_mailup_menu', '1');
    }

    public function temporary_plugin_page() {
        $current_user = wp_get_current_user();
        ?>
        <script type="text/javascript">
            function dismissMenu() {
                jQuery('#gf_spinner').show();
                jQuery.post(ajaxurl, {
                    action: "gf_dismiss_mailup_menu"
                },
                function (response) {
                    document.location.href = '?page=gf_edit_forms';
                    jQuery('#gf_spinner').hide();
                }
                );

            }
        </script>

        <div class="wrap about-wrap">
            <h1><?php _e('MailUp Add-On Beta Version', 'gravityformsmailup') ?></h1>

            <div
                class="about-text"><?php _e('The Gravity Forms MailUp Add-On makes changes to how you manage your MailUp integration.', 'gravityformsmailup') ?></div>
            <div class="changelog">
                <hr/>
                <div class="feature-section col two-col">
                    <div class="col-1">
                        <h3><?php _e('Manage MailUp Contextually', 'gravityformsmailup') ?></h3>

                        <p><?php _e('MailUp Feeds are now accessed via the MailUp sub-menu within the Form Settings for the Form with which you would like to integrate MailUp.', 'gravityformsmailup') ?></p>
                    </div>
                    <div class="col-2 last-feature">
                        <img src="http://help.mailup.com/download/attachments/589825/global.logo?version=10&modificationDate=1422283014417&api=v2">
                    </div>
                </div>

                <hr/>

                <form method="post" id="dismiss_menu_form" style="margin-top: 20px;">
                    <input type="checkbox" name="dismiss_mailup_menu" value="1" onclick="dismissMenu();">
                    <label><?php _e('I understand this change, dismiss this message!', 'gravityformsmailup') ?></label>
                    <img id="gf_spinner" src="<?php echo GFCommon::get_base_url() . '/images/spinner.gif' ?>"
                         alt="<?php _e('Please wait...', 'gravityformsmailup') ?>" style="display:none;"/>
                </form>

            </div>
        </div>
        <?php
    }

    //------ FOR BACKWARDS COMPATIBILITY ----------------------//
    //Migrate existing data to new table structure
    public function upgrade($previous_version) {

        $previous_is_pre_addon_framework = empty($previous_version) || version_compare($previous_version, '3.0.dev1', '<');

        if ($previous_is_pre_addon_framework) {

            //get old plugin settings
            $old_settings = get_option('gf_mailup_settings');
            //remove username and password from the old settings; these were very old legacy api settings that we do not support anymore

            if (is_array($old_settings)) {

                foreach ($old_settings as $id => $setting) {
                    if ($id != 'username' && $id != 'password') {
                        if ($id == 'apikey') {
                            $id = 'apiKey';
                        }
                        $new_settings[$id] = $setting;
                    }
                }
                $this->update_plugin_settings($new_settings);
            }

            //get old feeds
            $old_feeds = $this->get_old_feeds();

            if ($old_feeds) {

                $counter = 1;
                foreach ($old_feeds as $old_feed) {
                    $feed_name = 'Feed ' . $counter;
                    $form_id = $old_feed['form_id'];
                    $is_active = rgar($old_feed, 'is_active') ? '1' : '0';
                    $field_maps = rgar($old_feed['meta'], 'field_map');
                    $groups = rgar($old_feed['meta'], 'groups');
                    $list_id = rgar($old_feed['meta'], 'contact_list_id');

                    $new_meta = array(
                        'feedName' => $feed_name,
                        'mailupList' => $list_id,
                        'double_optin' => rgar($old_feed['meta'], 'double_optin') ? '1' : '0',
                        'sendWelcomeEmail' => rgar($old_feed['meta'], 'welcome_email') ? '1' : '0',
                    );

                    //add mappings
                    foreach ($field_maps as $key => $mapping) {
                        $new_meta['mappedFields_' . $key] = $mapping;
                    }

                    if (!empty($groups)) {
                        $group_id = 0;
                        //add groups to meta
                        //get the groups from mailup because we need to use the main group id to build the key used to map the fields
                        //old data only has the text, use the text to get the id
                        $mailup_groupings = $this->get_mailup_groups($list_id);

                        //loop through the existing feed data to create mappings for new tables
                        foreach ($groups as $key => $group) {
                            //get the name of the top level group so the id can be retrieved from the mailup data
                            foreach ($mailup_groupings as $mailup_group) {
                                if (str_replace('%', '', sanitize_title_with_dashes($mailup_group['name'])) == $key) {
                                    $group_id = $mailup_group['id'];
                                    break;
                                }
                            }

                            if (is_array($group)) {
                                foreach ($group as $subkey => $subgroup) {
                                    $setting_key_root = $this->get_group_setting_key($group_id, $subgroup['group_label']);
                                    $new_meta[$setting_key_root . '_enabled'] = rgar($subgroup, 'enabled') ? '1' : '0';
                                    $new_meta[$setting_key_root . '_decision'] = rgar($subgroup, 'decision');
                                    $new_meta[$setting_key_root . '_field_id'] = rgar($subgroup, 'field_id');
                                    $new_meta[$setting_key_root . '_operator'] = rgar($subgroup, 'operator');
                                    $new_meta[$setting_key_root . '_value'] = rgar($subgroup, 'value');
                                }
                            }
                        }
                    }

                    //add conditional logic, legacy only allowed one condition
                    $conditional_enabled = rgar($old_feed['meta'], 'optin_enabled');
                    if ($conditional_enabled) {
                        $new_meta['feed_condition_conditional_logic'] = 1;
                        $new_meta['feed_condition_conditional_logic_object'] = array(
                            'conditionalLogic' =>
                            array(
                                'actionType' => 'show',
                                'logicType' => 'all',
                                'rules' => array(
                                    array(
                                        'fieldId' => rgar($old_feed['meta'], 'optin_field_id'),
                                        'operator' => rgar($old_feed['meta'], 'optin_operator'),
                                        'value' => rgar($old_feed['meta'], 'optin_value')
                                    ),
                                )
                            )
                        );
                    } else {
                        $new_meta['feed_condition_conditional_logic'] = 0;
                    }

                    $this->insert_feed($form_id, $is_active, $new_meta);
                    $counter ++;
                }

                //set paypal delay setting
                $this->update_paypal_delay_settings('delay_mailup_subscription');
            }
        }
    }

    public function ensure_upgrade() {

        if (get_option('gf_mailup_update')) {
            return false;
        }

        $feeds = $this->get_feeds();
        if (empty($feeds)) {

            //Force Add-On framework upgrade
            $this->upgrade('2.0');
        }

        update_option('gf_mailup_update', 1);
    }

    public function update_paypal_delay_settings($old_delay_setting_name) {
        global $wpdb;
        $this->log_debug(__METHOD__ . '(): Checking to see if there are any delay settings that need to be migrated for PayPal Standard.');

        $new_delay_setting_name = 'delay_' . $this->_slug;

        //get paypal feeds from old table
        $paypal_feeds_old = $this->get_old_paypal_feeds();

        //loop through feeds and look for delay setting and create duplicate with new delay setting for the framework version of PayPal Standard
        if (!empty($paypal_feeds_old)) {
            $this->log_debug(__METHOD__ . '(): Old feeds found for ' . $this->_slug . ' - copying over delay settings.');
            foreach ($paypal_feeds_old as $old_feed) {
                $meta = $old_feed['meta'];
                if (!rgempty($old_delay_setting_name, $meta)) {
                    $meta[$new_delay_setting_name] = $meta[$old_delay_setting_name];
                    //update paypal meta to have new setting
                    $meta = maybe_serialize($meta);
                    $wpdb->update("{$wpdb->prefix}rg_paypal", array('meta' => $meta), array('id' => $old_feed['id']), array('%s'), array('%d'));
                }
            }
        }

        //get paypal feeds from new framework table
        $paypal_feeds = $this->get_feeds_by_slug('gravityformspaypal');
        if (!empty($paypal_feeds)) {
            $this->log_debug(__METHOD__ . '(): New feeds found for ' . $this->_slug . ' - copying over delay settings.');
            foreach ($paypal_feeds as $feed) {
                $meta = $feed['meta'];
                if (!rgempty($old_delay_setting_name, $meta)) {
                    $meta[$new_delay_setting_name] = $meta[$old_delay_setting_name];
                    $this->update_feed_meta($feed['id'], $meta);
                }
            }
        }
    }

    public function get_old_paypal_feeds() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rg_paypal';

        $form_table_name = GFFormsModel::get_form_table_name();
        $sql = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
				FROM {$table_name} s
				INNER JOIN {$form_table_name} f ON s.form_id = f.id";

        $this->log_debug(__METHOD__ . "(): getting old paypal feeds: {$sql}");

        $results = $wpdb->get_results($sql, ARRAY_A);

        $this->log_debug(__METHOD__ . "(): error?: {$wpdb->last_error}");

        $count = sizeof($results);

        $this->log_debug(__METHOD__ . "(): count: {$count}");

        for ($i = 0; $i < $count; $i ++) {
            $results[$i]['meta'] = maybe_unserialize($results[$i]['meta']);
        }

        return $results;
    }

    public function get_old_feeds() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rg_mailup';

        $form_table_name = GFFormsModel::get_form_table_name();
        $sql = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
					FROM $table_name s
					INNER JOIN $form_table_name f ON s.form_id = f.id";

        $results = $wpdb->get_results($sql, ARRAY_A);

        $count = sizeof($results);
        for ($i = 0; $i < $count; $i ++) {
            $results[$i]['meta'] = maybe_unserialize($results[$i]['meta']);
        }

        return $results;
    }

}
