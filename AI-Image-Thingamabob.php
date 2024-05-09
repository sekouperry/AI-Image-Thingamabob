<?php

/*
Plugin Name: CodeWP AI Image Thingamabob
Plugin URI: https://codewp.ai
Description: This WordPress plugin uses the OpenAI API to automatically add alt text, caption, and description to images when they are added to the media library. The generated content is based on the image and is output in JSON format. The plugin includes a settings page where you can enter your OpenAI API key.
Version: 1.0
Author: Sekou Perry
Author URI: https://codewp.ai
License: GPLv2 or later
Text Domain: codewp
*/

// Add settings page to the admin menu
add_action("admin_menu", "codewp_add_settings_page");

function codewp_add_settings_page()
{
    add_options_page(
        "CodeWP AI Image Thingamabob",
        "CodeWP AI Image Thingamabob",
        "manage_options",
        "codewp-ai-image-thingamabob",
        "codewp_plugin_settings_page"
    );
}

// Display the plugin settings page
function codewp_plugin_settings_page()
{
    ?>
    <div class="wrap">
        <h2>CodeWP AI Image Thingamabob</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields("codewp_plugin_options");
            do_settings_sections("codewp-ai-image-thingamabob");
            submit_button();?>
        </form>
    </div>
    <?php
}

// Register and define the settings
add_action("admin_init", "codewp_admin_init");

function codewp_admin_init()
{
    register_setting(
        "codewp_plugin_options",
        "codewp_plugin_options",
        "codewp_plugin_options_validate"
    );
    add_settings_section(
        "codewp_main",
        "Main Settings",
        "codewp_section_text",
        "codewp-ai-image-thingamabob"
    );
    add_settings_field(
        "codewp_plugin_setting_api_key",
        "OpenAI API Key",
        "codewp_setting_api_key",
        "codewp-ai-image-thingamabob",
        "codewp_main"
    );
    add_settings_field(
        "codewp_plugin_setting_checkbox",
        "Enable Auto Fill",
        "codewp_setting_checkbox",
        "codewp-ai-image-thingamabob",
        "codewp_main"
    );
}

// Draw the section header
function codewp_section_text()
{
    echo "<p>Enter your settings here.</p>";
}

// Display and fill the form field
function codewp_setting_api_key()
{
    $options = get_option("codewp_plugin_options");
    echo "<input id='codewp_plugin_setting_api_key' name='codewp_plugin_options[api_key]' type='text' value='{$options["api_key"]}' />";
}

// Display and fill the checkbox
function codewp_setting_checkbox()
{
    $options = get_option("codewp_plugin_options");
    $checked = $options["enable_auto_fill"] ? "checked" : "";
    echo "<input id='codewp_plugin_setting_checkbox' name='codewp_plugin_options[enable_auto_fill]' type='checkbox' $checked />";
}

// Validate user input
function codewp_plugin_options_validate($input)
{
    return $input; // return validated input
}

// Hook into the 'add_attachment' action
add_filter("wp_generate_attachment_metadata", "codewp_add_image_meta", 10, 2);

function codewp_add_image_meta($metadata, $attachment_id)
{
    $options = get_option("codewp_plugin_options");
    if (empty($options["enable_auto_fill"])) {
        return $metadata;
    }
    $api_key = $options["api_key"];
    $url_of_img_uploaded = wp_get_attachment_image_src(
        $attachment_id,
        "medium_large"
    )[0];


    if (!$url_of_img_uploaded) {
        error_log("Optimized image URL not found.");
        return $metadata;
    }

    $data = [
        "model" => "gpt-4-vision-preview",
        "messages" => [
            [
                "role" => "user",
                "content" => [
                    [
                        "type" => "text",
                        "text" =>
                            "Based on this image, create alt text (under 10 words), a caption (about 1 sentence) and a description (under 3 sentences). Output these in JSON format: {\"description\": \"\", \"alt\": \"\", \"caption\": \"\"}. It must be JSON RFC 8259. It must NOT include any additional characters, like markdown, '```json', etc... Begin with the '{' character.",
                    ],
                    [
                        "type" => "image_url",
                        "image_url" => [
                            "url" => $url_of_img_uploaded,
                        ],
                    ],
                ],
            ],
        ],
        "max_tokens" => 2000,
    ];

    $response = wp_remote_post("https://api.openai.com/v1/chat/completions", [
        "headers" => [
            "Content-Type" => "application/json",
            "Authorization" => "Bearer " . $api_key,
        ],
        "body" => json_encode($data),
        "method" => "POST",
        "data_format" => "body",
        "timeout" => 30,
    ]);

    if (is_wp_error($response)) {
        error_log(
            "Error in codewp_add_image_meta: " . $response->get_error_message()
        );
        return $metadata;
    }

    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body, true);

    // Check if the needed data is present
    if (!isset($response_data["choices"][0]["message"]["content"])) {
        error_log("Error or invalid response format in codewp_add_image_meta");
        return $metadata;
    }

    // Extract the content field
    $content = $response_data["choices"][0]["message"]["content"];
    $content = json_decode($content);

    // Check if the content has the required fields
    if (!isset($content->description, $content->alt, $content->caption)) {
        error_log("Missing expected fields in response content");
        return $metadata;
    }

    update_post_meta($attachment_id, "_wp_attachment_image_alt", $content->alt);
    wp_update_post([
        "ID" => $attachment_id,
        "post_excerpt" => $content->caption,
        "post_content" => $content->description,
    ]);

    return $metadata;
}

?>
