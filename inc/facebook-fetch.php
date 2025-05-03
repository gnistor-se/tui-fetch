<?php

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\Dom\Selector\XPathSelector;

/**
 * A class to handle Facebook scraping.
 */
class FacebookFetch {
	/**
	 * The BrowserFactory object.
	 *
	 * @var BrowserFactory $browser
	 */
	private BrowserFactory $browser_factory;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->browser_factory = new BrowserFactory( '/Users/jespernilsson/Development/tui-fetch/chrome-headless-shell/mac_arm-136.0.7103.49/chrome-headless-shell-mac-arm64/chrome-headless-shell' );
	}

	/**
	 * Get events from source url.
	 *
	 * @param string $source A source url.
	 */
	public function get_event_urls( $source ): array {
		$events  = array();
		$browser = $this->browser_factory->createBrowser();
		try {
			$page = $browser->createPage();
			$page->setUserAgent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36' );
			$page->navigate( "{$source}/events/" )->waitForNavigation();
			$page->waitUntilContainsElement( new XPathSelector( '//script[contains(text(), "actions_renderer")]' ) );
			$elements = $page->dom()->search( '//script[contains(text(), "actions_renderer")]' );
			foreach ( $elements as $element ) {
				$data        = json_decode( $element->getText() );
				$data_events = $data->require[0][3][0]->__bbox->require[9][3][1]->__bbox->result->data->node->all_collections->nodes[0]->style_renderer->collection->pageItems->edges ?? array();
				foreach ( $data_events as $data_event ) {
					$date = $data_event->node->actions_renderer->event->start_timestamp;
					if ( $date > time() && $data_event->node->node->url ) {
						$events[] = $data_event->node->node->url;
					}
				}
			}
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		} finally {
			$browser->close();
		}
		return $events;
	}

	/**
	 * Get event data from event url.
	 *
	 * @param string $url The source url.
	 */
	public function get_event_data( string $url = '' ): bool|array {
		$browser = $this->browser_factory->createBrowser();
		try {
			$page = $browser->createPage();
			$page->setUserAgent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36' );
			$page->navigate( $url )->waitForNavigation();
			$title    = $page->dom()->search( "//head/meta[contains(@property, 'og:title')]" )[0]->getAttribute( 'content' );
			$elements = $page->dom()->search( '//script[contains(text(), "event_description")]' );
			foreach ( $elements as $element ) {
				$data            = json_decode( $element->getText() );
				$data_candidates = $data->require[0][3][0]->__bbox->require;
				foreach ( $data_candidates as $data_candidate ) {
					if ( isset( $data_candidate[3][1]->__bbox->result->data->event ) ) {
						$data_event     = $data_candidate[3][1]->__bbox->result->data->event;
						$description    = $data_event->event_description->text;
						$adress         = $data_event->one_line_address ?? '';
						$adress_array   = explode( ',', $adress );
						$street_address = $adress_array[0] ?? '';
						$post_address   = $adress_array[1] ?? '';
						$country        = $adress_array[2] ?? '';
						$organiser      = $data_event->event_creator->name;
					}
				}
			}
			$elements = $page->dom()->search( '//script[contains(text(), "end_timestamp")]' );
			foreach ( $elements as $element ) {
				$data = json_decode( $element->getText() );
				if ( isset( $data->require[0][3][0]->__bbox->require[1][3][1]->__bbox->result->data->start_timestamp ) ) {
					$start_timestamp = $data->require[0][3][0]->__bbox->require[1][3][1]->__bbox->result->data->start_timestamp;
				}
				if ( isset( $data->require[0][3][0]->__bbox->require[1][3][1]->__bbox->result->data->end_timestamp ) ) {
					$end_timestamp = $data->require[0][3][0]->__bbox->require[1][3][1]->__bbox->result->data->end_timestamp;
				}
			}
		} catch ( Exception $e ) {
			return array( 'error' => $e->getMessage() );
		} finally {
			$browser->close();
		}
		return array(
			'title'           => $title ?? null,
			'description'     => $description ?? null,
			'organiser'       => $organiser ?? null,
			'street_address'  => $street_address ?? null,
			'post_address'    => $post_address ?? null,
			'country'         => $country ?? null,
			'start_timestamp' => $start_timestamp ?? null,
			'end_timestamp'   => $end_timestamp ?? null,
			'url'             => $url,
		);
	}
}
