<?php
/*
 * Plugin Name:		  Cyphex Image Hunter
 * Plugin URI:		  https://wordpress.org/plugins/cyphex-image-hunter/
 * Description:		  Automatically finds and inserts AI-generated images into your posts.
 * Version:			  1.5.4
 * Requires at least: 5.8
 * Requires PHP:	  7.4
 * Author:			  Ali hamza
 * Author URI:		  https://profiles.wordpress.org/hamaza7867/
 * License:			  GPLv2 or later
 * License URI:		  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:		  cyphex-image-hunter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Cyphex_Image_Hunter_Plugin' ) ) {

	class Cyphex_Image_Hunter_Plugin {

		private $groq_api_url = 'https://api.groq.com/openai/v1/chat/completions';
		private $pexels_api_url = 'https://api.pexels.com/v1/search';
		private $pixabay_api_url = 'https://pixabay.com/api/';
		public $pro;

		public function __construct() {
			$this->includes();
			// Settings
			add_action( 'admin_menu', array( $this, 'add_settings_page' ));
			add_action( 'admin_init', array( $this, 'register_settings' ));

			// Media Manager Integration
			add_action( 'wp_enqueue_media', array( $this, 'cyphex_enqueue_media_assets' ) );
			add_action( 'print_media_templates', array( $this, 'cyphex_print_assets_and_templates' ) );

			// AJAX Handlers
			add_action( 'wp_ajax_cyphex_image_hunter_search', array( $this, 'cyphex_handle_ajax_search' ));
			add_action( 'wp_ajax_cyphex_image_hunter_refine_prompt', array( $this, 'cyphex_handle_ajax_refine_prompt' ));
			add_action( 'wp_ajax_cyphex_image_hunter_sideload', array( $this, 'cyphex_handle_ajax_sideload' ));

			// Plugin Action Links
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ));

			// Pro Actions
			if ( $this->pro ) {
				add_action( 'wp_ajax_cyphex_activate_license', array( $this->pro, 'ajax_activate_license' ) );
			}
		}

		private function includes() {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-cyphex-pro-manager.php';
			$this->pro = new Cyphex_Pro_Manager();
		}

		public function add_plugin_action_links($links ) {
			$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=cyphex-image-hunter' ) ) . '" style="font-weight:bold;">' . esc_html__( 'Settings', 'cyphex-image-hunter' ) . '</a>';
			array_unshift($links, $settings_link );
			return $links;
		}

		// --- 1. Settings ---

		public function add_settings_page() {
			add_options_page(
				esc_html__( 'Cyphex Image Hunter', 'cyphex-image-hunter' ),
				esc_html__( 'Cyphex Image Hunter', 'cyphex-image-hunter' ),
				'manage_options',
				'cyphex-image-hunter',
				array( $this, 'render_settings_page' )
			);
		}

		public function register_settings() {
			register_setting( 'cyphex_image_hunter_options', 'cyphex_image_hunter_groq_key', array('sanitize_callback' => 'sanitize_text_field'));
			register_setting( 'cyphex_image_hunter_options', 'cyphex_image_hunter_pexels_key', array('sanitize_callback' => 'sanitize_text_field'));
			register_setting( 'cyphex_image_hunter_options', 'cyphex_image_hunter_pixabay_key', array('sanitize_callback' => 'sanitize_text_field'));
			register_setting( 'cyphex_image_hunter_options', 'cyphex_image_hunter_auto_credit', array('sanitize_callback' => 'absint'));
		}

		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Cyphex Image Hunter', 'cyphex-image-hunter'); ?></h1>
				
			<?php
				if ( isset( $_GET['tab'] ) ) {
					check_admin_referer( 'cyphex_image_hunter_tabs' );
				}
				$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
				$is_pro = cyphex_is_pro();
			?>
			<div class="wrap" style="margin: 20px 20px 0 0; max-width: none;">
				<div style="display: flex; align-items: center; margin-bottom: 25px; gap: 15px;">
					<div style="background: linear-gradient(135deg, #2271b1 0%, #3b82f6 100%); width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(34, 113, 177, 0.2);">
						<span class="dashicons dashicons-images-alt2" style="color: #fff; font-size: 24px; width: 24px; height: 24px;"></span>
					</div>
					<div>
						<h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #1e293b;"><?php echo esc_html( get_admin_page_title() ); ?></h1>
						<p style="margin: 0; color: #64748b; font-size: 14px;"><?php esc_html_e( 'The most advanced AI image toolkit for WordPress.', 'cyphex-image-hunter' ); ?></p>
					</div>
				</div>

				<h2 class="nav-tab-wrapper" style="border-bottom: none; padding: 0; display: flex; gap: 10px; margin-bottom: 30px;">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=cyphex-image-hunter&tab=general' ), 'cyphex_image_hunter_tabs' ) ); ?>" style="border-radius: 8px; border: 1px solid #e2e8f0; margin: 0; padding: 10px 20px; font-weight: 600; background: <?php echo $active_tab == 'general' ? '#fff' : 'transparent'; ?>; color: <?php echo $active_tab == 'general' ? '#2271b1' : '#64748b'; ?>; box-shadow: <?php echo $active_tab == 'general' ? '0 2px 4px rgba(0,0,0,0.05)' : 'none'; ?>;" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General Settings', 'cyphex-image-hunter' ); ?></a>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=cyphex-image-hunter&tab=pro' ), 'cyphex_image_hunter_tabs' ) ); ?>" style="border-radius: 8px; border: 1px solid #e2e8f0; margin: 0; padding: 10px 20px; font-weight: 600; background: <?php echo $active_tab == 'pro' ? '#fff' : 'transparent'; ?>; color: <?php echo $active_tab == 'pro' ? '#2271b1' : '#64748b'; ?>; box-shadow: <?php echo $active_tab == 'pro' ? '0 2px 4px rgba(0,0,0,0.05)' : 'none'; ?>;" class="nav-tab <?php echo $active_tab == 'pro' ? 'nav-tab-active' : ''; ?>">
						<?php esc_html_e( 'Pro Version', 'cyphex-image-hunter' ); ?>
						<?php if ( $is_pro ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 16px; margin-top: 5px;"></span>
						<?php endif; ?>
					</a>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=cyphex-image-hunter&tab=docs' ), 'cyphex_image_hunter_tabs' ) ); ?>" style="border-radius: 8px; border: 1px solid #e2e8f0; margin: 0; padding: 10px 20px; font-weight: 600; background: <?php echo $active_tab == 'docs' ? '#fff' : 'transparent'; ?>; color: <?php echo $active_tab == 'docs' ? '#2271b1' : '#64748b'; ?>; box-shadow: <?php echo $active_tab == 'docs' ? '0 2px 4px rgba(0,0,0,0.05)' : 'none'; ?>;" class="nav-tab <?php echo $active_tab == 'docs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'User Guide', 'cyphex-image-hunter' ); ?></a>
				</h2>

				<?php if ( $active_tab == 'general' ) : ?>
					<div style="display: grid; grid-template-columns: 1fr 350px; gap: 30px; align-items: start;">
						<div class="card" style="max-width: none; margin: 0; padding: 0; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); overflow: hidden; background: #fff;">
							<h2 style="padding: 20px 25px; background: #f8fafc; margin: 0; border-bottom: 1px solid #e2e8f0; font-size: 18px; color: #1e293b; display: flex; align-items: center; gap: 10px;">
								<span class="dashicons dashicons-admin-generic" style="color: #2271b1;"></span>
								<?php esc_html_e( 'Configuration', 'cyphex-image-hunter'); ?>
							</h2>
							<div style="padding: 30px 25px;">
								<form method="post" action="options.php">
									<?php settings_fields('cyphex_image_hunter_options'); ?>
									<?php do_settings_sections('cyphex_image_hunter_options'); ?>
									<table class="form-table">
										<tr valign="top">
											<th scope="row" style="width: 200px; font-weight: 600;"><?php esc_html_e( 'Groq API Key', 'cyphex-image-hunter'); ?> <span style="color:red">*</span></th>
											<td>
												<input type="password" name="cyphex_image_hunter_groq_key" value="<?php echo esc_attr(get_option( 'cyphex_image_hunter_groq_key')); ?>" class="regular-text" style="width: 100%;" placeholder="gsk_..." />
												<p class="description"><?php esc_html_e( 'Required for optimizing prompts and creating SEO titles.', 'cyphex-image-hunter'); ?></p>
											</td>
										</tr>
										<tr valign="top">
											<th scope="row" style="width: 200px; font-weight: 600;"><?php esc_html_e( 'Pexels API Key', 'cyphex-image-hunter'); ?></th>
											<td>
												<input type="password" name="cyphex_image_hunter_pexels_key" value="<?php echo esc_attr(get_option( 'cyphex_image_hunter_pexels_key')); ?>" class="regular-text" style="width: 100%;" />
											</td>
										</tr>
										<tr valign="top">
											<th scope="row" style="width: 200px; font-weight: 600;"><?php esc_html_e( 'Pixabay API Key', 'cyphex-image-hunter'); ?></th>
											<td>
												<input type="password" name="cyphex_image_hunter_pixabay_key" value="<?php echo esc_attr(get_option( 'cyphex_image_hunter_pixabay_key')); ?>" class="regular-text" style="width: 100%;" />
											</td>
										</tr>
										<tr valign="top">
											<th scope="row" style="width: 200px; font-weight: 600;"><?php esc_html_e( 'Auto-Credit Photographer', 'cyphex-image-hunter'); ?></th>
											<td>
												<label>
													<input type="checkbox" name="cyphex_image_hunter_auto_credit" value="1" <?php checked(1, get_option( 'cyphex_image_hunter_auto_credit'), true ); ?> />
													<?php esc_html_e( 'Automatically append "Photo by [Photographer] on [Platform]" to the image caption when inserted into WordPress.', 'cyphex-image-hunter'); ?>
												</label>
											</td>
										</tr>
									</table>
									<?php submit_button( __( 'Save API Keys', 'cyphex-image-hunter' ) ); ?>
								</form>
							</div>
						</div>

						<div style="display: flex; flex-direction: column; gap: 20px;">
							<!-- Quick Setup Card -->
							<div class="card" style="max-width: none; margin: 0; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; background: #fff; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
								<h3 style="margin-top: 0; font-size: 16px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-sos" style="color: #ef4444;"></span>
									<?php esc_html_e( 'Need Help?', 'cyphex-image-hunter'); ?>
								</h3>
								<p style="font-size: 13px; color: #64748b; margin-bottom: 15px;"><?php esc_html_e( 'If you\'re stuck, check our visual guides or use the right-click trick for blocked links.', 'cyphex-image-hunter'); ?></p>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=cyphex-image-hunter&tab=docs' ), 'cyphex_image_hunter_tabs' ) ); ?>" class="button button-secondary" style="width: 100%; text-align: center; height: 36px; line-height: 34px; border-radius: 6px; font-weight: 600;"><?php esc_html_e( 'View Documentation', 'cyphex-image-hunter'); ?></a>
							</div>

							<!-- Security Badge -->
							<div class="card" style="max-width: none; margin: 0; padding: 25px; border-radius: 12px; border: 1px solid #ecfdf5; background: #f0fdf4; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
								<h3 style="margin-top: 0; font-size: 16px; color: #065f46; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-shield-alt" style="color: #10b981;"></span>
									<?php esc_html_e( 'Secure Storage', 'cyphex-image-hunter'); ?>
								</h3>
								<p style="font-size: 13px; color: #065f46; margin: 0; opacity: 0.8;"><?php esc_html_e( 'Your keys are encrypted and stored locally. We never track your searches or store your images on our servers.', 'cyphex-image-hunter'); ?></p>
							</div>
						</div>
					</div>
				<?php endif; ?>
				<?php if ( $active_tab == 'pro' ) : ?>
					<div style="max-width: none; margin: 0;">
						<!-- Hero Section -->
						<div class="card" style="max-width: none; margin: 0 0 30px 0; border: none; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); overflow: hidden; border-radius: 16px; background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #fff; text-align: center; padding: 50px 20px;">
							<div style="background: rgba(255,255,255,0.2); width: 80px; height: 80px; border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
								<span class="dashicons dashicons-images-alt2" style="color: #fff; font-size: 40px; width: 40px; height: 40px;"></span>
							</div>
							<h2 style="color: #fff; font-size: 32px; margin: 0 0 10px 0;"><?php esc_html_e( 'Elevate Your Content Strategy', 'cyphex-image-hunter' ); ?></h2>
							<p style="font-size: 18px; opacity: 0.9; max-width: 700px; margin: 0 auto;"><?php esc_html_e( 'Compare our upcoming plans and discover the power of advanced AI image generation directly in your WordPress dashboard.', 'cyphex-image-hunter' ); ?></p>
						</div>

						<!-- Pricing / Feature Matrix -->
						<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px;">
							
							<!-- FREE PLAN -->
							<div class="card" style="max-width: none; margin: 0; padding: 40px; border-radius: 16px; border: 1px solid #e2e8f0; background: #fff; display: flex; flex-direction: column;">
								<div style="margin-bottom: 25px; text-align: center;">
									<span style="color: #64748b; font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;"><?php esc_html_e( 'Current', 'cyphex-image-hunter' ); ?></span>
									<h3 style="font-size: 28px; margin: 10px 0; color: #1e293b;"><?php esc_html_e( 'Free', 'cyphex-image-hunter' ); ?></h3>
									<div style="font-size: 36px; font-weight: 800; color: #1e293b;">$0<span style="font-size: 16px; color: #64748b; font-weight: 400;">/mo</span></div>
								</div>
								<ul style="list-style: none; padding: 0; margin: 0 0 30px 0; flex-grow: 1;">
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #10b981;"></span> <?php esc_html_e( 'Unlimited Pexels & Pixabay', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #10b981;"></span> <?php esc_html_e( 'Standard AI (Llama 3.3)', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #10b981;"></span> <?php esc_html_e( 'Single Image Generation', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #10b981;"></span> <?php esc_html_e( 'Basic AI Auto-Alt & Description', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; display: flex; items-center; gap: 10px; font-size: 14px; color: #94a3b8;"><span class="dashicons dashicons-no-alt" style="color: #cbd5e1;"></span> <?php esc_html_e( 'No Watermarking', 'cyphex-image-hunter' ); ?></li>
								</ul>
								<button class="button button-disabled" disabled style="width: 100%; height: 44px; font-weight: 700; border-radius: 8px;"><?php esc_html_e( 'Active Plan', 'cyphex-image-hunter' ); ?></button>
							</div>

							<!-- PRO PLAN -->
							<div class="card" style="max-width: none; margin: 0; padding: 40px; border-radius: 16px; border: 2px solid #3b82f6; background: #fff; display: flex; flex-direction: column; position: relative; transform: scale(1.05); z-index: 10; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
								<div style="position: absolute; top: -15px; left: 50%; transform: translateX(-50%); background: #3b82f6; color: #fff; padding: 4px 15px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;"><?php esc_html_e( 'Most Popular', 'cyphex-image-hunter' ); ?></div>
								<div style="margin-bottom: 25px; text-align: center;">
									<span style="color: #3b82f6; font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;"><?php esc_html_e( 'Coming Soon', 'cyphex-image-hunter' ); ?></span>
									<h3 style="font-size: 28px; margin: 10px 0; color: #1e293b;"><?php esc_html_e( 'Pro', 'cyphex-image-hunter' ); ?></h3>
									<div style="font-size: 36px; font-weight: 800; color: #1e293b;">$19<span style="font-size: 16px; color: #64748b; font-weight: 400;">/mo</span></div>
								</div>
								<ul style="list-style: none; padding: 0; margin: 0 0 30px 0; flex-grow: 1;">
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #1e293b; font-weight: 500;"><span class="dashicons dashicons-star-filled" style="color: #f59e0b;"></span> <?php esc_html_e( 'Everything in Free', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Nano Banana AI Integration', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Pexels & Pixabay Video Picker', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Flux 1.1 & DALL-E 3 Support', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Advanced SEO-Optimized Descriptions', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Brand Watermarking', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Batch Generation (Limited 5x)', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #3b82f6;"></span> <?php esc_html_e( 'Priority Support', 'cyphex-image-hunter' ); ?></li>
								</ul>
								<button class="button button-primary" style="width: 100%; height: 44px; font-weight: 700; border-radius: 8px; background: #3b82f6; border-color: #3b82f6;"><?php esc_html_e( 'Join Waitlist', 'cyphex-image-hunter' ); ?></button>
							</div>

							<!-- ULTRA PRO PLAN -->
							<div class="card" style="max-width: none; margin: 0; padding: 40px; border-radius: 16px; border: 1px solid #e2e8f0; background: #fff; display: flex; flex-direction: column;">
								<div style="margin-bottom: 25px; text-align: center;">
									<span style="color: #1e293b; font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;"><?php esc_html_e( 'Enterprise', 'cyphex-image-hunter' ); ?></span>
									<h3 style="font-size: 28px; margin: 10px 0; color: #1e293b;"><?php esc_html_e( 'Ultra Pro', 'cyphex-image-hunter' ); ?></h3>
									<div style="font-size: 36px; font-weight: 800; color: #1e293b;">$49<span style="font-size: 16px; color: #64748b; font-weight: 400;">/mo</span></div>
								</div>
								<ul style="list-style: none; padding: 0; margin: 0 0 30px 0; flex-grow: 1;">
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #1e293b; font-weight: 500;"><span class="dashicons dashicons-awards" style="color: #8b5cf6;"></span> <?php esc_html_e( 'Everything in Pro', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #8b5cf6;"></span> <?php esc_html_e( 'Nano Banana Pro (Max Power)', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #8b5cf6;"></span> <?php esc_html_e( 'Sora AI Video Generation', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #8b5cf6;"></span> <?php esc_html_e( 'Major AI Models (Publicity Rank)', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #8b5cf6;"></span> <?php esc_html_e( 'Unlimited Batch Generation', 'cyphex-image-hunter' ); ?></li>
									<li style="padding: 10px 0; display: flex; items-center; gap: 10px; font-size: 14px; color: #475569;"><span class="dashicons dashicons-yes" style="color: #8b5cf6;"></span> <?php esc_html_e( 'Custom AI LORA Training', 'cyphex-image-hunter' ); ?></li>
								</ul>
								<button class="button button-secondary" style="width: 100%; height: 44px; font-weight: 700; border-radius: 8px; border-color: #1e293b; color: #1e293b;"><?php esc_html_e( 'Coming Soon', 'cyphex-image-hunter' ); ?></button>
							</div>

						</div>

						<!-- Detailed Feature Grid -->
						<div class="card" style="max-width: none; margin: 40px 0 0 0; padding: 40px; border-radius: 16px; background: #fff; border: 1px solid #e2e8f0;">
							<h3 style="margin-top: 0; font-size: 20px; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 25px;"><?php esc_html_e( 'Deep Dive: Planned Features', 'cyphex-image-hunter' ); ?></h3>
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 30px;">
								<div>
									<h4 style="margin: 0 0 10px 0; color: #1e293b;">🤖 <?php esc_html_e( 'Next-Gen AI Models', 'cyphex-image-hunter' ); ?></h4>
									<p style="font-size: 13px; color: #64748b; line-height: 1.6;"><?php esc_html_e( 'Integration with Flux 1.1 and DALL-E 3 will allow you to generate photo-realistic images with perfect text rendering and anatomical accuracy.', 'cyphex-image-hunter' ); ?></p>
								</div>
								<div>
									<h4 style="margin: 0 0 10px 0; color: #1e293b;">🖌️ <?php esc_html_e( 'Smart Brand Assets', 'cyphex-image-hunter' ); ?></h4>
									<p style="font-size: 13px; color: #64748b; line-height: 1.6;"><?php esc_html_e( 'Upload your logo once and have it intelligently placed on every AI image you generate, ensuring consistent branding across your site.', 'cyphex-image-hunter' ); ?></p>
								</div>
								<div>
									<h4 style="margin: 0 0 10px 0; color: #1e293b;">📊 <?php esc_html_e( 'Bulk Asset Packing', 'cyphex-image-hunter' ); ?></h4>
									<p style="font-size: 13px; color: #64748b; line-height: 1.6;"><?php esc_html_e( 'Generate entire galleries of images for a single post in seconds. Pick the best ones or use them all for a rich visual experience.', 'cyphex-image-hunter' ); ?></p>
								</div>
								<div>
									<h4 style="margin: 0 0 10px 0; color: #1e293b;">🧠 <?php esc_html_e( 'Custom LORA Training', 'cyphex-image-hunter' ); ?></h4>
									<p style="font-size: 13px; color: #64748b; line-height: 1.6;"><?php esc_html_e( 'Train the AI on your specific products or style to get results that are perfectly tailored to your brand identity.', 'cyphex-image-hunter' ); ?></p>
								</div>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $active_tab == 'docs' ) : ?>
					<div class="card" style="max-width: none; margin: 0; padding: 40px; border-radius: 16px; border: 1px solid #e2e8f0; background: #fff; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);">
						<h2 style="margin-top: 0; font-size: 24px; border-bottom: 1px solid #eee; padding-bottom: 15px;">📖 <?php esc_html_e( 'Cyphex Image Hunter: Complete Guide', 'cyphex-image-hunter' ); ?></h2>
						
						<div style="display: grid; grid-template-columns: 250px 1fr; gap: 40px; margin-top: 20px;">
							<div style="border-right: 1px solid #eee; padding-right: 20px;">
								<ul style="list-style: none; padding: 0; margin: 0; position: sticky; top: 50px;">
									<li style="margin-bottom: 15px;"><a href="#what-is-groq" style="text-decoration: none; color: #2271b1; font-weight: 600;">⚡ <?php esc_html_e( 'What is Groq?', 'cyphex-image-hunter' ); ?></a></li>
									<li style="margin-bottom: 15px;"><a href="#setup" style="text-decoration: none; color: #2271b1; font-weight: 600;">🛠 <?php esc_html_e( 'Initial Setup', 'cyphex-image-hunter' ); ?></a></li>
									<li style="margin-bottom: 15px;"><a href="#using" style="text-decoration: none; color: #2271b1; font-weight: 600;">🎯 <?php esc_html_e( 'How to Use', 'cyphex-image-hunter' ); ?></a></li>
									<li style="margin-bottom: 15px;"><a href="#trouble" style="text-decoration: none; color: #2271b1; font-weight: 600;">🔧 <?php esc_html_e( 'Troubleshooting', 'cyphex-image-hunter' ); ?></a></li>
								</ul>
							</div>
							
							<div>
								<section id="what-is-groq" style="margin-bottom: 40px;">
									<h3 style="margin-top: 0;"><?php esc_html_e( '⚡ What is Groq and why do I need it?', 'cyphex-image-hunter' ); ?></h3>
									<p><?php esc_html_e( 'Groq is a high-speed AI engine (LPU) that powers the "brain" of this plugin. While stock providers give you the images, Groq does the intelligent work:', 'cyphex-image-hunter' ); ?></p>
									<ul style="list-style: disc; padding-left: 20px; color: #444;">
										<li><strong><?php esc_html_e( 'Prompt Engineering:', 'cyphex-image-hunter' ); ?></strong> <?php esc_html_e( 'It takes your simple search (e.g. "car") and expands it into a professional AI prompt.', 'cyphex-image-hunter' ); ?></li>
										<li><strong><?php esc_html_e( 'SEO Metadata:', 'cyphex-image-hunter' ); ?></strong> <?php esc_html_e( 'It automatically generates SEO-optimized Alt text and titles for your images.', 'cyphex-image-hunter' ); ?></li>
										<li><strong><?php esc_html_e( 'Speed:', 'cyphex-image-hunter' ); ?></strong> <?php esc_html_e( 'Groq is significantly faster than standard AI, giving you results in milliseconds.', 'cyphex-image-hunter' ); ?></li>
									</ul>
									<div style="margin-top: 30px;">
										<p style="font-weight: 600; margin-bottom: 25px; font-size: 18px; color: #1e293b;"><?php esc_html_e( 'Official Visual Setup Guide', 'cyphex-image-hunter' ); ?></p>
										
										<!-- Step 1 -->
										<div style="margin-bottom: 50px; border-left: 4px solid #3b82f6; padding-left: 25px;">
											<div style="margin-bottom: 15px;">
												<span style="background: #3b82f6; color: #fff; width: 24px; height: 24px; display: inline-flex; items-center; justify-content: center; border-radius: 50%; font-size: 12px; font-weight: 700; margin-right: 10px;">1</span>
												<h4 style="display: inline; margin: 0; font-size: 16px; color: #1e293b;"><?php esc_html_e( 'Groq Console: Home', 'cyphex-image-hunter' ); ?></h4>
											</div>
											<p style="font-size: 14px; color: #64748b; margin-bottom: 20px;"><?php esc_html_e( 'Start at the Home dashboard. This gives you an overview of your AI usage and capabilities.', 'cyphex-image-hunter' ); ?></p>
											<img src="<?php echo esc_url( plugins_url( 'assets/images/groq-step-home.png', __FILE__ ) ); ?>" style="width: 100%; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);" />
										</div>

										<!-- Step 2 -->
										<div style="margin-bottom: 50px; border-left: 4px solid #3b82f6; padding-left: 25px;">
											<div style="margin-bottom: 15px;">
												<span style="background: #3b82f6; color: #fff; width: 24px; height: 24px; display: inline-flex; items-center; justify-content: center; border-radius: 50%; font-size: 12px; font-weight: 700; margin-right: 10px;">2</span>
												<h4 style="display: inline; margin: 0; font-size: 16px; color: #1e293b;"><?php esc_html_e( 'Available AI Models', 'cyphex-image-hunter' ); ?></h4>
											</div>
											<p style="font-size: 14px; color: #64748b; margin-bottom: 20px;"><?php esc_html_e( 'You can see the high-speed Llama and Mixtral models that power this plugin.', 'cyphex-image-hunter' ); ?></p>
											<img src="<?php echo esc_url( plugins_url( 'assets/images/groq-step-models.png', __FILE__ ) ); ?>" style="width: 100%; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);" />
										</div>

										<!-- Step 3 -->
										<div style="margin-bottom: 50px; border-left: 4px solid #3b82f6; padding-left: 25px;">
											<div style="margin-bottom: 15px;">
												<span style="background: #3b82f6; color: #fff; width: 24px; height: 24px; display: inline-flex; items-center; justify-content: center; border-radius: 50%; font-size: 12px; font-weight: 700; margin-right: 10px;">3</span>
												<h4 style="display: inline; margin: 0; font-size: 16px; color: #1e293b;"><?php esc_html_e( 'The API Keys List', 'cyphex-image-hunter' ); ?></h4>
											</div>
											<p style="font-size: 14px; color: #64748b; margin-bottom: 20px;"><?php esc_html_e( 'Access your existing keys here. For security, we have masked the keys in this tutorial.', 'cyphex-image-hunter' ); ?></p>
											<div style="position: relative; overflow: hidden; border-radius: 12px; border: 1px solid #e2e8f0;">
												<img src="<?php echo esc_url( plugins_url( 'assets/images/groq-step-list.png', __FILE__ ) ); ?>" style="width: 100%; display: block;" />
												<div style="position: absolute; top: 25%; left: 30%; width: 20%; height: 60%; background: rgba(255,255,255,0.2); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-radius: 4px;"></div>
											</div>
										</div>

										<!-- Step 4 -->
										<div style="margin-bottom: 50px; border-left: 4px solid #3b82f6; padding-left: 25px;">
											<div style="margin-bottom: 15px;">
												<span style="background: #3b82f6; color: #fff; width: 24px; height: 24px; display: inline-flex; items-center; justify-content: center; border-radius: 50%; font-size: 12px; font-weight: 700; margin-right: 10px;">4</span>
												<h4 style="display: inline; margin: 0; font-size: 16px; color: #1e293b;"><?php esc_html_e( 'Generate Your Key', 'cyphex-image-hunter' ); ?></h4>
											</div>
											<p style="font-size: 14px; color: #64748b; margin-bottom: 20px;"><?php esc_html_e( 'Click "Create API Key" and enter a name to identify this connection.', 'cyphex-image-hunter' ); ?></p>
											<img src="<?php echo esc_url( plugins_url( 'assets/images/groq-step-modal.png', __FILE__ ) ); ?>" style="width: 100%; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);" />
										</div>

										<!-- Step 5 -->
										<div style="margin-bottom: 50px; border-left: 4px solid #3b82f6; padding-left: 25px;">
											<div style="margin-bottom: 15px;">
												<span style="background: #3b82f6; color: #fff; width: 24px; height: 24px; display: inline-flex; items-center; justify-content: center; border-radius: 50%; font-size: 12px; font-weight: 700; margin-right: 10px;">5</span>
												<h4 style="display: inline; margin: 0; font-size: 16px; color: #1e293b;"><?php esc_html_e( 'Copy & Secure Your Key', 'cyphex-image-hunter' ); ?></h4>
											</div>
											<p style="font-size: 14px; color: #64748b; margin-bottom: 20px;"><?php esc_html_e( 'Copy your new key. Once you close this modal, you will not be able to see the key again.', 'cyphex-image-hunter' ); ?></p>
											<div style="position: relative; overflow: hidden; border-radius: 12px; border: 1px solid #e2e8f0;">
												<img src="<?php echo esc_url( plugins_url( 'assets/images/groq-step-success.png', __FILE__ ) ); ?>" style="width: 100%; display: block;" />
												<div style="position: absolute; top: 53%; left: 33%; width: 32%; height: 4%; background: rgba(255,255,255,0.4); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); border-radius: 4px;"></div>
											</div>
										</div>
									</div>
								</section>

								<section id="setup" style="margin-bottom: 40px;">
									<h3><?php esc_html_e( '🛠 Initial Setup & API Keys', 'cyphex-image-hunter' ); ?></h3>
									<p><?php esc_html_e( 'To start hunting images, you need to connect your API keys in the "General Settings" tab.', 'cyphex-image-hunter' ); ?></p>
									<ul style="list-style: disc; padding-left: 20px;">
										<li><strong><?php esc_html_e( 'Groq AI:', 'cyphex-image-hunter' ); ?></strong> <?php esc_html_e( 'Mandatory for AI features. Get it at console.groq.com.', 'cyphex-image-hunter' ); ?></li>
										<li><strong><?php esc_html_e( 'Stock Providers:', 'cyphex-image-hunter' ); ?></strong> <?php esc_html_e( 'Connect Pexels or Pixabay for standard photography.', 'cyphex-image-hunter' ); ?></li>
									</ul>
								</section>

								<section id="using" style="margin-bottom: 40px;">
									<h3><?php esc_html_e( '🎯 How to Use the Image Hunter', 'cyphex-image-hunter' ); ?></h3>
									<ol style="padding-left: 20px;">
										<li><?php esc_html_e( 'Open any Post and click "Add Media".', 'cyphex-image-hunter' ); ?></li>
										<li><?php esc_html_e( 'Select "Cyphex Image Hunt" from the sidebar.', 'cyphex-image-hunter' ); ?></li>
										<li><?php esc_html_e( 'Search for anything—AI will handle the rest.', 'cyphex-image-hunter' ); ?></li>
									</ol>
								</section>

								<section id="trouble" style="margin-bottom: 40px;">
									<h3><?php esc_html_e( '🔧 Troubleshooting & FAQ', 'cyphex-image-hunter' ); ?></h3>
									<div style="background: #fff5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #d63638;">
										<h4 style="margin-top: 0;"><?php esc_html_e( 'API Links Blocked?', 'cyphex-image-hunter' ); ?></h4>
										<p style="margin-bottom: 0;"><?php esc_html_e( 'If clicking buttons doesn\'t work, right-click and "Open link in new tab".', 'cyphex-image-hunter' ); ?></p>
									</div>
								</section>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>
			<script>
				document.addEventListener('DOMContentLoaded', function() {
					const links = document.querySelectorAll('.cyphex-external-link');
					links.forEach(link => {
						link.addEventListener('click', function(e) {
							e.preventDefault();
							const url = this.getAttribute('href');
							const win = window.open(url, '_blank', 'noopener,noreferrer');
							if (win) win.opener = null;
						});
					});
				});
			</script>
			<?php
		}

		// --- 2. JavaScript, CSS & Templates ---

		public function cyphex_enqueue_media_assets() {
			// Load Puter.js for AI generation
			wp_enqueue_script('puter-js', 'https://js.puter.com/v2/', array(), '2.0', true );
			
			// Enqueue CSS
			wp_enqueue_style('cyphex-image-hunter-admin-css', plugins_url('assets/css/cyphex-image-hunter-admin.css', __FILE__ ), array(), '1.5.4');
			
			// Enqueue JS
			wp_enqueue_script( 'cyphex-image-hunter-admin-js', plugins_url( 'assets/js/cyphex-image-hunter-admin.js', __FILE__ ), array( 'jquery', 'wp-util', 'media-views', 'media-models' ), '1.5.4', true );
			
			// Localize script with translatable strings
			wp_localize_script( 'cyphex-image-hunter-admin-js', 'cyphex_image_hunter_vars', array(
				'nonce'	 => wp_create_nonce( 'cyphex_image_hunter_nonce' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'isPro'	  => cyphex_is_pro(),
				'labels' => array(
					'hunt'				=> esc_js( __( 'Cyphex Image Hunt', 'cyphex-image-hunter' ) ),
					'search'			=> esc_js( __( 'Search', 'cyphex-image-hunter' ) ),
					'generate'			=> esc_js( __( 'Generate', 'cyphex-image-hunter' ) ),
					'statusSearching'	=> esc_js( __( 'Searching...', 'cyphex-image-hunter' ) ),
					'statusGenerating'	=> esc_js( __( 'AI Generating...', 'cyphex-image-hunter' ) ),
					'statusDownloading' => esc_js( __( 'Importing to Library', 'cyphex-image-hunter' ) ),
					'statusRefining'	=> esc_js( __( 'Refining with AI...', 'cyphex-image-hunter' ) ),
					'errorSearch'		=> esc_js( __( 'Search failed. Please try again.', 'cyphex-image-hunter' ) ),
					'errorServer'		=> esc_js( __( 'Server error. Please check your connection.', 'cyphex-image-hunter' ) ),
					'errorPuter'		=> esc_js( __( 'AI Service (Puter) not available.', 'cyphex-image-hunter' ) ),
					'errorRefine'		=> esc_js( __( 'AI Refinement failed.', 'cyphex-image-hunter' ) ),
					'refinePrompt'		=> esc_js( __( 'How should I modify this image? (e.g., "Make it more professional", "Change color to blue")', 'cyphex-image-hunter' ) ),
					'description'		=> esc_js( __( 'Description', 'cyphex-image-hunter' ) ),
					'sourceModel'		=> esc_js( __( 'Source / Model', 'cyphex-image-hunter' ) ),
					'dimensions'		=> esc_js( __( 'Dimensions (WxH )', 'cyphex-image-hunter' ) ),
					'maxKb'				=> esc_js( __( 'Max KB', 'cyphex-image-hunter' ) ),
					'options'			=> esc_js( __( 'Options', 'cyphex-image-hunter' ) ),
					'aiOptimize'		=> esc_js( __( 'AI Optimize', 'cyphex-image-hunter' ) ),
					'webp'				=> esc_js( __( 'WebP', 'cyphex-image-hunter' ) ),
					'autoCredit'		=> esc_js( __( 'Auto-Credit', 'cyphex-image-hunter' ) ),
					'aiAlt'				=> esc_js( __( 'AI Alt-Text', 'cyphex-image-hunter' ) ),
					'aiDesc'			=> esc_js( __( 'AI Description', 'cyphex-image-hunter' ) ),
				),
			) );
		}

		public function cyphex_print_assets_and_templates() {
			if ( ! current_user_can( 'upload_files')) return;
			?>
			<!-- Backbone Templates -->
			<script type="text/html" id="tmpl-cyphex-image-hunter-search-panel">
				<div class="cyphex_image_hunter_toolbar">
					<div class="cyphex_image_hunter_group">
						<span class="cyphex_image_hunter_label">{{ cyphex_image_hunter_vars.labels.description }}</span>
						<input type="text" id="cyphex_image_hunter_search_input" placeholder="e.g. 'Cyberpunk city'">
					</div>
					<div class="cyphex_image_hunter_group">
						<span class="cyphex_image_hunter_label">{{ cyphex_image_hunter_vars.labels.sourceModel }}</span>
						<select id="cyphex_image_hunter_source">
							<option value="puter-flux" selected><?php esc_html_e( 'Generate (Flux 1.1 - Free/Fast )', 'cyphex-image-hunter'); ?></option>
							<option value="puter-sd3"><?php esc_html_e( 'Generate (Stable Diffusion 3 - Free )', 'cyphex-image-hunter'); ?></option>
							<option value="puter-dalle3"><?php esc_html_e( 'Generate (DALL-E 3 HD - Credits )', 'cyphex-image-hunter'); ?></option>
							<option value="pexels"><?php esc_html_e( 'Pexels', 'cyphex-image-hunter'); ?></option>
							<option value="pixabay"><?php esc_html_e( 'Pixabay', 'cyphex-image-hunter'); ?></option>
						</select>
					</div>
					<div class="cyphex_image_hunter_group">
						<span class="cyphex_image_hunter_label">{{ cyphex_image_hunter_vars.labels.dimensions }}</span>
						<div style="display:flex; gap:5px;">
							<input type="number" id="cyphex_image_hunter_width" class="cyphex_image_hunter_input_sm" placeholder="W">
							<input type="number" id="cyphex_image_hunter_height" class="cyphex_image_hunter_input_sm" placeholder="H">
						</div>
					</div>
					<div class="cyphex_image_hunter_group">
						<span class="cyphex_image_hunter_label">{{ cyphex_image_hunter_vars.labels.maxKb }}</span>
						<input type="number" id="cyphex_image_hunter_size" class="cyphex_image_hunter_input_sm" placeholder="20">
					</div>
					<div class="cyphex_image_hunter_group">
						<span class="cyphex_image_hunter_label">{{ cyphex_image_hunter_vars.labels.options }}</span>
						<div style="display:flex; gap:10px;">
							<label class="cyphex_image_hunter_checkbox_label">
								<input type="checkbox" id="cyphex_image_hunter_optimize" checked> {{ cyphex_image_hunter_vars.labels.aiOptimize }}
							</label>
							<label class="cyphex_image_hunter_checkbox_label">
								<input type="checkbox" id="cyphex_image_hunter_webp"> {{ cyphex_image_hunter_vars.labels.webp }}
							</label>
							<label class="cyphex_image_hunter_checkbox_label">
								<input type="checkbox" id="cyphex_image_hunter_credit" checked> {{ cyphex_image_hunter_vars.labels.autoCredit }}
							</label>
							<label class="cyphex_image_hunter_checkbox_label {{ !cyphex_image_hunter_vars.isPro ? 'cyphex-locked' : '' }}">
								<input type="checkbox" id="cyphex_image_hunter_ai_alt" {{ cyphex_image_hunter_vars.isPro ? 'checked' : 'disabled' }}> {{ cyphex_image_hunter_vars.labels.aiAlt }} {{ !cyphex_image_hunter_vars.isPro ? '🔒' : '' }}
							</label>
							<label class="cyphex_image_hunter_checkbox_label {{ !cyphex_image_hunter_vars.isPro ? 'cyphex-locked' : '' }}">
								<input type="checkbox" id="cyphex_image_hunter_ai_desc" {{ cyphex_image_hunter_vars.isPro ? 'checked' : 'disabled' }}> {{ cyphex_image_hunter_vars.labels.aiDesc }} {{ !cyphex_image_hunter_vars.isPro ? '🔒' : '' }}
							</label>
						</div>
					</div>
					<div class="cyphex_image_hunter_group">
						<span class="cyphex_image_hunter_label">&nbsp;</span>
						<button type="button" class="button button-primary" id="cyphex_image_hunter_search_btn">{{ cyphex_image_hunter_vars.labels.hunt }}</button>
					</div>
				</div>
				
				<div id="cyphex_image_hunter_ai_feedback"></div>
				<div id="cyphex_image_hunter_status"></div>

				<div class="cyphex_image_hunter_results_wrapper">
					<ul id="cyphex_image_hunter_results_list"></ul>
				</div>
			</script>

			<script type="text/html" id="tmpl-cyphex-image-hunter-image-item">
				<li class="cyphex_image_hunter_attachment" data-url="{{ data.src.original }}" data-source="{{ data.source }}" data-base-prompt="{{ data.prompt }}" data-photographer="{{ data.photographer }}" data-link="{{ data.link }}">
					<div class="thumbnail">
						<img src="{{ data.src.medium }}" draggable="false" alt="">
						<div class="cyphex_image_hunter_overlay">
							<button type="button" class="cyphex_image_hunter_btn_action cyphex_image_hunter_btn_primary cyphex_image_hunter_action_download"><?php esc_html_e( 'Download', 'cyphex-image-hunter'); ?></button>
							<button type="button" class="cyphex_image_hunter_btn_action cyphex_image_hunter_action_refine"><?php esc_html_e( 'Refine with AI', 'cyphex-image-hunter'); ?></button>
						</div>
					</div>
					<div class="cyphex_image_hunter_meta">
						<span>{{ data.photographer }}</span>
						<span class="cyphex_image_hunter_source_badge">{{ data.source }}</span>
					</div>
				</li>
			</script>
			<?php
		}

		// --- 3. AJAX Logic ---

		public function cyphex_handle_ajax_refine_prompt() {
			check_ajax_referer( 'cyphex_image_hunter_nonce', 'nonce' );
			if ( ! current_user_can( 'upload_files' ) ) {
				wp_send_json_error( 'Permission denied' );
			}
			$groq_key = get_option( 'cyphex_image_hunter_groq_key' );
			
			$base_prompt = isset($_POST['base_prompt']) ? sanitize_text_field( wp_unslash( $_POST['base_prompt'])) : '';
			$instruction = isset($_POST['instruction']) ? sanitize_text_field( wp_unslash( $_POST['instruction'])) : '';

			if ( empty($base_prompt ) || empty($instruction )) {
				wp_send_json_error( 'Missing required parameters');
			}

			if (!$groq_key ) wp_send_json_error( 'Missing API Key');

			$response = wp_remote_post( $this->groq_api_url, array(
				'headers' => array('Authorization' => 'Bearer ' . $groq_key, 'Content-Type' => 'application/json'),
				'body' => json_encode( array(
					'model' => 'llama-3.3-70b-versatile',
					'messages' => array(
						array('role' => 'system', 'content' => 'You are an AI Image Prompt Engineer. User will provide an existing prompt and a correction instruction. Rewrite the prompt to incorporate the instruction naturally. Return ONLY the new prompt text.'),
						array('role' => 'user', 'content' => "Original Prompt: $base_prompt\nInstruction: $instruction")
					),
					'max_tokens' => 100
				)),
				'timeout' => 15
			));

			if ( is_wp_error($response )) wp_send_json_error( 'Groq Error');
			$body = json_decode( wp_remote_retrieve_body($response ), true );
			$new_prompt = $body['choices'][0]['message']['content'] ?? $base_prompt . ' ' . $instruction;
			
			wp_send_json_success( trim($new_prompt ));
		}

		public function cyphex_handle_ajax_search() {
			check_ajax_referer( 'cyphex_image_hunter_nonce', 'nonce' );
			if ( ! current_user_can( 'upload_files' ) ) {
				wp_send_json_error( 'Permission denied' );
			}
			
			$groq_key = get_option( 'cyphex_image_hunter_groq_key');
			$pexels_key = get_option( 'cyphex_image_hunter_pexels_key');
			$pixabay_key = get_option( 'cyphex_image_hunter_pixabay_key');
			
			$prompt = isset($_POST['prompt']) ? sanitize_text_field( wp_unslash( $_POST['prompt'])) : '';
			if ( empty($prompt )) wp_send_json_error( 'Missing prompt');
			
			$source = isset($_POST['source']) ? sanitize_text_field( wp_unslash( $_POST['source'])) : '';
			$optimize = isset($_POST['optimize']) ? intval( $_POST['optimize']) : 1;

			if ($source === 'pexels' && !$pexels_key ) wp_send_json_error( 'Missing Pexels API Key');
			if ($source === 'pixabay' && !$pixabay_key ) wp_send_json_error( 'Missing Pixabay API Key');

			// 1. Groq Optimization
			$optimized_query = $prompt;
			if ($optimize && $groq_key ) {
				$is_ai_gen = ( strpos( $source, 'puter' ) === 0 );
				$system_message = $is_ai_gen 
					? 'You are an AI Image Prompt Engineer. Convert the user simple request into a highly detailed, descriptive, and cinematic prompt for an AI image generator (Flux/DALL-E). Describe the scene, lighting, and mood. Return ONLY the prompt text.'
					: 'Analyze the visual elements described by the user and convert them into a precise, comma-separated list of English search keywords optimized for finding stock photography. Return ONLY the keywords.';

				$groq_response = wp_remote_post( $this->groq_api_url, array(
					'headers' => array('Authorization' => 'Bearer ' . $groq_key, 'Content-Type' => 'application/json'),
					'body' => json_encode( array(
						'model' => 'llama-3.3-70b-versatile',
						'messages' => array(
							array('role' => 'system', 'content' => $system_message),
							array('role' => 'user', 'content' => $prompt)
						),
						'max_tokens' => 70
					)),
					'timeout' => 15
				));

				if ( ! is_wp_error($groq_response )) {
					$groq_body = json_decode( wp_remote_retrieve_body($groq_response ), true );
					$optimized_query = isset($groq_body['choices'][0]['message']['content']) ? trim($groq_body['choices'][0]['message']['content']) : $prompt;
				}
			}

			if ($source === 'puter') {
				 wp_send_json_success( array(
					'results' => array(),
					'optimized_query' => $optimized_query,
					'is_client_gen' => true
				));
				return;
			}

			$results = array();

			if ($source === 'pexels') {
				$response = wp_remote_get( $this->pexels_api_url . '?per_page=12&orientation=landscape&query=' . urlencode( $optimized_query ), array(
					'headers' => array('Authorization' => $pexels_key), 'timeout' => 15
				));
				if ( is_wp_error($response )) wp_send_json_error( 'Pexels Error');
				$body = json_decode( wp_remote_retrieve_body($response ), true );
				if ( ! empty($body['photos'])) {
					foreach ( $body['photos'] as $p ) {
						$results[] = array(
							'src' => array('original' => $p['src']['original'], 'medium' => $p['src']['medium']),
							'photographer' => $p['photographer'],
							'link' => $p['photographer_url'],
							'source' => 'Pexels'
						);
					}
				}
			} elseif ($source === 'pixabay') {
				$url = $this->pixabay_api_url . '?key=' . $pixabay_key . '&q=' . urlencode( $optimized_query ) . '&image_type=photo&per_page=12';
				$response = wp_remote_get( $url, array('timeout' => 15));
				if ( is_wp_error($response )) wp_send_json_error( 'Pixabay Error: ' . $response->get_error_message());
				$body = json_decode( wp_remote_retrieve_body($response ), true );
				if ( ! empty($body['hits'])) {
					foreach ( $body['hits'] as $h ) {
						$results[] = array(
							'src' => array('original' => $h['largeImageURL'], 'medium' => $h['webformatURL']),
							'photographer' => $h['user'],
							'link' => $h['pageURL'],
							'source' => 'Pixabay'
						);
					}
				}
			}

			if ( empty($results )) wp_send_json_error( 'No results found for: ' . $optimized_query );
			
			wp_send_json_success( array(
				'results' => $results,
				'optimized_query' => $optimized_query
			)); 
		}

		public function cyphex_handle_ajax_sideload() {
			check_ajax_referer( 'cyphex_image_hunter_nonce', 'nonce');
			if ( ! current_user_can( 'upload_files')) wp_send_json_error( 'Permission denied');
			
			if (function_exists('wp_raise_memory_limit')) {
				wp_raise_memory_limit('image');
			}
			
			$image_url_or_data = isset($_POST['image_url']) ? sanitize_textarea_field(wp_unslash( $_POST['image_url'])) : '';
			if ( empty($image_url_or_data )) wp_send_json_error( 'Missing image data');
			
			$source = isset($_POST['source']) ? sanitize_text_field( wp_unslash( $_POST['source'])) : '';
			$width = isset($_POST['width']) ? intval( $_POST['width']) : 0;
			$height = isset($_POST['height']) ? intval( $_POST['height']) : 0;
			$max_kb = isset($_POST['max_size_kb']) ? intval( $_POST['max_size_kb']) : 0;
			$convert_webp = isset($_POST['convert_webp']) ? filter_var(wp_unslash( $_POST['convert_webp']), FILTER_VALIDATE_BOOLEAN ) : false;
			$prompt = isset($_POST['prompt']) ? sanitize_text_field( wp_unslash( $_POST['prompt'])) : '';
			$photographer = isset($_POST['photographer']) ? sanitize_text_field( wp_unslash( $_POST['photographer'])) : '';
			$link = isset($_POST['link']) ? esc_url_raw(wp_unslash( $_POST['link'])) : '';
			$auto_credit = isset($_POST['auto_credit']) ? filter_var(wp_unslash( $_POST['auto_credit']), FILTER_VALIDATE_BOOLEAN ) : get_option( 'cyphex_image_hunter_auto_credit');
			$ai_alt_toggle = isset($_POST['ai_alt']) ? filter_var(wp_unslash( $_POST['ai_alt']), FILTER_VALIDATE_BOOLEAN ) : false;
			$ai_desc_toggle = isset($_POST['ai_desc']) ? filter_var(wp_unslash( $_POST['ai_desc']), FILTER_VALIDATE_BOOLEAN ) : false;
			$groq_key = get_option( 'cyphex_image_hunter_groq_key');

			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			$attachment_id = null;
			$alt_text = '';
			$ai_description = '';

			// PRO FEATURE: AI Metadata Generation
			if ( cyphex_is_pro() && ! empty( $groq_key ) && ( $ai_alt_toggle || $ai_desc_toggle ) ) {
				$prompt_tasks = array();
				if ( $ai_alt_toggle ) $prompt_tasks[] = 'Write a short (max 10 words) Alt Text for SEO.';
				if ( $ai_desc_toggle ) $prompt_tasks[] = 'Write a detailed 2-sentence description for the image metadata.';

				$alt_response = wp_remote_post( $this->groq_api_url, array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $groq_key,
						'Content-Type'	=> 'application/json'
					),
					'body' => json_encode( array(
						'model' => 'llama-3.3-70b-versatile',
						'messages' => array(
							array( 'role' => 'system', 'content' => 'You are an image metadata expert. Return JSON format with keys "alt" and "description". Base it on the user prompt.' ),
							array( 'role' => 'user', 'content' => "Prompt: $prompt\nTasks: " . implode( ' ', $prompt_tasks ) )
						),
						'response_format' => array( 'type' => 'json_object' ),
						'max_tokens' => 150
					) ),
					'timeout' => 12
				) );

				if ( ! is_wp_error( $alt_response ) ) {
					$meta_body = json_decode( wp_remote_retrieve_body( $alt_response ), true );
					if ( isset( $meta_body['choices'][0]['message']['content'] ) ) {
						$json_meta = json_decode( $meta_body['choices'][0]['message']['content'], true );
						if ( $ai_alt_toggle ) $alt_text = $json_meta['alt'] ?? '';
						if ( $ai_desc_toggle ) $ai_description = $json_meta['description'] ?? '';
					}
				}
			}

			$filename_base = sanitize_title($prompt );
			if ( empty($filename_base )) $filename_base = 'gen-image-' . time();
			else $filename_base .= '-' . time();

			// Handle Base64 (AI Generation)
			if ( 0 === strpos( $image_url_or_data, 'data:image' ) && strpos( $image_url_or_data, ';base64,' ) !== false ) {
				$header_end = strpos($image_url_or_data, ',') + 1;
				$header = substr($image_url_or_data, 0, $header_end);
				$data = substr($image_url_or_data, $header_end);
				$type = 'png'; 
				if (preg_match('/image\/(\w+)/', $header, $matches)) {
					$type = strtolower($matches[1]);
				}
				$data = base64_decode(str_replace(' ', '+', $data));
				if ($data === false) wp_send_json_error('Base64 decode failed');
				
				$upload = wp_upload_bits($filename_base . '.' . $type, null, $data );
				if ($upload['error']) wp_send_json_error( $upload['error']);
				
				$filename = $upload['file'];
				$wp_filetype = wp_check_filetype($filename, null );
				$attachment = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_title' => sanitize_text_field( $prompt ),
					'post_content' => sanitize_textarea_field( $ai_description ), // AI Description
					'post_status' => 'inherit'
				);
				$attachment_id = wp_insert_attachment($attachment, $filename );
				if ( ! is_wp_error($attachment_id )) {
					$attach_data = wp_generate_attachment_metadata($attachment_id, $filename );
					wp_update_attachment_metadata($attachment_id, $attach_data );
				}
			} else {
				// Handle Standard URL or AI Redirect Link
				$image_url = esc_url_raw($image_url_or_data);
				if ($source === 'Pexels') $image_url = strtok($image_url, '?');
				
				// Try standard sideload first
				$attachment_id = media_sideload_image($image_url, 0, $prompt, 'id');
				
				// Fallback: If standard sideload fails (e.g. redirects/CORS), try direct download
				if ( is_wp_error($attachment_id)) {
					$response = wp_safe_remote_get($image_url, array('timeout' => 30));
					if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
						$data = wp_remote_retrieve_body($response);
						$type = 'png';
						$headers = wp_remote_retrieve_headers($response);
						if (isset($headers['content-type']) && preg_match('/image\/(\w+)/', $headers['content-type'], $matches)) {
							$type = $matches[1];
						}
						
						$upload = wp_upload_bits($filename_base . '.' . $type, null, $data);
						if (!$upload['error']) {
							$filename = $upload['file'];
							$wp_filetype = wp_check_filetype($filename, null);
							$attachment = array(
								'post_mime_type' => $wp_filetype['type'],
								'post_title' => sanitize_text_field($prompt),
								'post_content' => sanitize_textarea_field( $ai_description ), // AI Description
								'post_status' => 'inherit'
							);
							$attachment_id = wp_insert_attachment($attachment, $filename);
							if ( ! is_wp_error( $attachment_id ) ) {
								$attach_data = wp_generate_attachment_metadata($attachment_id, $filename);
								wp_update_attachment_metadata($attachment_id, $attach_data);
								
								// Set Alt Text if generated
								if ( ! empty( $alt_text ) ) {
									update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
								}
							}
						}
					}
				}
			}

			// Set Alt Text for Base64 path too
			if ( ! is_wp_error( $attachment_id ) && ! empty( $alt_text ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );
			}

			if ( is_wp_error( $attachment_id ) ) {
				wp_send_json_error( sprintf( /* translators: %s: error message */ __( 'Failed to import image: %s', 'cyphex-image-hunter' ), $attachment_id->get_error_message() ) );
			}

			// --- Robust Post-Processing (Applied to ALL sources ) ---
			$file_path = get_attached_file($attachment_id );
			$needs_resize = ($width > 0 && $height > 0 );
			$needs_compress = ($max_kb > 0 );

			// Step 1: Exact Resize (Upscale/Crop if needed ) & WebP Conversion
			if ($needs_resize || $convert_webp ) {
				// We use our custom forced resize helper first if dimensions requested
				if ($needs_resize ) {
					$this->cyphex_force_resize_crop($file_path, $width, $height );
				}
				
				// Then handle WebP conversion
				if ($convert_webp ) {
					$editor = wp_get_image_editor($file_path );
					if ( ! is_wp_error($editor )) {
						$path_parts = pathinfo($file_path );
						$new_path = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.webp';
						$saved = $editor->save($new_path, 'image/webp');
						
						if ( ! is_wp_error($saved )) {
							wp_delete_file($file_path );
							$file_path = $new_path;
							update_attached_file($attachment_id, $file_path );
							wp_update_post(array(
								'ID' => $attachment_id,
								'post_mime_type' => 'image/webp'
							));
						}
					}
				}
			}

			// Step 2: Compression Loop (Separate Step )
			if ($needs_compress ) {
				clearstatcache();
				$current_size = filesize($file_path );
				$target_bytes = $max_kb * 1024;
				$quality = 90;

				if ($current_size && $current_size > $target_bytes ) {
					while ($quality >= 10 ) {
						$editor = wp_get_image_editor($file_path );
						if ( is_wp_error($editor )) break;
						$editor->set_quality($quality );
						$saved = $editor->save($file_path );
						unset($editor );

						if ( ! is_wp_error($saved )) {
							clearstatcache();
							$current_size = filesize($file_path );
							if ($current_size <= $target_bytes ) break;
							$quality -= 10;
						} else {
							break;
						}
					}
				}
			}

			// Finally: Update WP Metadata
			$metadata = wp_generate_attachment_metadata($attachment_id, $file_path );
			wp_update_attachment_metadata($attachment_id, $metadata );

			// --- Auto-Generate Metadata with AI ---
			if ($attachment_id && $groq_key && !empty($prompt )) {
				$this->cyphex_generate_ai_metadata($attachment_id, $prompt, $groq_key );
			}

			// --- Append Auto Credit ---
			if ($attachment_id && $auto_credit && !empty($photographer ) && $source !== 'puter') {
				$post = get_post($attachment_id );
				if ( ! empty($link )) {
					$credit_text = sprintf( 'Credit: <a href="%s" target="_blank" rel="noopener">%s</a> / %s', esc_url( $link ), esc_html($photographer ), esc_html($source ));
				} else {
					$credit_text = sprintf( 'Credit: %s / %s', esc_html($photographer ), esc_html($source ));
				}
				
				$separator = !empty($post->post_excerpt ) ? '<br/>' : '';
				$new_excerpt = trim($post->post_excerpt . $separator . $credit_text );
				
				wp_update_post(array(
					'ID' => $attachment_id,
					'post_excerpt' => wp_kses_post($new_excerpt )
				));
			}

			wp_send_json_success( array('id' => $attachment_id));
		}

		// Custom Helper to Force Exact Dimensions (Supports Upscaling )
		private function cyphex_force_resize_crop($file_path, $target_w, $target_h ) {
			$editor = wp_get_image_editor($file_path );
			if ( is_wp_error($editor )) return;

			$size = $editor->get_size();
			if (!$size ) return;

			$orig_w = $size['width'];
			$orig_h = $size['height'];

			// Calculate aspect ratios
			$src_ratio = $orig_w / $orig_h;
			$dst_ratio = $target_w / $target_h;

			// 1. Crop to correct aspect ratio first (from center )
			if ($src_ratio > $dst_ratio ) {
				// Source is wider -> Crop width
				$temp_h = $orig_h;
				$temp_w = (int) ($orig_h * $dst_ratio );
			} else {
				// Source is taller -> Crop height
				$temp_w = $orig_w;
				$temp_h = (int) ($orig_w / $dst_ratio );
			}

			$crop_x = (int) (($orig_w - $temp_w ) / 2 );
			$crop_y = (int) (($orig_h - $temp_h ) / 2 );

			$editor->crop($crop_x, $crop_y, $temp_w, $temp_h, $target_w, $target_h );
			$editor->save($file_path );
		}

		private function cyphex_generate_ai_metadata($attachment_id, $prompt, $api_key ) {
			$response = wp_remote_post( $this->groq_api_url, array(
				'headers' => array('Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'),
				'body' => json_encode( array(
					'model' => 'llama-3.3-70b-versatile',
					'messages' => array(
						array('role' => 'system', 'content' => 'You are an SEO expert. Generate JSON metadata for an image based on the user prompt. Return valid JSON with keys: title, alt_text, caption, description.'),
						array('role' => 'user', 'content' => "Generate metadata for image: " . $prompt)
					),
					'max_tokens' => 200
				)),
				'timeout' => 15
			));
		
			if ( is_wp_error($response )) return;
		
			$body = json_decode( wp_remote_retrieve_body($response ), true );
			$content = $body['choices'][0]['message']['content'] ?? '';
			
			if (preg_match('/\{.*\}/s', $content, $matches )) {
				$meta = json_decode( $matches[0], true );
				if ($meta ) {
					wp_update_post(array(
						'ID' => $attachment_id,
						'post_title' => sanitize_text_field( $meta['title'] ?? ''),
						'post_excerpt' => sanitize_text_field( $meta['caption'] ?? ''),
						'post_content' => sanitize_textarea_field($meta['description'] ?? ''),
					));
					update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $meta['alt_text'] ?? ''));
				}
			}
		}
	}

	/**
	 * Global helper function to check for Pro status.
	 *
	 * @return bool
	 */
	function cyphex_is_pro() {
		return defined( 'CYPHEX_IMAGE_HUNTER_PRO' ) && CYPHEX_IMAGE_HUNTER_PRO;
	}

	$cyphex_image_hunter_plugin = new Cyphex_Image_Hunter_Plugin();

	// Define Pro constant for easy access
	if ( $cyphex_image_hunter_plugin->pro->is_pro() ) {
		define( 'CYPHEX_IMAGE_HUNTER_PRO', true );
	} else {
		define( 'CYPHEX_IMAGE_HUNTER_PRO', false );
	}
}