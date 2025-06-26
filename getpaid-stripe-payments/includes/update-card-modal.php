<?php
/**
 * Contains the card update modal template.
 *
 */

defined( 'ABSPATH' ) || exit;

?>

<div class="bsui">
	<div  id="getpaid-stripe-update-payment-modal" class="modal" tabindex="-1" role="dialog">
		<div class="modal-dialog modal-dialog-centered modal-lg" role="checkout" style="max-width: 650px;">
			<div class="modal-content">

				<div class="modal-header">
        			<h5 class="modal-title"><?php esc_html_e( 'Update Payment Method', 'wpinv-stripe' ); ?></h5>
					<button type="button" class="close btn-close" data-bs-dismiss="modal" data-dismiss="modal" aria-label="<?php esc_attr_e( 'Close', 'wpinv-stripe' ); ?>">
						<?php if ( empty( $GLOBALS['aui_bs5'] ) ) : ?>
                            <span aria-hidden="true">×</span>
                        <?php endif; ?>
					</button>
				</div>

				<div class="modal-body" style="padding-top: 2rem; padding-bottom: 2rem;">

					<div class="getpaid-stripe-update-payment-method mb-3"></div>
					<div class="getpaid-stripe-update-payment-method-errors" class="mb-3 w-100">
						<!-- Card errors will appear here. -->
					</div>

					<?php

						if ( ! is_ssl() && ! $this->is_sandbox() ) {
							aui()->alert(
								array(
									'type'    => 'error',
									'content' => __( 'Stripe gateway requires HTTPS connection for live transactions.', 'wpinv-stripe' ),
								),
								true
							);
						}

					?>

                </div>

				<div class="modal-footer">
        			<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-dismiss="modal"><?php esc_html_e( 'Cancel', 'wpinv-stripe' ); ?></button>
        			<button type="button" class="btn btn-primary getpaid-process-updated-stripe-payment-method"><?php esc_html_e( 'Update', 'wpinv-stripe' ); ?></button>
      			</div>

			</div>
		</div>
	</div>
</div>
