<?php

/**
 * @param       $uri
 * @param false $filesize
 * @param false $sha512
 * @param false $master
 * @param false $ims
 *
 * @return false|mixed|string|null
 */
function server_download( $uri, $filesize = false, $sha512 = false,
    $master = false, $ims = false 
) {
    // download remote file from any slave server and return file path
    // return codes: string containing downloadpath/false/NULL (NULL = not modified)
    // check whether file already exists
    $dirs = common_dirs();
    $downloadpath = $dirs[ 'download' ] . '/' . basename($uri);
    if (@file_exists($downloadpath) ) {
        if (server_validate($downloadpath, $filesize) ) {
            return $downloadpath;
        }

        unlink($downloadpath);
    }
    if (@file_exists($downloadpath) ) {
        error('file ' . basename($uri) . ' already exists in the download directory!');
    }

    // check whether enough space exists to store file
    server_spacecheck($filesize, $dirs);

    // find a working slave server to download file
    $failedservers = [];
    $iterations = 0;
    $maxiterations = 10;
    $invalid = 0;
    $maxinvalid = 3;
    $serverlist = false;
    while ( ( ++$iterations <= $maxiterations )AND( $invalid <= $maxinvalid ) ) {
        $server = server_find($failedservers, $serverlist, $master);
        if (!$server ) {
            return server_download_fail($failedservers, $invalid, $uri);
        }
        $url = $server[ 'protocol' ] . '://' . $server[ 'address' ] . $uri;
        $downloadedfile = server_download_try(
            $url, $dirs[ 'download' ], $filesize, $ims, $invalidsize 
        );
        if ($downloadedfile === null) {
            return null;
        }

        if ($downloadedfile !== false) {
            if (server_validate($downloadedfile, $filesize, $sha512) ) {
                break;
            }

            // remove downloaded file that failed validation
            @unlink($downloadedfile);
            $invalid++;
        }
        if ($invalidsize ) {
            $invalid++;
        }
        $failedservers[] = $server[ 'name' ];
        if ($iterations > $maxiterations ) {
            return server_download_fail($failedservers, $invalid, $uri);
        }
    }

    // success; return filepath of downloaded file
    return $downloadedfile;
}

/**
 * @param $failedservers
 * @param $invalid
 * @param $uri
 *
 * @return false
 */
function server_download_fail( $failedservers, $invalid, $uri )
{
    $times = ( $invalid != 1 ) ? ' times' : ' time';
    if ($invalid > 0 ) {
        page_feedback(
            'downloaded file rejected ' . $invalid . $times
            . ' because the size or checksum is invalid: <b>'
            . htmlentities(basename($uri)) . '</b>', 'a_warning' 
        );
    } else {
        page_feedback(
            'tried maximum servers (' . count($failedservers)
            . ') but could not download file: <b>' . htmlentities($uri) . '</b>', 'a_warning' 
        );
    }
    return false;
}

/**
 * @param       $url
 * @param       $downloaddir
 * @param false $filesize
 * @param false $ims
 * @param false $invalidsize
 *
 * @return false|string|null
 */
function server_download_try( $url, $downloaddir, $filesize = false,
    $ims = false, & $invalidsize = false 
) {
    // tries to download file specified by URL to download directory
    global $guru;

    // target path to write downloaded file to
    $downloadpath = $downloaddir . '/' . basename($url);

    // use external fetch command to retrieve file since it has proper timeouts
    $param = [];
    // -T <sec> = timeout
    if (( int )@$guru[ 'preferences' ][ 'connect_timeout' ] > 0 ) {
        $param[ 'T' ] = '-T ' . ( int )$guru[ 'preferences' ][ 'connect_timeout' ];
    }
    // -S <bytes> = require filesize or will not download
    if (( int )$filesize > 0 ) {
        $param[ 'S' ] = '-S ' . ( int )$filesize;
    }
    // -i <file> = only download if newer than given file (mtime)
    if ($ims ) {
        $param[ 'i' ] = '-i ' . escapeshellarg($ims);
    }
    // assemble command
    $command = '/usr/bin/fetch -o ' . escapeshellarg($downloadpath)
    . ' ' . implode(' ', $param) . ' ' . escapeshellarg($url) . ' 2>&1';
    exec($command, $output, $rv);

    // only accept result if return value zero and non-empty result
    if (( $rv == 0 )AND @file_exists($downloadpath) ) {
        return $downloadpath;
    }
    if ($rv == 0 ) {
        return null;
    } /* IMS: If Modified Since = Not modified since */
    if (@file_exists($downloadpath) ) {
        unlink($downloadpath);
    }
    if (($rv == 1) && strpos($output[0], 'size mismatch') !== false) {
        $invalidsize = true;
    }
    return false;
}

/**
 * @param       $uri
 * @param false $filesize
 * @param false $sha512
 */
function server_download_bg( $uri, $filesize = false, $sha512 = false )
{
    // required library
    activate_library('background');

    // location of downloaded file
    $dirs = common_dirs();
    $filepath = $dirs[ 'download' ] . '/' . basename($uri);

    // check whether enough space exists to store file
    server_spacecheck($filesize, $dirs);

    // check background job
    $btag = md5($uri . $filesize . $sha512);
    $bquery = background_query($btag);
    if ($bquery[ 'running' ] ) {
        error('download of "' . htmlentities(basename($uri)) . '" is already running!');
    } elseif ($bquery[ 'exists' ] ) {
        background_remove($btag);
    }

    // find server and assemble URL
    $server = server_find();
    if (!@isset($server[ 'address' ]) ) {
        error('could not find a slave server to download from; this is weird!');
    }
    $url = $server[ 'protocol' ] . '://' . $server[ 'address' ] . $uri;

    // register background job
    $command = '/usr/bin/fetch -o ' . escapeshellarg($filepath) . ' '
    . escapeshellarg($url);
    background_register(
        $btag, [
        'commands' => ['fetch' => $command],
        'storage' => [
            'failedservers' => [],
        'lastserver' => $server[ 'name' ]
        ],
             ]
    );
}

/**
 * @param       $uri
 * @param false $filesize
 * @param false $sha512
 *
 * @return bool|int
 */
function server_download_bg_query( $uri, $filesize = false, $sha512 = false )
{
    // required libraries
    activate_library('background');

    // location of downloaded file
    $dirs = common_dirs();
    $filepath = $dirs[ 'download' ] . '/' . basename($uri);

    // check background job
    $btag = md5($uri . $filesize . $sha512);
    $bquery = background_query($btag);

    // if no download is know, return false
    if (!$bquery[ 'exists' ] ) {
        return false;
    }

    // if the download is stil running, return an integer as percentage
    if ($bquery[ 'running' ] ) {
        // compare size
        $expected_size = ( int )$filesize;
        $actual_size = ( int )filesize($filepath);
        $percent = floor(( $actual_size / $expected_size ) * 100);
        return ( int )$percent;
    }

    // if the download has completed, then validate it and return true or false
    if (( $bquery[ 'ctag' ][ 'fetch' ][ 'rv' ] == 0 )AND @file_exists($filepath) ) {
        background_remove($btag);
        if (server_validate($filepath, $filesize, $sha512) ) {
            page_feedback(
                'successfully downloaded: ' . htmlentities(basename($uri)),
                'b_success' 
            );
            return true;
        }

        // remove downloaded file that failed validation
        unlink($filepath);
        page_feedback(
            'downloaded file rejected: ' . htmlentities(basename($uri)),
            'a_warning'
        );

        return false;
    }

    // if none of the above, then an error occurred or the file does not exist
    background_remove($btag);

    // since the current download failed, try another server
    $failedservers = @$bquery[ 'storage' ][ 'failedservers' ];
    $lastserver = @$bquery[ 'storage' ][ 'lastserver' ];
    if (@$bquery[ 'storage' ][ 'lastserver' ] ) {
        $failedservers[] = @$bquery[ 'storage' ][ 'lastserver' ];
    }
    $server = server_find($failedservers);
    if (!@isset($server[ 'address' ]) ) {
        page_feedback(
            'tried all servers (' . count($failedservers)
            . ') but could not download file!', 'a_error' 
        );
        return false;
    }
    $url = $server[ 'protocol' ] . '://' . $server[ 'address' ] . $uri;
    $command = '/usr/bin/fetch -o ' . escapeshellarg($filepath) . ' '
    . escapeshellarg($url);
    background_register(
        $btag, [
        'commands' => ['fetch' => $command],
        'storage' => [
            'failedservers' => $failedservers,
        'lastserver' => $server[ 'name' ]
        ],
             ]
    );

    // inform user and return integer 0 (download in progress)
    page_feedback(
        'trying another download server because ' . htmlentities($lastserver)
        . ' failed to download the file: ' . htmlentities(basename($uri))
    );
    return 0;
}

/**
 * @param $type
 * @param $filename
 *
 * @return string
 */
function server_uri( $type, $filename )
{
    // required library
    activate_library('gurudb');

    // gather data
    $general = gurudb_general();
    $curver = common_systemversion();
    $platform = common_systemplatform();
    $search = ['%SYSVER%', '%PLATFORM%'];
    $replace = [$curver[ 'sysver' ], $platform];

    // determine prefix
    $prefix = @$general[ 'uri-' . $type ];
    if ($prefix) {
        $prefix = str_replace($search, $replace, $prefix);
    } else {
        page_feedback(
            'could not retrieve correct URI for download type: '
            . htmlentities($type), 'a_warning'
        );
    }

    // return URI
    return $prefix . $filename;
}

/**
 * @param array $failedservers
 * @param false $serverlist
 * @param false $master
 *
 * @return false|mixed
 */
function server_find( $failedservers = [], & $serverlist = false, $master = false )
{
    global $guru;
    if (!$serverlist ) {
        activate_library('gurudb');
        $serverlist = ( $master ) ? gurudb_master() : gurudb_slave();
    }
    // try preferred server first
    if (@empty($failedservers) ) {
        if ($master ) {
            if (@isset($serverlist[ $guru[ 'preferences' ][ 'preferred_master' ] ][ 'address' ]) ) {
                return $serverlist[ $guru[ 'preferences' ][ 'preferred_master' ] ];
            }
        } elseif (@isset($serverlist[ $guru[ 'preferences' ][ 'preferred_slave' ] ][ 'address' ]) ) {
            return $serverlist[ $guru[ 'preferences' ][ 'preferred_slave' ] ];
        }
    }
    // try other servers in list
    foreach ( $serverlist as $server ) {
        if (!in_array($server['name'], $failedservers, true)) {
            return $server;
        }
    }
    return false;
}

/**
 * @param       $filesize
 * @param false $dirs
 */
function server_spacecheck( $filesize, $dirs = false )
{
    // verify download directory
    if (!$dirs ) {
        $dirs = common_dirs();
    }
    if (!@is_dir($dirs[ 'download' ])OR!@is_writable($dirs[ 'download' ]) ) {
        error('Download directory does not exist or is not writable!');
    }

    // check free space required
    $factor = 1.0;
    $margin = 64 * 1024 * 1024;
    $spacerequired = ( $factor * ( int )$filesize ) + $margin;
    if ($spacerequired > disk_free_space($dirs[ 'download' ]) ) {
        error(
            'not enough free space on your download directory! '
            . 'If you are running from LiveCD, you have run out of RAM!' 
        );
    }
}

/**
 * @param       $file
 * @param false $filesize
 * @param false $sha512
 *
 * @return bool
 */
function server_validate( $file, $filesize = false, $sha512 = false )
{
    if (!@file_exists($file) ) {
        return false;
    }
    if ((( int )$filesize > 0) && $filesize !== false) {
        if (filesize($file) == 0) {
            return false;
        }

        if (filesize($file) != $filesize) {
            return false;
        }
    }
    if (@strlen($sha512) > 0 ) {
        if (function_exists('hash')AND false ) {
            if (hash('sha512', file_get_contents($file)) != $sha512 ) {
                return false;
            }
        } elseif (trim(shell_exec('/sbin/sha512 -q ' . escapeshellarg($file))) != $sha512 ) {
            return false;
        }
    }
    return true;
}
