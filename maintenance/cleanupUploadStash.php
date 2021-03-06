<?php
/**
 * Remove old or broken uploads from temporary uploaded file storage,
 * clean up associated database records
 *
 * Copyright © 2011, Wikimedia Foundation
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Ian Baker <ibaker@wikimedia.org>
 * @ingroup Maintenance
 */

require_once( __DIR__ . '/Maintenance.php' );

/**
 * Maintenance script to remove old or broken uploads from temporary uploaded
 * file storage and clean up associated database records.
 *
 * @ingroup Maintenance
 */
class UploadStashCleanup extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Clean up abandoned files in temporary uploaded file stash";
	}

	public function execute() {
		global $wgUploadStashMaxAge;

		$repo = RepoGroup::singleton()->getLocalRepo();

		$dbr = $repo->getSlaveDb();

		// how far back should this look for files to delete?
		$cutoff = time() - $wgUploadStashMaxAge;

		$this->output( "Getting list of files to clean up...\n" );
		$res = $dbr->select(
			'uploadstash',
			'us_key',
			'us_timestamp < ' . $dbr->addQuotes( $dbr->timestamp( $cutoff ) ),
			__METHOD__
		);

		if( !is_object( $res ) || $res->numRows() == 0 ) {
			$this->output( "No files to cleanup!\n" );
			// nothing to do.
			return;
		}

		// finish the read before starting writes.
		$keys = array();
		foreach( $res as $row ) {
			array_push( $keys, $row->us_key );
		}

		$this->output( 'Removing ' . count( $keys ) . " file(s)...\n" );
		// this could be done some other, more direct/efficient way, but using
		// UploadStash's own methods means it's less likely to fall accidentally
		// out-of-date someday
		$stash = new UploadStash( $repo );

		$i = 0;
		foreach( $keys as $key ) {
			$i++;
			try {
				$stash->getFile( $key, true );
				$stash->removeFileNoAuth( $key );
			} catch ( UploadStashBadPathException $ex ) {
				$this->output( "Failed removing stashed upload with key: $key\n"  );
			} catch ( UploadStashZeroLengthFileException $ex ) {
				$this->output( "Failed removing stashed upload with key: $key\n"  );
			}
			if ( $i % 100 == 0 ) {
				$this->output( "$i\n" );
			}
		}
		$this->output( "$i done\n" );

		$tempRepo = $repo->getTempRepo();
		$dir      = $tempRepo->getZonePath( 'thumb' );
		$iterator = $tempRepo->getBackend()->getFileList( array( 'dir' => $dir ) );

		$this->output( "Deleting old thumbnails...\n" );
		$i = 0;
		foreach ( $iterator as $file ) {
			$i++;
			if ( wfTimestamp( TS_UNIX, $tempRepo->getFileTimestamp( "$dir/$file" ) ) < $cutoff ) {
				$tempRepo->quickPurge( "$dir/$file" );
			}
			if ( $i % 100 == 0 ) {
				$this->output( "$i\n" );
			}
		}
		$this->output( "$i done\n" );
	}
}

$maintClass = "UploadStashCleanup";
require_once( RUN_MAINTENANCE_IF_MAIN );
