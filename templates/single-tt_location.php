<?php
/**
 * Fallback single-city template.
 *
 * Used only when Breakdance is not active and the active theme ships no
 * tt_location-specific template. Breakdance users build this page visually with
 * the [tur_takvimi_city_*] shortcodes instead.
 *
 * @package TurTakvimi
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<main class="tt-city-page" id="tt-city-page">
		<article <?php post_class( 'tt-city-page__article' ); ?>>
			<header class="tt-city-page__header">
				<h1 class="tt-city-page__title"><?php the_title(); ?></h1>
			</header>

			<?php
			the_content();

			echo do_shortcode( '[tur_takvimi_city_schedule]' );
			echo do_shortcode( '[tur_takvimi_city_map]' );
			echo do_shortcode( '[tur_takvimi_city_stops]' );
			echo do_shortcode( '[tur_takvimi_postcode_search]' );
			?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
