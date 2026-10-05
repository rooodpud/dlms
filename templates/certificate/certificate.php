<?php
/**
 * Course completion certificate (rendered to PDF by Dompdf, A4 landscape).
 *
 * Override by copying to yourtheme/deutschlms/certificate/certificate.php.
 * Dompdf supports a subset of CSS 2.1: use block/table layout and absolute
 * positioning, not flexbox or grid. Fonts: "DejaVu Sans", "DejaVu Serif"
 * (full Unicode, incl. umlauts). Images must be local files in the uploads
 * folder; remote URLs are blocked.
 *
 * @package DeutschLMS
 *
 * @var array $args {
 *     @type string $title              Certificate heading.
 *     @type string $student_name       Student's display name.
 *     @type string $course_title       Course title.
 *     @type string $completion_date    Formatted completion date.
 *     @type string $certificate_number Certificate number.
 *     @type string $signer             Name above the signature line ('' to hide).
 *     @type string $site_name          Site name.
 *     @type string $background_path    Absolute path of a background image ('' for none).
 * }
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $args['title'] ); ?></title>
<style>
	@page { margin: 0; }
	html, body { margin: 0; padding: 0; }
	body { font-family: "DejaVu Sans", sans-serif; color: #1f2933; }
	.background { position: absolute; top: 0; left: 0; width: 297mm; height: 210mm; z-index: -1; }
	.frame { position: absolute; top: 12mm; left: 12mm; right: 12mm; bottom: 12mm; border: 1.2mm solid #1d4ed8; }
	.inner { position: absolute; top: 15mm; left: 15mm; right: 15mm; bottom: 15mm; border: 0.3mm solid #9db4ef; text-align: center; }
	.site { margin-top: 16mm; font-size: 11pt; letter-spacing: 2pt; text-transform: uppercase; color: #52606d; }
	.title { margin-top: 8mm; font-family: "DejaVu Serif", serif; font-size: 32pt; font-weight: bold; color: #1d4ed8; }
	.lead { margin-top: 10mm; font-size: 12pt; color: #52606d; }
	.name { margin-top: 4mm; font-family: "DejaVu Serif", serif; font-size: 28pt; font-weight: bold; }
	.course { margin-top: 4mm; font-size: 18pt; font-weight: bold; }
	.date { margin-top: 6mm; font-size: 12pt; color: #52606d; }
	/* Anchored with `top`: Dompdf puts a `bottom`-anchored box inside .inner below the page. */
	.footer { position: absolute; left: 20mm; right: 20mm; top: 157mm; }
	.footer table { width: 100%; border-collapse: collapse; }
	.footer td { width: 50%; vertical-align: bottom; font-size: 10pt; color: #52606d; }
	.signature { border-top: 0.3mm solid #1f2933; padding-top: 2mm; width: 70mm; color: #1f2933; }
	.number { text-align: right; }
</style>
</head>
<body>
	<?php if ( '' !== $args['background_path'] ) : ?>
		<img class="background" src="<?php echo esc_attr( $args['background_path'] ); ?>" alt="">
	<?php endif; ?>
	<div class="frame"></div>
	<div class="inner">
		<div class="site"><?php echo esc_html( $args['site_name'] ); ?></div>
		<div class="title"><?php echo esc_html( $args['title'] ); ?></div>
		<div class="lead"><?php esc_html_e( 'This certifies that', 'deutschlms' ); ?></div>
		<div class="name"><?php echo esc_html( $args['student_name'] ); ?></div>
		<div class="lead"><?php esc_html_e( 'has successfully completed the course', 'deutschlms' ); ?></div>
		<div class="course"><?php echo esc_html( $args['course_title'] ); ?></div>
		<?php if ( '' !== $args['completion_date'] ) : ?>
			<div class="date">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: completion date. */
						__( 'Completed on %s', 'deutschlms' ),
						$args['completion_date']
					)
				);
				?>
			</div>
		<?php endif; ?>
		<div class="footer">
			<table>
				<tr>
					<td>
						<?php if ( '' !== $args['signer'] ) : ?>
							<div class="signature"><?php echo esc_html( $args['signer'] ); ?></div>
						<?php endif; ?>
					</td>
					<td class="number">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: certificate number. */
								__( 'Certificate no. %s', 'deutschlms' ),
								$args['certificate_number']
							)
						);
						?>
					</td>
				</tr>
			</table>
		</div>
	</div>
</body>
</html>
