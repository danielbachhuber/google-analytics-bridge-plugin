<?php
/**
 * Renders the settings template part.
 *
 * @package google-analytics-bridge
 */

use GAB\Admin;

?>
<div class="wrap">
	<h2><?php esc_html_e( 'Google Analytics Settings', 'google-analytics-bridge' ); ?></h2>

	<?php
	if ( ! empty( $_GET['success'] ) ) {
		switch ( $_GET['success'] ) {
			case 'google-connect':
				$message = esc_html__( 'Successfully connected to Google.', 'google-analytics-bridge' );
				break;
			case 'google-disconnect':
				$message = esc_html__( 'Disconnected from Google.', 'google-analytics-bridge' );
				break;
			default:
				$message = '';
				break;
		}
		if ( $message ) {
			echo '<div class="message updated"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
	?>

	<?php if ( ! Admin::get_client_id() || ! admin::get_client_secret() ) : ?>
		<div class="message error"><p><?php esc_html_e( 'Client id and secret must be set before you can authenticate.', 'google-analytics-bridge' ); ?></p></div>
	<?php endif; ?>

	<div class="google-analytics-connection">
		<h3><?php esc_html_e( 'Google Analytics Connection', 'google-analytics-bridge' ); ?></h3>
		<?php
		if ( Admin::get_google_auth_details() ) :
			?>
		<button class="button-primary" disabled="disabled">
			<?php
			esc_html_e( 'Connected to Google Analytics', 'google-analytics-bridge' );
			?>
			</button>
			<a class="button" onclick="javascript:if ( ! confirm( '
			<?php
				echo esc_js(
					__( 'Are you sure you want to disconnect? GA integrations will be disabled until reconnected.', 'google-analytics-bridge' )
				);
			?>
				') ) { return false; }"
			href="<?php echo esc_url( Admin::get_disconnect_callback_url() ); ?>">
			<?php esc_html_e( 'Disconnect Google Analytics', 'google-analytics-bridge' ); ?>
			</a>
		<?php else : ?>
		<a href="<?php echo esc_url( Admin::get_auth_callback_url() ); ?>"
			class="button button-primary"><?php echo esc_html_e( 'Connect To Google Analytics', 'google-analytics-bridge' ); ?></a>
		<?php endif; ?>
	</div>

</div>
