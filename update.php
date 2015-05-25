<?php
// designed to be run as php update.php
// based on https://github.com/markjaquith/WordPress-Plugin-Directory-Slurper
// and the fork by Rarst
$args = $argv;
$cmd  = array_shift( $args );
$type = 'all';
$at_a_time = 500; // how many files to download at a time

if ( ! empty( $args[0] ) )
	$type = $args[0];

switch ( $type ) {

	case 'readme':
		$directory = 'readmes';
		$download  = 'readmes/%s.txt';
		$globreg   = 'readmes/*.txt';
		$url       = 'http://plugins.svn.wordpress.org/%s/trunk/readme.txt';
		break;

	case 'all':
		$directory = 'plugins';
		$download  = 'zips/%s.zip';
		$globreg   = 'zips/*.zip';
		$url       = 'http://downloads.wordpress.org/plugin/%s.latest-stable.zip?nostats=1';
		break;

	default:
		echo $cmd . ": invalid command\r\n";
		echo 'Usage: php ' . $cmd . " [command]\r\n\r\n";
		echo "Available commands:\r\n";
		echo "  all - Downloads full plugin zips\r\n";
		echo "  readme - Downloads plugin readmes only\r\n";
		die();
}

echo "Determining most recent SVN revision...\r\n";

try {

	$changelog = @file_get_contents( 'http://plugins.trac.wordpress.org/log/?format=changelog&stop_rev=HEAD' );

	if ( ! $changelog )
		throw new Exception( 'Could not fetch the SVN changelog' );

	preg_match( '#\[([0-9]+)\]#', $changelog, $matches );

	if ( ! $matches[1] )
		throw new Exception( 'Could not determine most recent revision.' );

} catch ( Exception $e ) {

	die( $e->getMessage() . "\r\n" );
}

$svn_last_revision = (int) $matches[1];

echo 'Most recent SVN revision: ' . $svn_last_revision . "\r\n";

if ( file_exists( $directory . '/.last-revision' ) ) {

	$last_revision = (int) file_get_contents( $directory . '/.last-revision' );
	echo 'Last synced revision: ' . $last_revision . "\r\n";
}
else {

	$last_revision = false;
	echo "You have not yet performed a successful sync. Settle in. This will take a while.\r\n";
}

$start_time = time();

if ( $last_revision != $svn_last_revision ) {

	if ( $last_revision ) {
		$changelog_url = sprintf( 'http://plugins.trac.wordpress.org/log/?verbose=on&mode=follow_copy&format=changelog&rev=%d&limit=%d', $svn_last_revision, $svn_last_revision - $last_revision );
		$changes       = file_get_contents( $changelog_url );
		preg_match_all( '#^' . "\t" . '*\* ([^/A-Z ]+)[ /].* \((added|modified|deleted|moved|copied)\)' . "\n" . '#m', $changes, $matches );
		$plugins = array_unique( $matches[1] );
	}
	else {

		$plugins = file_get_contents( 'http://svn.wp-plugins.org/' );
		preg_match_all( '#<li><a href="([^/]+)/">([^/]+)/</a></li>#', $plugins, $matches );
		$plugins = $matches[1];
	}
	
	if ( empty( $plugins ) ){
		echo "WordPress.org is down";
	}
	
	$all_plugins = $plugins;
	$cap = count( $plugins );
	$plugins_parts = array_chunk($plugins, $at_a_time );

	// download all of them
	$e = $at_a_time;
	$estimate = true;
	$howmany = (int) count( $plugins ) / $at_a_time;
	echo "Part 1 of 3 (downloading plugins): Begin \r\n";
	foreach( $plugins_parts as $n => $part ){
		$time_start = microtime(true);
		
		// Number of threads you want
		$threads = 22;

		// Treads storage
		$ts = array();

		// Group Urls
		$plugingroup = array_chunk($part, floor(count($part) / $threads));
		
		$i = 0;
		
		foreach ( $plugingroup as $group ) {
			$ts[] = new Downloader($group, $i, $download, $url, $type );
			$i++;
		}

		// wait for all Threads to complete
		foreach ( $ts as $t ) {
			$t->join();
		}
		$time_end = microtime(true);
		$execution_time = (($time_end - $time_start)/60) * ( $howmany - ( $n+1 ) );
		echo 'Download time remaining: '.$execution_time." minutes \r\n";
			
		echo "Downloaded " . $e . " of $cap plugins \r\n";
		$e = $e + $at_a_time;
	}
	echo "Part 1 of 3 (downloading plugins): End \r\n";
	
	
	
	// delete all empty zip files
	echo "Part 2 of 3 (sizecheck): Begin \r\n";
	$found = glob( $globreg);
	$glob_parts = array_chunk($found, $at_a_time );	
	foreach( $glob_parts as $n => $part ){
		$time_start = microtime(true);
		// Number of threads you want
		$threads = 250;

		// Treads storage
		$ts = array();

		// Group Urls
		$plugingroup = array_chunk($part, floor(count($part) / $threads));

		$i = 0;
		
		foreach ( $plugingroup as $group ) {
			$ts[] = new SizeCheck($group, $i, $download, $type );
			$i++;
		}

		// wait for all Threads to complete
		foreach ( $ts as $t ) {
			$t->join();
		}
		$num = ( $n + 1 ) * 500;
		$time_end = microtime(true);
		$execution_time = (($time_end - $time_start)/60) * ( $howmany - ( $n+1 ) );
		echo 'Sizecheck time remaining: '.$execution_time." minutes \r\n";
		echo "Checked " . $num . " of $cap plugins \r\n";
	}
	echo "Part 2 of 3 (sizecheck): End \r\n";
	
	if ( $type != 'readme' ){
		// unzip remaining ones
		echo "Part 3 of 3 (unzipping): Begin \r\n";
		foreach( $glob_parts as $n => $part ){
			$time_start = microtime(true);
			// Number of threads you want
			$threads = 250;

			// Treads storage
			$ts = array();

			// Group Urls
			$plugingroup = array_chunk($part, $at_a_time );
			
		
			$i = 0;
			
			foreach ( $plugingroup as $group ) {
				$ts[] = new UnzipperThreaded($group, $i, $download, $type );
				$i++;
			}

			// wait for all Threads to complete
			foreach ( $ts as $t ) {
				$t->join();
			}
			$num = ( $n + 1 ) * 500;
			$time_end = microtime(true);
			$execution_time = (($time_end - $time_start)/60) * ( $howmany - ( $n+1 ) );
			echo 'Unzipping time remaining: '.$execution_time." minutes \r\n";
			echo "Unzipped " . $num . " of $cap plugins \r\n";
		}
		echo "Part 3 of 3 (unzipping): End \r\n";
	}

	if ( file_put_contents( $directory . '/.last-revision', $svn_last_revision ) )
		echo "[CLEANUP] Updated $directory/.last-revision to " . $svn_last_revision . "\r\n";
	else
		echo "[ERROR] Could not update $directory/.last-revision to " . $svn_last_revision . "\r\n";
}

$end_time = time();
$minutes  = ( $end_time - $start_time ) / 60;
$seconds  = ( $end_time - $start_time ) % 60;

echo "[SUCCESS] Done updating plugins!\r\n";
echo 'It took ' . number_format( $minutes ) . ' minute' . ( $minutes == 1 ? '' : 's' ) . ' and ' . $seconds . ' second' . ( $seconds == 1 ? '' : 's' ) . ' to update ' . count( $plugins ) . ' plugin' . ( count( $plugins ) == 1 ? '' : 's' ) . "\r\n";
echo "[DONE]\r\n";

/**
 * Delete file/directory recursively. Based on WP_Filesystem_Direct->delete() because GPL.
 *
 * @param string $file
 *
 * @return bool
 */
function delete( $file ) {

	if ( empty( $file ) ) //Some filesystems report this as /, which can cause non-expected recursive deletion of all files in the filesystem.
		return false;

	$file = str_replace( '\\', '/', $file ); //for win32, occasional problems deleting files otherwise

	if ( is_file( $file ) )
		return unlink( $file );

	//At this point its a folder, and we're in recursive mode
	$filelist = glob( $file . '/*', GLOB_MARK );
	$retval   = true;

	foreach ( $filelist as $filename ) {

		if ( ! delete( $filename ) )
			$retval = false;
	}

	if ( file_exists( $file ) && ! rmdir( $file ) )
		$retval = false;

	return $retval;
}

class Downloader extends Thread {
	
	public $urls;
	public $name;
	public $download;
	public $url;
	public $type;
	

    public function __construct( $urls = array(), $name, $download, $url, $type ) {
        $this->urls = $urls;
        $this->name = $name;
		$this->download = $download;
		$this->url = $url;
		$this->type = $type;
        $this->start();
    }

    public function run() {
        if ($this->urls) {  
			$plugins = $this->urls;
			 // array of curl handles
			  $curly = array();
			  // data to be returned
			  $result = array();
			 
			  // multi handle
			  $mh = curl_multi_init();
			  $id = 0;
			  $file = array();
			foreach( $plugins as $plugin_i ){
				$plugin = urldecode( $plugin_i );
				$curly[$id] = curl_init();
				$path = sprintf( $this->download, $plugin );
				$file[$id] = fopen( $path, 'w' );
				curl_setopt( $curly[$id], CURLOPT_URL, sprintf( $this->url, $plugin ));
				curl_setopt( $curly[$id], CURLOPT_FILE, $file[$id] );
				curl_multi_add_handle($mh, $curly[$id]);
				$id++;
			}
			
			// execute the handles
			  $running = null;
			  do {
				curl_multi_exec($mh, $running);
			  } while($running > 0);

			  // get content and remove handles
			  foreach($curly as $idt => $c) {
					$next = $plugins[$idt];
					curl_multi_remove_handle($mh, $c);
					fclose( $file[$idt] );
				}

			  // all done
			  curl_multi_close($mh);
			}
    }
}

class UnzipperThreaded extends Thread {
	
	public $urls;
	public $name;
	public $download;
	public $url;
	public $type;
	

    public function __construct( $urls = array(), $name, $download, $url, $type ) {
        $this->urls = $urls;
        $this->name = $name;
		$this->download = $download;
		$this->url = $url;
		$this->type = $type;
        $this->start();
    }

    public function run() {
        if ($this->urls) {  
			$plugins = $this->urls;

			  // get content and remove handles
			  foreach($plugins as $i => $file ) {
				$path = sprintf( $this->download, $file );
				
					if ( 'all' === $this->type ) {

						if ( file_exists( 'plugins/' . $file ) ){
							delete( 'plugins/' . $file );
						}

						if ( true === $zip->open( $path ) ) {
							$zip->extractTo( 'plugins' );
							$zip->close();
						} else {
							echo 'Updating ' . $file . ' failed in extract';
							echo "\r\n";
						}
						unlink( $path );
					}
				
			  }
			}
    }
}


class SizeCheck extends Thread {
	public $urls;
	public $name;
	public $download;
	public $url;
	public $type;

    public function __construct( $files = array(), $name, $download, $type ) {
        $this->urls = $urls;
        $this->name = $name;
		$this->download = $download;
		$this->type = $type;
        $this->start();
    }

    public function run() {
        if ($this->urls) {  
			$plugins = $this->urls;
			foreach( $plugins as $plugin_i ){
				$path = sprintf( $this->download, $plugin_i );
				if ( file_exists( $path ) && filesize ( $path ) == 0 ){
					unlink( $path );
				}
			}
		}
    }
}
