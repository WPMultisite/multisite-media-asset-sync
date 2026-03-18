<?php
/**
 * Plugin Name: Multisite Media Asset Sync
 * Plugin URI: https://wpmultisite.com/plugins/multisite-media-asset-sync
 * Description: Sync site icons, custom logos, login page logos, default featured images, background images, header images, WooCommerce placeholder images, and Open Graph images across a multisite network.
 * Version: 1.0.7
 * Author: WPMultisite.com
 * Author URI: https://wpmultisite.com
 * Network: true
 * Text Domain: multisite-media-asset-sync
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Update URI: https://updates.wenpai.net
 */

if (!defined("ABSPATH")) {
    exit();
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wenpai-updater.php';
new WenPai_Updater( plugin_basename( __FILE__ ), '1.0.7' );

class Multisite_Media_Sync
{
    private static $instance = null;
    private $settings = [];
    private $asset_types = [];

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (!is_multisite()) {
            add_action("admin_notices", [$this, "multisite_required_notice"]);
            return;
        }

        $this->define_asset_types();
        $this->settings = get_site_option("multisite_media_sync_settings", [
            "excluded_sites" => [get_main_site_id()], // 默认排除主站点
            "auto_sync_new_sites" => true,
            "enabled_assets" => [
                "site_icon" => true,
                "custom_logo" => true,
                "login_logo" => true,
                "default_featured_image" => true,
                "og_image" => true,
                "background_image" => true,
                "header_image" => true,
                "woocommerce_placeholder_image" => true,
            ],
        ]);

        add_action("network_admin_menu", [$this, "add_plugin_page"]);
        add_action("admin_init", [$this, "register_settings"]);

        foreach ($this->asset_types as $asset_id => $asset) {
            if (isset($asset["option_name"])) {
                add_action(
                    "update_option_{$asset["option_name"]}",
                    [$this, "sync_media_asset"],
                    10,
                    3
                );
            }
        }

        add_action("customize_save_after", [$this, "handle_customizer_save"]);
        add_action("wp_initialize_site", [$this, "handle_new_site"]);
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), [
            $this,
            "add_settings_link",
        ]);
        add_action("wp_ajax_sync_media_to_single_site", [
            $this,
            "ajax_sync_to_single_site",
        ]);
        add_action("wp_ajax_sync_media_to_all", [$this, "ajax_sync_to_all"]);
        add_action("wp_ajax_save_sync_settings", [
            $this,
            "ajax_save_sync_settings",
        ]);
        add_action("wp_ajax_save_media_asset", [
            $this,
            "ajax_save_media_asset",
        ]);
        add_action("wp_ajax_remove_media_asset", [
            $this,
            "ajax_remove_media_asset",
        ]);
        add_action("wp_ajax_cleanup_duplicate_attachments", [
            $this,
            "ajax_cleanup_duplicate_attachments",
        ]);
        add_action("wp_ajax_save_site_management", [
            $this,
            "ajax_save_site_management",
        ]);

        add_action("login_enqueue_scripts", [$this, "customize_login_logo"]);
        add_filter(
            "get_post_metadata",
            [$this, "set_default_featured_image"],
            10,
            4
        );
        add_filter("wpseo_opengraph_image", [$this, "sync_yoast_og_image"]);
        add_filter("rankmath/opengraph/facebook/image", [
            $this,
            "sync_rankmath_og_image",
        ]);

        load_plugin_textdomain(
            "multisite-media-asset-sync",
            false,
            basename(dirname(__FILE__)) . "/languages"
        );
    }

    private function define_asset_types()
    {
        $this->asset_types = [
            "site_icon" => [
                "name" => __("Site Icon", "multisite-media-asset-sync"),
                "description" => __(
                    "The site favicon shown in browser tabs and bookmarks",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "site_icon",
                "customizer_section" => "title_tagline",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [48, 48],
                        true,
                        [
                            "class" => "site-icon-preview",
                            "style" =>
                                "object-fit: contain; width: 48px; height: 48px;",
                        ]
                    );
                },
            ],
            "custom_logo" => [
                "name" => __("Custom Logo", "multisite-media-asset-sync"),
                "description" => __(
                    "The main site logo displayed in your theme header",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "theme_mods_" . get_stylesheet(),
                "theme_mod_key" => "custom_logo",
                "customizer_section" => "title_tagline",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "custom-logo-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
            ],
            "login_logo" => [
                "name" => __("Login Page Logo", "multisite-media-asset-sync"),
                "description" => __(
                    "The logo displayed on the WordPress login page",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "multisite_media_sync_login_logo",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "login-logo-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
            ],
            "default_featured_image" => [
                "name" => __(
                    "Default Featured Image",
                    "multisite-media-asset-sync"
                ),
                "description" => __(
                    "The default image used when posts have no featured image set",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "multisite_media_sync_default_featured_image",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "default-featured-image-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
            ],
            "og_image" => [
                "name" => __("Open Graph Image", "multisite-media-asset-sync"),
                "description" => __(
                    "The default image used for social sharing (e.g., Facebook, Twitter)",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "multisite_media_sync_og_image",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "og-image-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
            ],
            "background_image" => [
                "name" => __(
                    "Default Background Image",
                    "multisite-media-asset-sync"
                ),
                "description" => __(
                    "The default background image for the site, synced across classic and block themes.",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "theme_mods_" . get_stylesheet(),
                "theme_mod_key" => "background_image",
                "customizer_section" => "background_image",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "background-image-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
            ],
            "header_image" => [
                "name" => __(
                    "Default Header Image",
                    "multisite-media-asset-sync"
                ),
                "description" => sprintf(
                    __(
                        "The default header image for classic themes. %s",
                        "multisite-media-asset-sync"
                    ),
                    wp_is_block_theme()
                        ? "<strong>" .
                            __(
                                "Not available in block themes.",
                                "multisite-media-asset-sync"
                            ) .
                            "</strong>"
                        : ""
                ),
                "option_name" => "theme_mods_" . get_stylesheet(),
                "theme_mod_key" => "header_image",
                "customizer_section" => "header_image",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "header-image-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
                "is_disabled" => wp_is_block_theme(),
            ],
            "woocommerce_placeholder_image" => [
                "name" => __(
                    "WooCommerce Placeholder Image",
                    "multisite-media-asset-sync"
                ),
                "description" => __(
                    "The default placeholder image for WooCommerce products.",
                    "multisite-media-asset-sync"
                ),
                "option_name" => "woocommerce_placeholder_image",
                "preview_callback" => function ($attachment_id) {
                    return wp_get_attachment_image(
                        $attachment_id,
                        [150, 150],
                        true,
                        [
                            "class" => "woocommerce-placeholder-preview",
                            "style" =>
                                "object-fit: contain; max-width: 150px; max-height: 150px;",
                        ]
                    );
                },
                "is_disabled" => !class_exists("WooCommerce"),
            ],
        ];
    }

    public function add_plugin_page()
    {
        add_submenu_page(
            "settings.php",
            __("Multisite Media Assets Sync", "multisite-media-asset-sync"),
            __("Media Sync", "multisite-media-asset-sync"),
            "manage_network_options",
            "multisite-media-asset-sync",
            [$this, "create_admin_page"]
        );
    }

    public function register_settings()
    {
        register_setting(
            "multisite_media_sync_group",
            "multisite_media_sync_settings"
        );
    }

    public function create_admin_page()
    {
        if (!current_user_can("manage_network_options")) {
            wp_die(
                __(
                    "You do not have sufficient permissions to access this page.",
                    "multisite-media-asset-sync"
                )
            );
        }

        wp_enqueue_media();

        $main_site_id = get_main_site_id();
        $current_assets = $this->get_current_assets();
        $sites = get_sites(["archived" => 0, "deleted" => 0]);
        $excluded_sites = $this->settings["excluded_sites"] ?? [];
        $enabled_assets = $this->settings["enabled_assets"] ?? [];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="card">
                <h2><?php _e(
                    "Media Assets from Main Site",
                    "multisite-media-asset-sync"
                ); ?></h2>
                <p><?php _e(
                    "These assets will be synchronized to other sites based on your settings.",
                    "multisite-media-asset-sync"
                ); ?></p>
                <span id="media-assets-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <div class="media-assets-preview">
                    <?php foreach (
                        $this->asset_types
                        as $asset_id => $asset
                    ): ?>
                        <div class="media-asset">
                            <h3><?php echo esc_html($asset["name"]); ?></h3>
                            <p><?php echo wp_kses_post(
                                $asset["description"]
                            ); ?></p>
                            <div class="asset-preview" data-asset-id="<?php echo esc_attr(
                                $asset_id
                            ); ?>">
                                <?php if (
                                    !empty($current_assets[$asset_id])
                                ): ?>
                                    <?php echo $asset["preview_callback"](
                                        $current_assets[$asset_id]
                                    ); ?>
                                <?php else: ?>
                                    <div class="empty"><?php _e(
                                        "Not set",
                                        "multisite-media-asset-sync"
                                    ); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (
                                isset($asset["is_disabled"]) &&
                                $asset["is_disabled"]
                            ): ?>
                                <p class="description" style="color: #dc3232;">
                                    <?php if ($asset_id === "header_image") {
                                        _e(
                                            "This feature is disabled in block themes.",
                                            "multisite-media-asset-sync"
                                        );
                                    } elseif (
                                        $asset_id ===
                                        "woocommerce_placeholder_image"
                                    ) {
                                        _e(
                                            "WooCommerce is not active on this site.",
                                            "multisite-media-asset-sync"
                                        );
                                    } ?>
                                </p>
                            <?php endif; ?>
                            <?php if (empty($asset["is_disabled"])): ?>
                                <input type="hidden" id="<?php echo esc_attr(
                                    $asset_id
                                ); ?>_id" value="<?php echo esc_attr(
    $current_assets[$asset_id] ?? ""
); ?>">
                                <button class="button select-media" data-asset-id="<?php echo esc_attr(
                                    $asset_id
                                ); ?>">
                                    <?php echo empty($current_assets[$asset_id])
                                        ? __(
                                            "Set Now",
                                            "multisite-media-asset-sync"
                                        )
                                        : __(
                                            "Change",
                                            "multisite-media-asset-sync"
                                        ); ?>
                                </button>
                                <?php if (
                                    !empty($current_assets[$asset_id])
                                ): ?>
                                    <button class="button remove-media" data-asset-id="<?php echo esc_attr(
                                        $asset_id
                                    ); ?>">
                                        <?php _e(
                                            "Remove",
                                            "multisite-media-asset-sync"
                                        ); ?>
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2><?php _e(
                    "Quick Sync",
                    "multisite-media-asset-sync"
                ); ?></h2>
                <p><?php _e(
                    "Sync media assets to all sites in the network immediately.",
                    "multisite-media-asset-sync"
                ); ?></p>
                <button id="sync-to-all" class="button button-primary">
                    <?php _e(
                        "Sync All Assets to All Sites",
                        "multisite-media-asset-sync"
                    ); ?>
                </button>
                <span id="sync-status" class="notice" style="display:none; margin-left: 10px;"></span>
            </div>

            <div class="card">
                <h2><?php _e(
                    "Cleanup Duplicates",
                    "multisite-media-asset-sync"
                ); ?></h2>
                <p><?php _e(
                    "Remove duplicate attachments across all sites based on original IDs.",
                    "multisite-media-asset-sync"
                ); ?></p>
                <button id="cleanup-duplicates" class="button button-secondary">
                    <?php _e(
                        "Clean Up Duplicates",
                        "multisite-media-asset-sync"
                    ); ?>
                </button>
                <span id="cleanup-status" class="notice" style="display:none; margin-left: 10px;"></span>
            </div>

            <div class="card">
                <h2><?php _e(
                    "Sync Settings",
                    "multisite-media-asset-sync"
                ); ?></h2>
                <span id="sync-settings-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <form id="sync-settings-form" method="post">
                    <h3><?php _e(
                        "Assets to Sync",
                        "multisite-media-asset-sync"
                    ); ?></h3>
                    <p><?php _e(
                        "Select which media assets you want to synchronize across your network.",
                        "multisite-media-asset-sync"
                    ); ?></p>

                    <div class="enabled-assets">
                        <?php foreach (
                            $this->asset_types
                            as $asset_id => $asset
                        ): ?>
                            <?php if (empty($asset["is_disabled"])): ?>
                                <label>
                                    <input type="checkbox" name="enabled_assets[<?php echo esc_attr(
                                        $asset_id
                                    ); ?>]"
                                        <?php checked(
                                            !empty($enabled_assets[$asset_id])
                                        ); ?>>
                                    <?php echo esc_html($asset["name"]); ?>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <h3><?php _e(
                        "New Sites",
                        "multisite-media-asset-sync"
                    ); ?></h3>
                    <p><label>
                        <input type="checkbox" name="auto_sync_new_sites"
                            <?php checked(
                                !empty($this->settings["auto_sync_new_sites"])
                            ); ?>>
                        <?php _e(
                            "Automatically sync media assets to new sites when they are created",
                            "multisite-media-asset-sync"
                        ); ?>
                    </label></p>

                    <button type="button" id="save-sync-settings" class="button button-primary">
                        <?php _e(
                            "Save Sync Settings",
                            "multisite-media-asset-sync"
                        ); ?>
                    </button>
                </form>
            </div>

            <div class="card">
                <h2><?php _e(
                    "Site Management",
                    "multisite-media-asset-sync"
                ); ?></h2>
                <p><?php _e(
                    "Manage which sites will receive synchronized media assets. The Source Site is excluded by default.",
                    "multisite-media-asset-sync"
                ); ?></p>
                <span id="site-management-status" class="notice" style="display:none; margin-top: 10px;"></span>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e(
                                "Site",
                                "multisite-media-asset-sync"
                            ); ?></th>
                            <th><?php _e(
                                "URL",
                                "multisite-media-asset-sync"
                            ); ?></th>
                            <th><?php _e(
                                "Include in Sync",
                                "multisite-media-asset-sync"
                            ); ?></th>
                            <th><?php _e(
                                "Actions",
                                "multisite-media-asset-sync"
                            ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sites as $site): ?>
                            <?php
                            $site_id = $site->blog_id;
                            $is_main_site = $site_id == $main_site_id;
                            $is_excluded = in_array($site_id, $excluded_sites);
                            $details = get_blog_details($site_id);
                            ?>
                            <tr<?php echo $is_main_site
                                ? ' class="main-site"'
                                : ""; ?>>
                                <td>
                                    <?php echo esc_html($details->blogname); ?>
                                    <?php if ($is_main_site): ?>
                                        <span class="main-site-badge"><?php _e(
                                            "(Main Site)",
                                            "multisite-media-asset-sync"
                                        ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(
                                        $details->siteurl
                                    ); ?>" target="_blank">
                                        <?php echo esc_html(
                                            $details->siteurl
                                        ); ?>
                                    </a>
                                </td>
                                <td class="sync-status">
                                    <?php if (!$is_main_site): ?>
                                        <label>
                                            <input type="checkbox" class="site-sync-toggle" data-site-id="<?php echo esc_attr(
                                                $site_id
                                            ); ?>"
                                                <?php checked(
                                                    !$is_excluded
                                                ); ?>>
                                            <span class="<?php echo $is_excluded
                                                ? "excluded"
                                                : "included"; ?>">
                                                <?php echo $is_excluded
                                                    ? __(
                                                        "Excluded",
                                                        "multisite-media-asset-sync"
                                                    )
                                                    : __(
                                                        "Included",
                                                        "multisite-media-asset-sync"
                                                    ); ?>
                                            </span>
                                        </label>
                                    <?php else: ?>
                                        <span class="source-badge"><?php _e(
                                            "Source Site",
                                            "multisite-media-asset-sync"
                                        ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$is_main_site): ?>
                                        <button type="button" class="sync-now button" data-site-id="<?php echo esc_attr(
                                            $site_id
                                        ); ?>">
                                            <?php _e(
                                                "Sync Now",
                                                "multisite-media-asset-sync"
                                            ); ?>
                                        </button>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Media Uploader
            $('.select-media').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var assetId = button.data('asset-id');
                var inputId = '#' + assetId + '_id';
                var previewContainer = button.prevAll('.asset-preview');

                var mediaUploader = wp.media({
                    title: '<?php _e(
                        "Select Media",
                        "multisite-media-asset-sync"
                    ); ?>',
                    button: { text: '<?php _e(
                        "Use this media",
                        "multisite-media-asset-sync"
                    ); ?>' },
                    multiple: false
                });

                mediaUploader.on('select', function() {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    var attachmentId = attachment.id;

                    $(inputId).val(attachmentId);
                    previewContainer.empty();

                    var assetSettings = {
                        'site_icon': { size: [48, 48], class: 'site-icon-preview', style: 'object-fit: contain; width: 48px; height: 48px;' },
                        'custom_logo': { size: [150, 150], class: 'custom-logo-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' },
                        'login_logo': { size: [150, 150], class: 'login-logo-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' },
                        'default_featured_image': { size: [150, 150], class: 'default-featured-image-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' },
                        'og_image': { size: [150, 150], class: 'og-image-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' },
                        'background_image': { size: [150, 150], class: 'background-image-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' },
                        'header_image': { size: [150, 150], class: 'header-image-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' },
                        'woocommerce_placeholder_image': { size: [150, 150], class: 'woocommerce-placeholder-preview', style: 'object-fit: contain; max-width: 150px; max-height: 150px;' }
                    };

                    var settings = assetSettings[assetId] || { size: [150, 150], class: '', style: '' };
                    var previewHtml = '<img src="' + attachment.url + '" class="' + settings.class + '" style="' + settings.style + '" />';
                    previewContainer.html(previewHtml);

                    button.text('<?php _e(
                        "Change",
                        "multisite-media-asset-sync"
                    ); ?>');
                    if (!button.next('.remove-media').length) {
                        button.after('<button class="button remove-media" data-asset-id="' + assetId + '"><?php _e(
                            "Remove",
                            "multisite-media-asset-sync"
                        ); ?></button>');
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'save_media_asset',
                            asset_id: assetId,
                            attachment_id: attachmentId,
                            nonce: '<?php echo wp_create_nonce(
                                "save-media-asset"
                            ); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#media-assets-status')
                                    .removeClass('notice-error')
                                    .addClass('notice-success')
                                    .text('<?php _e(
                                        "Media asset saved and synced successfully!",
                                        "multisite-media-asset-sync"
                                    ); ?>')
                                    .show()
                                    .delay(3000)
                                    .fadeOut();
                            } else {
                                $('#media-assets-status')
                                    .removeClass('notice-success')
                                    .addClass('notice-error')
                                    .text(response.data.message || '<?php _e(
                                        "Failed to save media asset.",
                                        "multisite-media-asset-sync"
                                    ); ?>')
                                    .show()
                                    .delay(3000)
                                    .fadeOut();
                            }
                        },
                        error: function() {
                            $('#media-assets-status')
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text('<?php _e(
                                    "An error occurred while saving.",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show()
                                .delay(3000)
                                .fadeOut();
                        }
                    });
                });

                mediaUploader.open();
            });

            // Remove Media
            $(document).on('click', '.remove-media', function(e) {
                e.preventDefault();
                var button = $(this);
                var assetId = button.data('asset-id');
                var inputId = '#' + assetId + '_id';
                var previewContainer = button.prevAll('.asset-preview');
                var selectButton = button.prev('.select-media');

                if (confirm('<?php _e(
                    "Are you sure you want to remove this media asset? This will affect all synced sites.",
                    "multisite-media-asset-sync"
                ); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'remove_media_asset',
                            asset_id: assetId,
                            nonce: '<?php echo wp_create_nonce(
                                "remove-media-asset"
                            ); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $(inputId).val('');
                                previewContainer.html('<div class="empty"><?php _e(
                                    "Not set",
                                    "multisite-media-asset-sync"
                                ); ?></div>');
                                selectButton.text('<?php _e(
                                    "Set Now",
                                    "multisite-media-asset-sync"
                                ); ?>');
                                button.remove();
                                $('#media-assets-status')
                                    .removeClass('notice-error')
                                    .addClass('notice-success')
                                    .text('<?php _e(
                                        "Media asset removed and synced successfully!",
                                        "multisite-media-asset-sync"
                                    ); ?>')
                                    .show()
                                    .delay(3000)
                                    .fadeOut();
                            } else {
                                $('#media-assets-status')
                                    .removeClass('notice-success')
                                    .addClass('notice-error')
                                    .text(response.data.message || '<?php _e(
                                        "Failed to remove media asset.",
                                        "multisite-media-asset-sync"
                                    ); ?>')
                                    .show();
                            }
                        },
                        error: function() {
                            $('#media-assets-status')
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text('<?php _e(
                                    "An error occurred while removing.",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show();
                        }
                    });
                }
            });

            // Sync to all sites
            $('#sync-to-all').on('click', function() {
                const button = $(this);
                const statusSpan = $('#sync-status');

                button.prop('disabled', true);
                statusSpan.text('<?php _e(
                    "Syncing...",
                    "multisite-media-asset-sync"
                ); ?>').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sync_media_to_all',
                        nonce: '<?php echo wp_create_nonce(
                            "sync-all-sites"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text(response.data.message)
                                .show();
                            setTimeout(() => {
                                button.prop('disabled', false);
                                statusSpan.fadeOut();
                            }, 3000);
                        } else {
                            statusSpan
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data.message)
                                .show();
                            button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        statusSpan
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .text('<?php _e(
                                "An error occurred during sync.",
                                "multisite-media-asset-sync"
                            ); ?>')
                            .show();
                        button.prop('disabled', false);
                    }
                });
            });

            // Cleanup Duplicates
            $('#cleanup-duplicates').on('click', function() {
                const button = $(this);
                const statusSpan = $('#cleanup-status');

                if (confirm('<?php _e(
                    "This will remove duplicate attachments across all sites. Continue?",
                    "multisite-media-asset-sync"
                ); ?>')) {
                    button.prop('disabled', true);
                    statusSpan.text('<?php _e(
                        "Cleaning up...",
                        "multisite-media-asset-sync"
                    ); ?>').show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cleanup_duplicate_attachments',
                            nonce: '<?php echo wp_create_nonce(
                                "cleanup-duplicates"
                            ); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                statusSpan
                                    .removeClass('notice-error')
                                    .addClass('notice-success')
                                    .text(response.data.message)
                                    .show();
                                setTimeout(() => {
                                    button.prop('disabled', false);
                                    statusSpan.fadeOut();
                                }, 5000);
                            } else {
                                statusSpan
                                    .removeClass('notice-success')
                                    .addClass('notice-error')
                                    .text(response.data.message || '<?php _e(
                                        "Failed to clean up duplicates.",
                                        "multisite-media-asset-sync"
                                    ); ?>')
                                    .show();
                                button.prop('disabled', false);
                            }
                        },
                        error: function() {
                            statusSpan
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text('<?php _e(
                                    "An error occurred during cleanup.",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show();
                            button.prop('disabled', false);
                        }
                    });
                }
            });

            // Sync to single site
            $('.sync-now').on('click', function() {
                const button = $(this);
                const siteId = button.data('site-id');

                button.prop('disabled', true).text('<?php _e(
                    "Syncing...",
                    "multisite-media-asset-sync"
                ); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sync_media_to_single_site',
                        site_id: siteId,
                        nonce: '<?php echo wp_create_nonce(
                            "sync-to-single-site"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('<?php _e(
                                "Synced!",
                                "multisite-media-asset-sync"
                            ); ?>');
                            setTimeout(() => {
                                button.prop('disabled', false).text('<?php _e(
                                    "Sync Now",
                                    "multisite-media-asset-sync"
                                ); ?>');
                            }, 2000);
                        } else {
                            button.prop('disabled', false).text('<?php _e(
                                "Error",
                                "multisite-media-asset-sync"
                            ); ?>');
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('<?php _e(
                            "Sync Now",
                            "multisite-media-asset-sync"
                        ); ?>');
                        alert('<?php _e(
                            "An error occurred during sync.",
                            "multisite-media-asset-sync"
                        ); ?>');
                    }
                });
            });

            // Save Sync Settings
            $('#save-sync-settings').on('click', function() {
                const button = $(this);
                const statusSpan = $('#sync-settings-status');
                const formData = $('#sync-settings-form').serializeArray();
                const settings = {};

                formData.forEach(item => {
                    if (item.name.includes('[')) {
                        const [mainKey, subKey] = item.name.match(/([^\[]+)\[([^\]]+)\]/).slice(1, 3);
                        if (!settings[mainKey]) settings[mainKey] = {};
                        settings[mainKey][subKey] = item.value ? true : false;
                    } else {
                        settings[item.name] = item.value ? true : false;
                    }
                });

                button.prop('disabled', true);
                statusSpan.text('<?php _e(
                    "Saving...",
                    "multisite-media-asset-sync"
                ); ?>').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_sync_settings',
                        settings: settings,
                        nonce: '<?php echo wp_create_nonce(
                            "save-sync-settings"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text('<?php _e(
                                    "Sync settings saved successfully!",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show()
                                .delay(3000)
                                .fadeOut();
                        } else {
                            statusSpan
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data.message || '<?php _e(
                                    "Failed to save sync settings.",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show();
                        }
                        button.prop('disabled', false);
                    },
                    error: function() {
                        statusSpan
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .text('<?php _e(
                                "An error occurred while saving.",
                                "multisite-media-asset-sync"
                            ); ?>')
                            .show();
                        button.prop('disabled', false);
                    }
                });
            });

            // Site Management - Real-time Save
            $('.site-sync-toggle').on('change', function() {
                const checkbox = $(this);
                const statusSpan = $('#site-management-status');
                const mainSiteId = <?php echo intval(get_main_site_id()); ?>;
                let excludedSites = [mainSiteId]; // 默认排除主站点

                $('.site-sync-toggle').each(function() {
                    const siteId = $(this).data('site-id');
                    if (!$(this).prop('checked') && siteId && Number.isInteger(parseInt(siteId))) {
                        excludedSites.push(parseInt(siteId));
                    }
                });

                excludedSites = [...new Set(excludedSites)];

                statusSpan.text('<?php _e(
                    "Saving...",
                    "multisite-media-asset-sync"
                ); ?>').show();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_site_management',
                        excluded_sites: excludedSites,
                        nonce: '<?php echo wp_create_nonce(
                            "save-site-management"
                        ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusSpan
                                .removeClass('notice-error')
                                .addClass('notice-success')
                                .text('<?php _e(
                                    "Site management saved successfully!",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show()
                                .delay(3000)
                                .fadeOut();

                            $('.site-sync-toggle').each(function() {
                                const $this = $(this);
                                const isChecked = $this.prop('checked');
                                const statusText = $this.next('span');
                                statusText
                                    .text(isChecked ? '<?php _e(
                                        "Included",
                                        "multisite-media-asset-sync"
                                    ); ?>' : '<?php _e(
    "Excluded",
    "multisite-media-asset-sync"
); ?>')
                                    .removeClass('included excluded')
                                    .addClass(isChecked ? 'included' : 'excluded');
                            });
                        } else {
                            statusSpan
                                .removeClass('notice-success')
                                .addClass('notice-error')
                                .text(response.data.message || '<?php _e(
                                    "Failed to save site management.",
                                    "multisite-media-asset-sync"
                                ); ?>')
                                .show();
                            checkbox.prop('checked', !checkbox.prop('checked'));
                        }
                    },
                    error: function(xhr, status, error) {
                        statusSpan
                            .removeClass('notice-success')
                            .addClass('notice-error')
                            .text('<?php _e(
                                "An error occurred while saving: ",
                                "multisite-media-asset-sync"
                            ); ?>' + error)
                            .show();
                        checkbox.prop('checked', !checkbox.prop('checked'));
                    }
                });
            });
        });
        </script>

        <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            max-width: unset;
            margin-top: 20px;
            padding: 20px;
        }
        .sync-status .included { color: #46b450; }
        .sync-status .excluded { color: #dc3232; }
        .source-badge {
            background: #007cba;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .main-site-badge {
            background: #ffe000;
            color: #23282d;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 11px;
            margin-left: 5px;
        }
        .media-assets-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }
        .media-asset {
            flex: 1;
            min-width: 200px;
            max-width: 300px;
            border: 1px solid #e2e4e7;
            border-radius: 4px;
            padding: 15px;
        }
        .site-icon-preview {
            padding: 5px;
        }
        .asset-preview {
            background: #f7f7f7;
            border: 1px solid #ddd;
            padding: 15px;
            text-align: center;
            margin-bottom: 10px;
            min-height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .asset-preview.empty {
            color: #888;
            font-style: italic;
        }
        .asset-preview img {
            max-width: 100%;
            max-height: 150px;
            object-fit: contain;
        }
        .enabled-assets {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .enabled-assets label {
            margin-right: 20px;
            display: flex;
            align-items: center;
        }
        .notice {
            padding: 8px 12px;
            border-radius: 3px;
        }
        .notice-success {
            background-color: #dff0d8;
            border-left: 4px solid #46b450;
        }
        .notice-error {
            background-color: #f2dede;
            border-left: 4px solid #dc3232;
        }
        </style>
        <?php
    }

    private function get_current_assets()
    {
        switch_to_blog(get_main_site_id());
        $stylesheet = get_stylesheet();
        $theme_mods = get_option("theme_mods_$stylesheet", []);
        $background_image_url = $theme_mods["background_image"] ?? "";
        $background_image_id = $background_image_url
            ? attachment_url_to_postid($background_image_url)
            : 0;
        $header_image_url = $theme_mods["header_image"] ?? "";
        $header_image_id = $header_image_url
            ? attachment_url_to_postid($header_image_url)
            : 0;

        $assets = [
            "site_icon" => get_option("site_icon"),
            "custom_logo" => get_theme_mod("custom_logo", 0),
            "login_logo" => get_option("multisite_media_sync_login_logo"),
            "default_featured_image" => get_option(
                "multisite_media_sync_default_featured_image"
            ),
            "og_image" => get_option("multisite_media_sync_og_image"),
            "background_image" => $background_image_id,
            "header_image" => $header_image_id,
            "woocommerce_placeholder_image" => get_option(
                "woocommerce_placeholder_image",
                0
            ),
        ];
        restore_current_blog();
        return $assets;
    }

    private function sync_to_all_sites()
    {
        $main_site_id = get_main_site_id();
        $excluded_sites = $this->settings["excluded_sites"] ?? [];
        $enabled_assets = $this->settings["enabled_assets"] ?? [];
        $current_assets = $this->get_current_assets();

        $sites = get_sites(["archived" => 0, "deleted" => 0]);
        foreach ($sites as $site) {
            $site_id = $site->blog_id;
            if (
                $site_id == $main_site_id ||
                in_array($site_id, $excluded_sites)
            ) {
                continue;
            }
            $this->sync_to_site($site_id, $current_assets, $enabled_assets);
        }
    }

    private function sync_to_site($site_id, $current_assets, $enabled_assets)
    {
        switch_to_blog($site_id);

        if (
            !empty($enabled_assets["site_icon"]) &&
            !empty($current_assets["site_icon"])
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["site_icon"]
            );
            if ($new_attachment_id) {
                update_option("site_icon", $new_attachment_id);
            }
        }

        if (
            !empty($enabled_assets["custom_logo"]) &&
            !empty($current_assets["custom_logo"])
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["custom_logo"]
            );
            if ($new_attachment_id) {
                set_theme_mod("custom_logo", $new_attachment_id);
            }
        }

        if (
            !empty($enabled_assets["login_logo"]) &&
            !empty($current_assets["login_logo"])
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["login_logo"]
            );
            if ($new_attachment_id) {
                update_option(
                    "multisite_media_sync_login_logo",
                    $new_attachment_id
                );
            }
        }

        if (
            !empty($enabled_assets["default_featured_image"]) &&
            !empty($current_assets["default_featured_image"])
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["default_featured_image"]
            );
            if ($new_attachment_id) {
                update_option(
                    "multisite_media_sync_default_featured_image",
                    $new_attachment_id
                );
            }
        }

        if (
            !empty($enabled_assets["og_image"]) &&
            !empty($current_assets["og_image"])
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["og_image"]
            );
            if ($new_attachment_id) {
                update_option(
                    "multisite_media_sync_og_image",
                    $new_attachment_id
                );
            }
        }

        if (
            !empty($enabled_assets["background_image"]) &&
            !empty($current_assets["background_image"])
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["background_image"]
            );
            if ($new_attachment_id) {
                $stylesheet = get_stylesheet();
                $theme_mods = get_option("theme_mods_$stylesheet", []);
                $theme_mods["background_image"] = wp_get_attachment_url(
                    $new_attachment_id
                );
                update_option("theme_mods_$stylesheet", $theme_mods);
            }
        }

        if (
            !empty($enabled_assets["header_image"]) &&
            !empty($current_assets["header_image"]) &&
            !wp_is_block_theme()
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["header_image"]
            );
            if ($new_attachment_id) {
                $stylesheet = get_stylesheet();
                $theme_mods = get_option("theme_mods_$stylesheet", []);
                $theme_mods["header_image"] = wp_get_attachment_url(
                    $new_attachment_id
                );
                update_option("theme_mods_$stylesheet", $theme_mods);
            }
        }

        if (
            !empty($enabled_assets["woocommerce_placeholder_image"]) &&
            !empty($current_assets["woocommerce_placeholder_image"]) &&
            class_exists("WooCommerce")
        ) {
            $new_attachment_id = $this->copy_attachment(
                $current_assets["woocommerce_placeholder_image"]
            );
            if ($new_attachment_id) {
                update_option(
                    "woocommerce_placeholder_image",
                    $new_attachment_id
                );
            }
        }

        restore_current_blog();
    }

    private function copy_attachment($attachment_id)
    {
        switch_to_blog(get_main_site_id());
        $attachment = get_post($attachment_id);
        $original_file = get_attached_file($attachment_id);
        restore_current_blog();

        if (!$attachment || !$original_file || !file_exists($original_file)) {
            error_log(
                "Failed to find attachment or file for ID: $attachment_id"
            );
            return false;
        }

        $existing = get_posts([
            "post_type" => "attachment",
            "posts_per_page" => 1,
            "meta_query" => [
                [
                    "key" => "_multisite_original_id",
                    "value" => $attachment_id,
                    "compare" => "=",
                ],
            ],
        ]);

        if (!empty($existing)) {
            $existing_file = get_attached_file($existing[0]->ID);
            if ($existing_file && file_exists($existing_file)) {
                return $existing[0]->ID;
            } else {
                wp_delete_attachment($existing[0]->ID, true);
            }
        }

        $upload_dir = wp_upload_dir();
        $filename = wp_unique_filename(
            $upload_dir["path"],
            basename($original_file)
        );
        $new_file = $upload_dir["path"] . "/" . $filename;

        if (
            wp_mkdir_p($upload_dir["path"]) &&
            copy($original_file, $new_file)
        ) {
            $attachment_data = [
                "guid" => $upload_dir["url"] . "/" . $filename,
                "post_mime_type" => $attachment->post_mime_type,
                "post_title" => $attachment->post_title,
                "post_content" => $attachment->post_content,
                "post_status" => "inherit",
            ];

            $new_attachment_id = wp_insert_attachment(
                $attachment_data,
                $new_file
            );

            if (!is_wp_error($new_attachment_id)) {
                require_once ABSPATH . "wp-admin/includes/image.php";
                $attach_data = wp_generate_attachment_metadata(
                    $new_attachment_id,
                    $new_file
                );
                wp_update_attachment_metadata($new_attachment_id, $attach_data);
                update_post_meta(
                    $new_attachment_id,
                    "_multisite_original_id",
                    $attachment_id
                );
                return $new_attachment_id;
            } else {
                error_log(
                    "Failed to insert attachment: " .
                        $new_attachment_id->get_error_message()
                );
            }
        } else {
            error_log("Failed to copy file from $original_file to $new_file");
        }

        return false;
    }

    public function ajax_save_media_asset()
    {
        check_ajax_referer("save-media-asset", "nonce");

        if (!current_user_can("manage_network_options")) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        $asset_id = sanitize_key($_POST["asset_id"]);
        $attachment_id = intval($_POST["attachment_id"]);

        if (!isset($this->asset_types[$asset_id]) || !$attachment_id) {
            wp_send_json_error([
                "message" => __(
                    "Invalid asset or attachment.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        switch_to_blog(get_main_site_id());

        switch ($asset_id) {
            case "site_icon":
                update_option("site_icon", $attachment_id);
                break;
            case "custom_logo":
                set_theme_mod("custom_logo", $attachment_id);
                break;
            case "login_logo":
                update_option(
                    "multisite_media_sync_login_logo",
                    $attachment_id
                );
                break;
            case "default_featured_image":
                update_option(
                    "multisite_media_sync_default_featured_image",
                    $attachment_id
                );
                break;
            case "og_image":
                update_option("multisite_media_sync_og_image", $attachment_id);
                break;
            case "background_image":
                $stylesheet = get_stylesheet();
                $theme_mods = get_option("theme_mods_$stylesheet", []);
                $theme_mods["background_image"] = wp_get_attachment_url(
                    $attachment_id
                );
                update_option("theme_mods_$stylesheet", $theme_mods);
                break;
            case "header_image":
                $stylesheet = get_stylesheet();
                $theme_mods = get_option("theme_mods_$stylesheet", []);
                $theme_mods["header_image"] = wp_get_attachment_url(
                    $attachment_id
                );
                update_option("theme_mods_$stylesheet", $theme_mods);
                break;
            case "woocommerce_placeholder_image":
                update_option("woocommerce_placeholder_image", $attachment_id);
                break;
        }

        restore_current_blog();

        $this->sync_to_all_sites();

        wp_send_json_success([
            "message" => __(
                "Media asset saved and synced.",
                "multisite-media-asset-sync"
            ),
        ]);
    }

    public function ajax_remove_media_asset()
    {
        check_ajax_referer("remove-media-asset", "nonce");

        if (!current_user_can("manage_network_options")) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        $asset_id = sanitize_key($_POST["asset_id"]);

        if (!isset($this->asset_types[$asset_id])) {
            wp_send_json_error([
                "message" => __("Invalid asset.", "multisite-media-asset-sync"),
            ]);
        }

        switch_to_blog(get_main_site_id());

        switch ($asset_id) {
            case "site_icon":
                delete_option("site_icon");
                break;
            case "custom_logo":
                remove_theme_mod("custom_logo");
                break;
            case "login_logo":
                delete_option("multisite_media_sync_login_logo");
                break;
            case "default_featured_image":
                delete_option("multisite_media_sync_default_featured_image");
                break;
            case "og_image":
                delete_option("multisite_media_sync_og_image");
                break;
            case "background_image":
                $stylesheet = get_stylesheet();
                $theme_mods = get_option("theme_mods_$stylesheet", []);
                unset($theme_mods["background_image"]);
                update_option("theme_mods_$stylesheet", $theme_mods);
                break;
            case "header_image":
                $stylesheet = get_stylesheet();
                $theme_mods = get_option("theme_mods_$stylesheet", []);
                unset($theme_mods["header_image"]);
                update_option("theme_mods_$stylesheet", $theme_mods);
                break;
            case "woocommerce_placeholder_image":
                delete_option("woocommerce_placeholder_image");
                break;
        }

        restore_current_blog();

        $main_site_id = get_main_site_id();
        $excluded_sites = $this->settings["excluded_sites"] ?? [];
        $enabled_assets = $this->settings["enabled_assets"] ?? [];
        $sites = get_sites(["archived" => 0, "deleted" => 0]);

        foreach ($sites as $site) {
            $site_id = $site->blog_id;
            if (
                $site_id == $main_site_id ||
                in_array($site_id, $excluded_sites) ||
                empty($enabled_assets[$asset_id])
            ) {
                continue;
            }

            switch_to_blog($site_id);
            switch ($asset_id) {
                case "site_icon":
                    delete_option("site_icon");
                    break;
                case "custom_logo":
                    remove_theme_mod("custom_logo");
                    break;
                case "login_logo":
                    delete_option("multisite_media_sync_login_logo");
                    break;
                case "default_featured_image":
                    delete_option(
                        "multisite_media_sync_default_featured_image"
                    );
                    break;
                case "og_image":
                    delete_option("multisite_media_sync_og_image");
                    break;
                case "background_image":
                    $stylesheet = get_stylesheet();
                    $theme_mods = get_option("theme_mods_$stylesheet", []);
                    unset($theme_mods["background_image"]);
                    update_option("theme_mods_$stylesheet", $theme_mods);
                    break;
                case "header_image":
                    if (!wp_is_block_theme()) {
                        $stylesheet = get_stylesheet();
                        $theme_mods = get_option("theme_mods_$stylesheet", []);
                        unset($theme_mods["header_image"]);
                        update_option("theme_mods_$stylesheet", $theme_mods);
                    }
                    break;
                case "woocommerce_placeholder_image":
                    if (class_exists("WooCommerce")) {
                        delete_option("woocommerce_placeholder_image");
                    }
                    break;
            }
            restore_current_blog();
        }

        wp_send_json_success([
            "message" => __(
                "Media asset removed and synced.",
                "multisite-media-asset-sync"
            ),
        ]);
    }

    public function ajax_cleanup_duplicate_attachments()
    {
        check_ajax_referer("cleanup-duplicates", "nonce");

        if (!current_user_can("manage_network_options")) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        $deleted_count = 0;
        $sites = get_sites(["archived" => 0, "deleted" => 0]);

        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);

            $attachments = get_posts([
                "post_type" => "attachment",
                "posts_per_page" => -1,
                "meta_key" => "_multisite_original_id",
            ]);

            $seen_original_ids = [];
            foreach ($attachments as $attachment) {
                $original_id = get_post_meta(
                    $attachment->ID,
                    "_multisite_original_id",
                    true
                );
                if (empty($original_id)) {
                    continue;
                }

                if (isset($seen_original_ids[$original_id])) {
                    $existing_file = get_attached_file($attachment->ID);
                    if ($existing_file && file_exists($existing_file)) {
                        wp_delete_attachment($attachment->ID, true);
                        $deleted_count++;
                        error_log(
                            "Deleted duplicate attachment ID: $attachment->ID for original ID: $original_id on site $site->blog_id"
                        );
                    }
                } else {
                    $seen_original_ids[$original_id] = $attachment->ID;
                }
            }

            restore_current_blog();
        }

        if ($deleted_count > 0) {
            wp_send_json_success([
                "message" => sprintf(
                    __(
                        "Cleaned up %d duplicate attachments.",
                        "multisite-media-asset-sync"
                    ),
                    $deleted_count
                ),
            ]);
        } else {
            wp_send_json_success([
                "message" => __(
                    "No duplicate attachments found.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }
    }

    public function handle_new_site($new_site)
    {
        if (empty($this->settings["auto_sync_new_sites"])) {
            return;
        }

        $current_assets = $this->get_current_assets();
        $enabled_assets = $this->settings["enabled_assets"] ?? [];
        $this->sync_to_site(
            $new_site->blog_id,
            $current_assets,
            $enabled_assets
        );
    }

    public function handle_customizer_save($customizer)
    {
        if (get_current_blog_id() != get_main_site_id()) {
            return;
        }
        $this->sync_to_all_sites();
    }

    public function customize_login_logo()
    {
        $logo_id = get_option("multisite_media_sync_login_logo");
        if (
            $logo_id &&
            ($logo_url = wp_get_attachment_image_url($logo_id, "full"))
        ) { ?>
            <style type="text/css">
                #login h1 a {
                    background-image: url(<?php echo esc_url($logo_url); ?>);
                    background-size: contain;
                    width: 100%;
                    height: 100px;
                }
            </style>
            <?php }
    }

    public function set_default_featured_image(
        $value,
        $object_id,
        $meta_key,
        $single
    ) {
        if ($meta_key !== "_thumbnail_id" || $value) {
            return $value;
        }

        $default_image_id = get_option(
            "multisite_media_sync_default_featured_image"
        );
        return $default_image_id ?: $value;
    }

    public function sync_yoast_og_image($image)
    {
        $og_image_id = get_option("multisite_media_sync_og_image");
        if ($og_image_id) {
            return wp_get_attachment_image_url($og_image_id, "full");
        }
        return $image;
    }

    public function sync_rankmath_og_image($image)
    {
        $og_image_id = get_option("multisite_media_sync_og_image");
        if ($og_image_id) {
            return wp_get_attachment_image_url($og_image_id, "full");
        }
        return $image;
    }

    public function ajax_sync_to_single_site()
    {
        check_ajax_referer("sync-to-single-site", "nonce");
        if (
            !current_user_can("manage_network_options") ||
            !($site_id = intval($_POST["site_id"]))
        ) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied or invalid site ID.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        $current_assets = $this->get_current_assets();
        $enabled_assets = $this->settings["enabled_assets"] ?? [];
        $this->sync_to_site($site_id, $current_assets, $enabled_assets);
        wp_send_json_success([
            "message" => __(
                "Sync completed successfully.",
                "multisite-media-asset-sync"
            ),
        ]);
    }

    public function ajax_sync_to_all()
    {
        check_ajax_referer("sync-all-sites", "nonce");
        if (!current_user_can("manage_network_options")) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        $this->sync_to_all_sites();
        wp_send_json_success([
            "message" => __(
                "Sync completed successfully.",
                "multisite-media-asset-sync"
            ),
        ]);
    }

    public function ajax_save_sync_settings()
    {
        check_ajax_referer("save-sync-settings", "nonce");
        if (
            !current_user_can("manage_network_options") ||
            !is_array($settings = $_POST["settings"])
        ) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied or invalid settings.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        update_site_option("multisite_media_sync_settings", $settings);
        $this->settings = $settings;
        wp_send_json_success([
            "message" => __(
                "Settings saved successfully.",
                "multisite-media-asset-sync"
            ),
        ]);
    }

    public function ajax_save_site_management()
    {
        check_ajax_referer("save-site-management", "nonce");
        if (!current_user_can("manage_network_options")) {
            wp_send_json_error([
                "message" => __(
                    "Permission denied.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        $main_site_id = get_main_site_id();
        $excluded_sites =
            isset($_POST["excluded_sites"]) &&
            is_array($_POST["excluded_sites"])
                ? array_map("intval", $_POST["excluded_sites"])
                : [];

        if (!in_array($main_site_id, $excluded_sites)) {
            $excluded_sites[] = $main_site_id;
        }

        $excluded_sites = array_filter($excluded_sites, function ($id) {
            return $id > 0 && get_blog_details($id) !== false;
        });
        $excluded_sites = array_unique($excluded_sites);

        $this->settings["excluded_sites"] = $excluded_sites;
        $update_result = update_site_option(
            "multisite_media_sync_settings",
            $this->settings
        );

        if ($update_result === false) {
            wp_send_json_error([
                "message" => __(
                    "Failed to update settings. Check database permissions.",
                    "multisite-media-asset-sync"
                ),
            ]);
        }

        wp_send_json_success([
            "message" => __(
                "Site management saved successfully.",
                "multisite-media-asset-sync"
            ),
        ]);
    }

    public function multisite_required_notice()
    {
        ?>
        <div class="notice notice-error">
            <p><?php _e(
                "Multisite Media Sync requires WordPress Multisite to be enabled.",
                "multisite-media-asset-sync"
            ); ?></p>
        </div>
        <?php
    }

    public function add_settings_link($links)
    {
        if (is_multisite()) {
            $settings_link =
                '<a href="' .
                network_admin_url(
                    "settings.php?page=multisite-media-asset-sync"
                ) .
                '">' .
                __("Settings", "multisite-media-asset-sync") .
                "</a>";
            array_unshift($links, $settings_link);
        }
        return $links;
    }

    public function sync_media_asset($old_value, $new_value, $option)
    {
        if (get_current_blog_id() != get_main_site_id()) {
            return;
        }
        $this->sync_to_all_sites();
    }
}

function multisite_media_sync_init()
{
    Multisite_Media_Sync::get_instance();
}
add_action("plugins_loaded", "multisite_media_sync_init");
