<?php
/**
 * Full-width landing template: theme header and footer, no sidebar, no theme page title.
 */
defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="nry-main" class="nry-page">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php
get_footer();
