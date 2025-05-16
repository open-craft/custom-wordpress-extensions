<?php
/**
 * @package OpenCraft_Custom_Extensions
 * @version 0.0.1
 */
/*
Plugin Name: OpenCraft Custom Extensions
Plugin URI: https://github.com/open-craft/opencraft-wordpress-extensions
Description: Custom functionality specific to opencraft.com's website, including custom TwentyCRM integration.
Author: OpenCraft
Version: 0.0.1
Author URI: https://opencraft.com/
 */

/**
 * Register our settings initializer to the admin initialization sequence.
 */
add_action('admin_init', 'add_opencraft_plugin_settings');
function add_opencraft_plugin_settings()
{
    register_setting('twenty', 'twenty_options');

    add_settings_section(
        'twenty_section_settings',
        __("Twenty Integration", 'twenty'),
        'twenty_section_settings_callback',
        'twenty',
    );
    // Register a new field in the "twenty_section_settings" section, inside the "twenty" page.
    add_settings_field(
        'twenty_field_token', // As of WP 4.6 this value is used only internally.
        // Use $args' label_for to populate the id inside the callback.
        __('API Token', 'twenty'),
        'twenty_field_token_cb',
        'twenty',
        'twenty_section_settings',
        array(
            'label_for' => 'twenty_field_token',
            'class' => 'twenty_row',
        )
    );
    add_settings_field(
        'twenty_field_base_url',
        __('Endpoint', 'twenty'),
        'twenty_field_base_url_cb',
        'twenty',
        'twenty_section_settings',
        array(
            'label_for' => 'twenty_field_base_url',
            'class' => 'twenty_row',
        )
    );
}

function twenty_section_settings_callback($args)
{
    ?>
    <p id="<?php echo esc_attr($args['id']); ?>">
        <?php esc_html_e('Configure the integration with TwentyCRM below.', 'twenty'); ?>
        <a href="https://github.com/open-craft/opencraft-wordpress-extensions"><?php
            esc_html_e('Plugin source code here.', 'twenty');
            ?></a>
    </p>
    <?php
}

function render_input($args, $options)
{
    ?>
    <input
            id="<?php echo esc_attr($args['label_for']); ?>"
            value="<?php echo esc_attr($options[$args['label_for']]) ?>"
            name="twenty_options[<?php echo esc_attr($args['label_for']); ?>]">
    </input>
    <?php
}

function twenty_field_token_cb($args)
{
    // Get the value of the setting we've registered with register_setting()
    $options = get_option('twenty_options');
    render_input($args, $options);
    ?>
    <p class="description">
        <?php esc_html_e('Enter your API key for Twenty CRM.', 'twenty'); ?>
    </p>
    <?php
}

function twenty_field_base_url_cb($args)
{
    // Get the value of the setting we've registered with register_setting()
    $options = get_option('twenty_options');
    render_input($args, $options);
    ?>
    <p class="description">
        <?php esc_html_e('Enter the base API URL of your TwentyCRM Installation, including trailing slash. Example: https://twenty.example.com/rest/', 'twenty'); ?>
    </p>
    <?php
}

/**
 * Add the top level menu page.
 */
add_action('admin_menu', 'wporg_options_page');
function wporg_options_page()
{
    add_menu_page(
        'Twenty',
        'TwentyCRM',
        'manage_options',
        'twenty',
        'twenty_options_page_html',
    );
}

/**
 * Top level menu callback function
 */
function twenty_options_page_html()
{
    // check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // add error/update messages

    // check if the user have submitted the settings
    // WordPress will add the "settings-updated" $_GET parameter to the url
    if (isset($_GET['settings-updated'])) {
        // add settings saved message with the class of "updated"
        add_settings_error('twenty_messages', 'twenty_message', __('Settings Saved', 'twenty'), 'updated');
    }

    // show error/update messages
    settings_errors('twenty_messages');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            // output security fields for the registered setting "wporg"
            settings_fields('twenty');
            // output setting sections and their fields
            // (sections are registered for "twenty", each field is registered to a specific section)
            do_settings_sections('twenty');
            // output save settings button
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}


/// This next section augments gravity forms.

function guidv4()
{
    // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
    $data = random_bytes(16);

    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    // Output the 36 character UUID.
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function call_twenty($url, $data)
{
    $options = get_option('twenty_options');
    if (!$options) {
        error_log("Could not locate options for TwentyCRM upload.");
        return false;
    }
    $token = preg_replace('/\s+/', '', $options['twenty_field_token']);
    $base_url = $options['twenty_field_base_url'];
    if (!$token) {
        error_log("Token not set for TwentyCRM.");
        return false;
    }
    if (!$base_url) {
        error_log("Base URL not set for TwentyCRM");
        return false;
    }
    $url = $base_url . $url;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        array(
            "Authorization: Bearer {$token}",
            "Content-Type: application/json",
        ),
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if (!$result) {
        error_log("Curl error: " . curl_error($ch));
    }
    curl_close($ch);
    return $result;
}

/*
We use GravityForms, and GravityForms has a Webhook feature that allows us
to post to an endpoint. It even allows JSON!

...Except that we can't change the structure of the JSON. It has to be a flat
hash, no nested entries. That won't work for our install of TwentyCRM, which
has a complex data structure for its calls. In fact, we need to do multiple
calls here, so even using the (much simpler) filter that changes the data
structure won't work.

This action extracts the data from the forms and gets the info from them
to send to the multiple endpoints of TwentyCRM.
*/
add_action('gform_after_submission_1', 'after_submission', 10, 2);
function after_submission($entry, $form)
{
    $fields = array();
    // We have to get fields by their index from the form, but it's not clear
    // what these fields are if we just grab them by index. Let's construct
    // a map that results in code that's easier to read.
    foreach ($form['fields'] as $value) {
        // Some of the forms are manually appended with the word "(Optional)"
        // to satisfy the designers. Clip that away since it doesn't matter
        // for our case and it'll be easier to read without it.
        $fields[trim(explode("(", $value["label"])[0])] = strval($value["id"]);
    }

    $full_name = $entry[$fields['Name']];
    $name_segments = array_filter(
        explode(
            " ",
            $full_name,
        ),
        function ($str) {
            return $str !== "";
        }
    );
    $first_name = $name_segments[0];
    if (count($name_segments) > 1) {
        $last_name = implode(" ", array_slice($name_segments, 1));
    } else {
        $last_name = "";
    }
    $organization_name = $entry[$fields["Organization"]];
    $organization = null;
    if (strlen($organization_name)) {
        $organization = array(
            "id" => guidv4(),
            "name" => $organization_name,
        );
    }
    $person = array(
        // We can manually set a generated ID here to simplify implementation.
        "id" => guidv4(),
        "name" => array(
            "firstName" => $first_name,
            "lastName" => $last_name,
        ),
        "emails" => array(
            "primaryEmail" => $entry[$fields["Email"]],
        ),
        "jobTitle" => $entry[$fields["Title"]],
    );
    $opportunity = array(
        "name" => $full_name,
        "stage" => "NEW",
        "description" => $entry[$fields["How can we help?"]],
        "pointOfContactId" => $person["id"],
    );
    if ($organization) {
        $opportunity["companyId"] = $organization['id'];
        if (!call_twenty("companies", $organization)) {
            error_log("Failed posting company record.");
            return;
        }
    }
    $person_result = call_twenty("people", $person);
    if (!$person_result) {
        error_log("Failed posting person record.");
        return;
    }
    $opportunity_result = call_twenty("opportunities", $opportunity);
    if (!$opportunity_result) {
        error_log("Failed posting organization record.");
    }
}
