<?php
/**
 * CLI TUI for fetching events from Facebook and storing them as markdown files.
 * Specially made for use with gnisto.se hugo.
 *
 * @package tui-fetch.
 */

declare( strict_types = 1 );

use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Bridge\PhpTerm\PhpTermBackend;
use PhpTui\Tui\Display\Display;
use PhpTui\Tui\DisplayBuilder;
use PhpTui\Tui\Extension\Core\Widget\GaugeWidget;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Extension\Core\Widget\BlockWidget;
use PhpTui\Tui\Extension\Core\Widget\Block\Padding;
use PhpTui\Tui\Extension\Core\Widget\GridWidget;
use PhpTui\Tui\Extension\Core\Widget\ParagraphWidget;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarOrientation;
use PhpTui\Tui\Extension\Core\Widget\Scrollbar\ScrollbarState;
use PhpTui\Tui\Extension\Core\Widget\ScrollbarWidget;
use PhpTui\Tui\Extension\Core\Widget\CompositeWidget;
use PhpTui\Tui\Layout\Constraint;
use PhpTui\Tui\Text\Text;
use PhpTui\Tui\Text\Title;
use PhpTui\Tui\Widget\Borders;
use PhpTui\Tui\Widget\BorderType;
use PhpTui\Tui\Widget\Direction;
use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\KeyModifiers;
use PhpTui\Term\Terminal;

require 'vendor/autoload.php';
require 'inc/facebook-fetch.php';

/**
 * A class to handle Facebook scraping.
 */
class TuiFetch {
	/**
	 * The log messages.
	 *
	 * @var string $log
	 */
	private string $log = '';

	/**
	 * The updated sum.
	 *
	 * @var inte $updated.
	 */
	private int $updated = 0;

	/**
	 * The new sum.
	 *
	 * @var inte $duplicated.
	 */
	private int $duplicated = 0;

	/**
	 * The new sum.
	 *
	 * @var inte $new.
	 */
	private int $new = 0;

	/**
	 * The directories.
	 *
	 * @var array $directories
	 */
	private array $directories = array( 'new', 'archive', 'updated' );

	/**
	 * The sources array.
	 *
	 * @var array $sources
	 */
	private array $sources = array();

	/**
	 * The Display class.
	 *
	 * @var Display $display
	 */
	private Display $display;

	/**
	 * The Terminal class.
	 *
	 * @var Terminal $terminal
	 */
	private Terminal $terminal;

	/**
	 * Class constructor.
	 *
	 * @param string $source_file The source file.
	 */
	public function __construct( string $source_file ) {
		// Create directories.
		foreach ( $this->directories as $directory ) {
			if ( ! is_dir( "./{$directory}" ) ) {
				mkdir( "./{$directory}" );
			}
		}

		// Initiate TUI Display.
		$this->terminal = Terminal::new();
		$this->display  = DisplayBuilder::default( PhpTermBackend::new( $this->terminal ) )->build();

		// Archive old events.
		self::archive_events();

		// Read sources.
		$handle = fopen( $source_file, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$this->sources[] = trim( $line );
			}
			fclose( $handle );
		}
	}

	/**
	 * The do fetch function.
	 */
	public function do_fetch() {
		$this->terminal->execute( Actions::cursorHide() );
		// switch to the "alternate" screen so that we can return the user where they left off.
		$this->terminal->execute( Actions::alternateScreenEnable() );
		$this->terminal->execute( Actions::enableMouseCapture() );
		$this->terminal->enableRawMode();

		$facebook_fetch = new FacebookFetch();
		$events         = array();
		foreach ( $this->sources as $index => $source ) {
			$events_result = $facebook_fetch->get_event_urls( $source );
			if ( false !== $events_result ) {
				$events     = array_merge( $events, $events_result );
				$this->log .= "<fg=white>Loading source {$source}</>\n";
			} else {
				$this->log .= "<fg=red>Error loading source {$source} ⚠</>\n";
			}
			self::print_hub( $index, count( $this->sources ), 'Loading sources' );
		}

		foreach ( $events as $index => $event ) {
			$event_data  = $facebook_fetch->get_event_data( $event );
			if ( false !== $event_data ) {
				$file_result = self::save_event( $event_data );
				switch ( $file_result ) {
					case 0:
						$this->log .= "<fg=green>Saved new event {$event_data['title']}</>\n";
						++$this->new;
						break;
					case 1:
						$this->log .= "<fg=yellow>Updating event {$event_data['title']}</>\n";
						++$this->updated;
						break;
					case 2:
						$this->log .= "<fg=red>Duplicate event {$event_data['title']}</>\n";
						++$this->duplicated;
						break;
				}
				self::print_hub( $index, count( $events ), 'Loading events' );
			} else {
				$this->log .= "<fg=red>Error loading event.</>\n";
			}
		}

		$this->log .= "<fg=green>Done fetching events, press ESC to exit. 🥳</>\n";
		self::print_hub( 1, 1, 'None' );

		while ( true ) {
			$event = $this->terminal->events()->next();
			if ( $event instanceof CodedKeyEvent ) {
				if ( KeyCode::Esc === $event->code ) {
					break;
				}
			}
			// sleep for Xms - note that it's encouraged to implement apps
			// using an async library such as Amp or React.
			usleep( 50_000 );
		}
		$this->terminal->disableRawMode();
		$this->terminal->execute( Actions::cursorShow() );
		$this->terminal->execute( Actions::alternateScreenDisable() );
		$this->terminal->execute( Actions::disableMouseCapture() );
		exit;
	}

	/**
	 * Save event as md file.
	 *
	 * @param array $event The event data.
	 */
	private function save_event( array $event ): int {
		$file_name      = self::get_filename( $event );
		$title          = $event['title'];
		$description    = $event['description'] ?? '';
		$street_address = $event['street_address'] ?? '';
		$post_address   = $event['post_address'] ?? '';
		$country        = $event['country'] ?? '';
		$organizer      = $event['organizer'] ?? '';
		$url            = $event['url'] ?? '';
		$start_date     = date( 'Y-m-d H:i:s', $event['start_timestamp'] );
		$end_time       = $event['end_timestamp'] ? date( 'Y-m-d H:i:s', $event['end_timestamp'] ) : '';

		$markdown  = '';
		$markdown .= "---\n";
		$markdown .= "title: \"{$title}\"\n";
		$markdown .= "date: \"{$start_date}\"\n";
		if ( ! empty( $end_time ) ) {
			$markdown .= "end_date: \"{$end_time}\"\n";
		}
		$markdown .= "locations: []\n";
		$markdown .= "forms: []\n";
		$markdown .= "topics: []\n";
		$markdown .= "organizer: \"{$organizer}\"\n";
		$markdown .= "addressName: \"\"\n";
		$markdown .= "streetAddress: \"{$street_address}\"\n";
		$markdown .= "postalCode: \"{$post_address}\"\n";
		$markdown .= "addressRegion: \"\"\n";
		$markdown .= "addressCountry: \"$country\"\n";
		$markdown .= "source: \"{$url}\"\n";
		$markdown .= "---\n";
		$markdown .= $description;

		try {
			if ( file_exists( "./archive/{$file_name}" ) ) {
				$existing_file = fopen( "./archive/{$file_name}", 'r' );
				$existing_markdown = fread( $existing_file, filesize( "./archive/{$file_name}" ));
				if ( $existing_markdown !== $markdown ) {
					unlink( "./archive/{$file_name}" );
					$updated_file = fopen( "./updated/{$file_name}", 'w' );
					fwrite( $updated_file, $markdown );
					fclose( $updated_file );
					return 1;
				}
				return 2;
			}
			$new_file = fopen( "./new/{$file_name}", 'w' );
			if ( $new_file ) {
				fwrite( $new_file, $markdown );
				fclose( $new_file );
			} else {
				$this->log .= "<fg=red>Error writing file {$file_name} ⚠</>\n";
			}
		} catch ( Exception $e ) {
			$this->log .= "<fg=red>Error reading or writing file {$file_name} ⚠</>\n";
		}
		return 0;
	}

	/**
	 * Archive all old files.
	 */
	public function archive_events() {
		$new_dir     = array_diff( scandir( './new' ), array( '..', '.' ) );
		$updated_dir = array_diff( scandir( './updated' ), array( '..', '.' ) );
		$total       = count( $new_dir ) + count( $updated_dir );
		$index       = 0;
		foreach ( $new_dir as $value ) {
			rename( "./new/{$value}", "./archive/{$value}" );
			$this->log .= "<fg=yellow>Archiving file {$value}</>\n";
			self::print_hub( $index, $total, 'Archive old files from new' );
			usleep( 100000 );
			++$index;
		}
		foreach ( $updated_dir as $value ) {
			rename( "./updated/{$value}", "./archive/{$value}" );
			$this->log .= "<fg=yellow>Archiving file {$value}</>\n";
			self::print_hub( $index, $total, 'Archive old files' );
			usleep( 100000 );
			++$index;
		}
	}

	/**
	 * Print the hub.
	 *
	 * @param int    $index          The current progress index.
	 * @param int    $total          The current progress total.
	 * @param string $label_progress The current progress label.
	 */
	private function print_hub( $index, $total, $label_progress ) {
		$log_array = explode( "\n", $this->log );
		$log       = implode( "\n", array_reverse( $log_array ) );
		$this->display->draw(
			GridWidget::default()
				->direction( Direction::Vertical )
				->constraints(
					Constraint::max( 5 ),
					Constraint::min( 10 ),
					Constraint::max( 5 ),
				)
				->widgets(
					BlockWidget::default()
						->borders( Borders::ALL )
						->padding( Padding::all( 1 ) )
						->titles( Title::fromString( $label_progress ) )
						->borderType( BorderType::Rounded )
						->widget(
							GaugeWidget::default()->ratio( $index / $total )->style( Style::default()->fg( AnsiColor::Red ) )
						),
					BlockWidget::default()
						->padding( Padding::all( 1 ) )
						->borders( Borders::ALL )
						->titles( Title::fromString( 'Log' ) )
						->borderType( BorderType::Rounded )
						->widget(
							CompositeWidget::fromWidgets(
								ParagraphWidget::fromText(
									Text::parse( $log )
								),
							),
						),
					BlockWidget::default()
						->padding( Padding::all( 1 ) )
						->borders( Borders::ALL )
						->titles( Title::fromString( 'Result' ) )
						->borderType( BorderType::Rounded )
						->widget(
							GridWidget::default()
								->direction( Direction::Horizontal )
									->constraints(
										Constraint::percentage( 33 ),
										Constraint::percentage( 33 ),
										Constraint::percentage( 33 ),
									)
								->widgets(
									ParagraphWidget::fromText(
										Text::parse( "<fg=green>New: {$this->new}</>" )
									),
									ParagraphWidget::fromText(
										Text::parse( " <fg=yellow>Updated: {$this->updated}</>" )
									),
									ParagraphWidget::fromText(
										Text::parse( "<fg=red>Duplicated: {$this->duplicated}</>" )
									),
								),
						),
				),
		);
	}

	/**
	 * Returns the filename for an event.
	 *
	 * @param array $event The event array.
	 */
	private function get_filename( array $event ): string {
		$file_name = null;
		if ( $event['title'] && $event['start_timestamp'] ) {
			$file_name = self::filename_sanitizer( $event['title'] );
			$file_time = gmdate( 'Y-m-d', $event['start_timestamp'] );
			$file_name = "{$file_time}-{$file_name}.md";
		}
		$file_name = iconv( 'utf-8', 'CP1256//IGNORE', $file_name );
		return $file_name;
	}

	/**
	 * Sanitize filenames.
	 *
	 * @param string $unsafe_filename An unsafe filename.
	 */
	private function filename_sanitizer( string $unsafe_filename ): string {
		$unsafe_filename      = strtolower( $unsafe_filename );
		$unsafe_filename      = strtr( $unsafe_filename, 'äåö', 'aao' );
		$dangerous_characters = array( ' ', '"', '\'', '&', '/', '\\', '?', ':', '!', '-', '#' );
		$safe_filename        = str_replace( $dangerous_characters, ' ', $unsafe_filename );
		$safe_filename        = preg_replace( '/\s+/', ' ', $safe_filename );
		$safe_filename        = str_replace( ' ', '-', $safe_filename );
		$safe_filename        = str_replace( '(', '', $safe_filename );
		$safe_filename        = str_replace( ')', '', $safe_filename );
		$safe_filename        = str_replace( '–', '-', $safe_filename );
		return $safe_filename;
	}
}

if ( ! file_exists( $argv[1] ) ) {
	print( "File {$argv[1]} cant be found." );
	exit();
}

$tui_fetch = new TuiFetch( $argv[1] );
$tui_fetch->do_fetch();
