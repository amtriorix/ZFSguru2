#!/usr/local/bin/php

<?php

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 *
 * ZFSguru - benchmark script
 * version 3
 * (C) 2010-2014, zfsguru.com
 * http://zfsguru.com
 *
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

// default variables
$chartdefault = [
    'test_seq' => true,
    'test_rio' => false,
    'testsize_gib' => 100,
    'testrounds' => 3,
    'cooldown' => 2,
    'seq_blocksize' => 1024 * 1024,
    'rio_requests' => 5000,
    'rio_scalezvol' => false,
    'rio_alignment' => 4096,
    'rio_queuedepth' => 32,
    'sectorsize_override' => 0,
    'secure_erase' => false
];
$poolname = 'gurubenchmarkpool';
$zvolname = 'guruzvoltest';

// internal variables
$benchmark = [];
$datfilename = trim(shell_exec('realpath .')) . '/benchmarks/startbenchmark.dat';
$expected_magic_string = 'XX00XXBENCHMARKXX00XX';
$dd_preg = '/^[0-9]+ bytes transferred in [0-9\.]+ secs '
    . '\(([0-9]+) bytes\/sec\)$/m';
$rt_preg = '/^Requests per second\: ([0-9]+)$/m';
$ld_preg_arr = [
    'KMEM' => '/^[\s]*vm\.kmem_size\="?([^"]*)"?$/m',
    'KMAX' => '/^[\s]*vm\.kmem_size_max\="?([^"]*)"?$/m',
    'AMIN' => '/^[\s]*vfs\.zfs\.arc_min\="?([^"]*)"?$/m',
    'AMAX' => '/^[\s]*vfs\.zfs\.arc_max\="?([^"]*)"?$/m',
    'XBIO' => '/^[\s]*vfs\.zfs\.cache_flush_disable\="?([^"]*)"?$/m',
    'XZIL' => '/^[\s]*vfs\.zfs\.zil_disable\="?([^"]*)"?$/m',
    'PFD' => '/^[\s]*vfs\.zfs\.prefetch_disable\="?([^"]*)"?$/m',
    'XWL' => '/^[\s]*vfs\.zfs\.txg\.write_limit_override\="?([^"]*)"?$/m',
    'XST' => '/^[\s]*vfs\.zfs\.txg\.synctime\="?([^"]*)"?$/m',
    'XTO' => '/^[\s]*vfs\.zfs\.txg\.timeout\="?([^"]*)"?$/m',
    'VMIN' => '/^[\s]*vfs\.zfs\.vdev\.min_pending\="?([^"]*)"?$/m',
    'VMAX' => '/^[\s]*vfs\.zfs\.vdev\.max_pending\="?([^"]*)"?$/m',
    'VCB' => '/^[\s]*vfs\.zfs\.vdev\.cache\.bshift\="?([^"]*)"?$/m',
    'VCS' => '/^[\s]*vfs\.zfs\.vdev\.cache\.size\="?([^"]*)"?$/m',
    'VCM' => '/^[\s]*vfs\.zfs\.vdev\.cache\.max\="?([^"]*)"?$/m'
];

// fetch disks from argv
$disks = [];
if (count($argv) > 1 ) {
    for ($i = 1, $iMax = count($argv); $i <= $iMax; $i++ ) {
        if (@strlen($argv[ $i ]) > 0 ) {
            $disks[] = $argv[ $i ];
        }
    }
}
@reset($disks);

// determine manual or web-gui test and set variables accordingly
if ($argv[ 1 ] === 'startbenchmark' ) {
    // start benchmark initiated via web-interface

    // read benchmark information file
    $benchmark_dat = file_get_contents($datfilename);
    $data = unserialize($benchmark_dat);
    if ($data[ 'magic_string' ] != $expected_magic_string ) {
        zfsguru_benchmark_error('benchmark_dat; magic string');
    }

    // remove benchmark information file
    exec('/bin/rm -f ' . $datfilename . ' 2>&1');

    // set variables
    $disks = $data[ 'disks' ];
    $data[ 'gigabyte' ] = ( double )$data[ 'testsize_gib' ];
    $data[ 'gigabyte_nf' ] = number_format($data[ 'gigabyte' ], 3, '.', '');
    $data[ 'diskcount' ] = @count($data[ 'disks' ]);
    $data[ 'test_seq' ] = ( @$data[ 'tests' ][ 'sequential' ] ) ? true : false;
    $data[ 'test_rio' ] = ( @$data[ 'tests' ][ 'randomio' ] ) ? true : false;
    // use sector override function if applicable (this creates .nop providers)
    if (@$data[ 'sectorsize_override' ] > 0 ) {
        $disks = zfsguru_benchmark_attach_gnop($disks, $data[ 'sectorsize_override' ]);
    }
} else {
    // start manual benchmark from command line
    // note: this is not supported and untested at the moment
    // syntax: ./benchmark.php <disk1> <disk2> <disk3> <disk4> ...

    // get data from argv (command line parameters)
    $disks = [];
    if (count($argv) > 1 ) {
        for ($i = 1, $iMax = count($argv); $i <= $iMax; $i++ ) {
            if (@strlen($argv[ $i ]) > 0 ) {
                $disks[] = $argv[ $i ];
            }
        }
    }
    @reset($disks);
    // use default values for $data array
    $data = $chartdefault;
    // and finally set diskcount
    $data[ 'diskcount' ] = @count($disks);
}


// functions

function zfsguru_benchmark_welcome()
{
    global $data, $disks;
    echo( 'ZFSGURU-benchmark, version 1' . chr(10) );
    echo( 'Test size: ' . $data[ 'gigabyte_nf' ] . ' gigabytes (GiB)' . chr(10) );
    echo( 'Test rounds: ' . $data[ 'testrounds' ] . chr(10) );
    echo( 'Cooldown period: ' . $data[ 'cooldown' ] . ' seconds' . chr(10) );
    if (@$data[ 'sectorsize_override' ] > 0 ) {
        echo( 'Sector size override: ' . @$data[ 'sectorsize_override' ] . ' bytes' . chr(10) );
    } else {
        echo( 'Sector size override: default (no override)' . chr(10) );
    }
    echo( 'Number of disks: ' . ( int )$data[ 'diskcount' ] . ' disks' . chr(10) );
    foreach ( $disks as $id => $disk ) {
        echo( 'disk ' . ( ( int )$id + 1 ) . ': ' . $disk . chr(10) );
    }
    if (@$data[ 'sysinfo' ][ 'contamination' ] != false ) {
        echo( 'WARNING: loader.conf settings are contaminated! Please reboot first!' );
    }
    echo( chr(10) . chr(10) );
}

function zfsguru_benchmark_init()
{
    global $data, $poolname, $chartdefault, $testnames, $ld_preg_arr;

    // assign names to each test
    $qd = ( int )@$data[ 'rio_queuedepth' ];
    $testnames = [
    'seqread' => 'Sequential Read',
    'seqwrite' => 'Sequential Write',
    'raidtest.read' => 'Random Read (qd=' . $qd . ')',
    'raidtest.write' => 'Random Write (qd=' . $qd . ')',
    'raidtest.mixed' => 'Random Read+Write (qd=' . $qd . ')'
    ];

    // craft testsettings string
    $data[ 'testsettings_str' ] = '';
    $ts_arr = [
    'testsize_gib' => 'TS',
    'testrounds' => 'TR',
    'cooldown' => 'CD',
    'seq_blocksize' => 'BS',
    'rio_requests' => 'RQ',
    'rio_scalezvol' => 'SC',
    'rio_alignment' => 'AL',
    'rio_queuedepth' => 'QD',
    'sectorsize_override' => 'SECT',
    'secure_erase' => 'SE'
    ];
    foreach ( $ts_arr as $varname => $tag ) {
        if (@$data[ $varname ] != $chartdefault[ $varname ] ) {
            if (@strlen($data[ $varname ]) > 0 ) {
                $data[ 'testsettings_str' ] .= $tag . @$data[ $varname ] . '; ';
            } else {
                $data[ 'testsettings_str' ] .= $tag . ( int )@$data[ $varname ] . '; ';
            }
        }
    }
    // craft tuning string
    if (@$data[ 'sysinfo' ][ 'contamination' ] != false ) {
        $data[ 'tuning_str' ] = 'NEEDREBOOT ';
    } else {
        $data[ 'tuning_str' ] = '';
    }
    $loaderconf = @file_get_contents('/boot/loader.conf');
    foreach ( $ld_preg_arr as $tag => $ld_preg ) {
        if (preg_match($ld_preg, $loaderconf, $matches) ) {
            $data[ 'tuning_str' ] .= $tag . '=' . @$matches[ 1 ] . '; ';
        }
    }
    if ($data[ 'tuning_str' ] == '' ) {
        $data[ 'tuning_str' ] = 'none';
    }
    // announce setings and tuning
    echo( '* Test Settings: ' . $data[ 'testsettings_str' ] . chr(10) );
    echo( '* Tuning: ' . $data[ 'tuning_str' ] . chr(10) );
    // kill background processes which might interfere
    echo( '* Stopping background processes: sendmail, moused, syslogd and cron'
    . chr(10) );
    exec('/usr/bin/killall sendmail moused syslogd cron 2>&1');
    echo( '* Stopping Samba service' . chr(10) );
    exec('/usr/local/etc/rc.d/samba_server onestop > /dev/null 2>&1');
    // destroy existing benchmark pool (supress output)
    exec('/sbin/zpool import -f ' . $poolname . ' 2>&1');
    sleep(1);
    exec('/sbin/zpool destroy ' . $poolname . ' 2>&1');
    echo( chr(10) );
}

/**
 * @param     $tag
 * @param int $rv
 */
function zfsguru_benchmark_error( $tag, $rv = -1 )
{
    global $poolname;
    echo( chr(10) );
    echo( '* ERROR during "' . $tag . '"; got return value ' . $rv );
    echo( chr(10) );
    // destroy test pool before quiting; or we have problems creating it next time
    exec('/sbin/zpool destroy ' . $poolname);
    usleep(1000);
    exit($rv);
}

/**
 * @param $disks
 * @param $sectorsize
 *
 * @return array|false
 */
function zfsguru_benchmark_attach_gnop( $disks, $sectorsize )
{
    if (!is_array($disks) ) {
        return false;
    }

    // now create geom_nop providers on each disk
    foreach ( $disks as $disk ) {
        // destroy existing .nop device
        exec('/sbin/gnop destroy ' . $disk . '.nop 2>&1');
        // create new .nop device
        exec(
            '/sbin/gnop create -S ' . ( int )$sectorsize . ' ' . $disk . ' 2>&1', $output,
            $rv 
        );
        if ($rv != 0 ) {
            zfsguru_benchmark_error('geom_nop on ' . $disk, $rv);
        }
    }

    // now return new array with .nop suffix attached
    $nopdisks = [];
    foreach ( $disks as $disk ) {
        $nopdisks[] = $disk . '.nop';
    }
    return $nopdisks;
}

function zfsguru_benchmark_sync()
{
    exec('/bin/sync');
    exec('/bin/sync');
}

function zfsguru_benchmark_secure_erase()
{
    global $data;
    echo( 'Secure Erase. ' );
    if (is_array(@$data[ 'disks' ]) ) {
        foreach ( $data[ 'disks' ] as $disk ) {
            exec('/sbin/newfs -E -b 65536 /dev/' . $disk, $output, $rv);
            if ($rv != 0 ) {
                zfsguru_benchmark_error('secure_erase', $rv);
            }
        }
    }
}

/**
 * @param $raid
 * @param $vdev
 */
function zfsguru_benchmark_createpool( $raid, $vdev )
{
    global $poolname;
    echo( 'c' );
    $options = '-f -O atime=off ';
    // check for nested pools and use raw $vdev as string
    if (( $raid === 'RAID1+0' )||( $raid === 'RAIDZ+0' )||( $raid === 'RAIDZ2+0' ) ) {
        $command = '/sbin/zpool create ' . $options . $poolname . ' ' . $vdev;
    } else {
        $command = '/sbin/zpool create ' . $options . $poolname . ' ' . $raid . ' ' .
        implode(' ', $vdev);
    }
    exec($command, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('zpool create', $rv);
    }
    sleep(1);
}

function zfsguru_benchmark_destroypool()
{
    global $poolname;
    echo( 'd' );
    // sleep before destroying pool to prevent "device in use" errors
    sleep(2);
    // destroy pool
    exec('/sbin/zpool destroy -f ' . $poolname, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('zpool destroy', $rv);
    }
    sleep(1);
}

function zfsguru_benchmark_remountpool()
{
    global $poolname;
    echo( 'm' );
    // sleep before unmounting to prevent "device in use" errors
    sleep(2);
    // unmount
    exec('/sbin/zfs unmount -f ' . $poolname, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('zfs unmount', $rv);
    }
    // small sleep
    sleep(1);
    // mount again
    exec('/sbin/zfs mount ' . $poolname, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('zfs mount', $rv);
    }
}

/**
 * @return array|false
 */
function zfsguru_benchmark_sequentialio()
{
    global $data;
    if (!@$data[ 'test_seq' ] ) {
        return false;
    }
    // start with sequential write so we will be reading "real" data next test
    $write = zfsguru_benchmark_sequentialwrite();
    // sequential read
    $read = zfsguru_benchmark_sequentialread();
    return compact('read', 'write');
}

/**
 * @return mixed
 */
function zfsguru_benchmark_sequentialread()
{
    global $data, $poolname, $dd_preg;
    // remount pool to clear cache
    zfsguru_benchmark_remountpool();
    echo( 'R' );
    // cooldown before starting test
    sleep(( int )$data[ 'cooldown' ]);
    // read zero.000 file from pool in 1MiB blocks
    $command = '/bin/dd if=/' . $poolname . '/zero.000 of=/dev/null '
    . 'bs=' . ( int )$data[ 'seq_blocksize' ] . ' 2>&1';
    exec($command, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('dd read', $rv);
    }
    // remove test file zero.000
    exec('/bin/rm /' . $poolname . '/zero.000', $output2, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('rm zero.000', $rv);
    }
    // process score
    preg_match($dd_preg, implode(chr(10), $output), $readmatch);
    return $readmatch[ 1 ];
}

/**
 * @return mixed
 */
function zfsguru_benchmark_sequentialwrite()
{
    global $data, $poolname, $dd_preg;
    echo( 'W' );
    // cooldown before starting test
    sleep(( int )$data[ 'cooldown' ]);
    $count = ( $data[ 'gigabyte' ] * 1024 * 1024 ) /
    ( ( int )$data[ 'seq_blocksize' ] / 1024 );
    // write zero.000 file from pool in 1MiB blocks
    $command = '/bin/dd if=/dev/zero of=/' . $poolname . '/zero.000 '
    . 'bs=' . ( int )$data[ 'seq_blocksize' ] . ' count=' . ( int )$count . ' 2>&1';
    exec($command, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('dd write', $rv);
    }
    // sync
    zfsguru_benchmark_sync();
    // process score
    preg_match($dd_preg, implode(chr(10), $output), $writematch);
    return $writematch[ 1 ];
}

/**
 * @return array|false
 */
function zfsguru_benchmark_randomio()
{
    global $data, $poolname, $zvolname, $rt_preg;
    $zvoldev = '/dev/zvol/' . $poolname . '/' . $zvolname;
    if (!@$data[ 'test_rio' ] ) {
        return false;
    }
    echo( 'z' );

    // check for scale zvol option
    if (@$data[ 'rio_scalezvol' ] === true ) {
        $zvolsize = $data[ 'gigabyte' ] * 1024 * 1024 * 1024 * ( int )$data[ 'datadisks' ];
    } else {
        $zvolsize = ( double )$data[ 'gigabyte' ] * 1024 * 1024 * 1024;
    }

    // zvol size
    $zvolsize_nf = number_format($zvolsize, 3);

    // create zvol
    exec(
        '/sbin/zfs create -V ' . $zvolsize . ' ' . $poolname . '/' . $zvolname,
        $output, $rv 
    );
    if ($rv != 0 ) {
        zfsguru_benchmark_error('zfs create zvol (' . $zvolsize . ')', $rv);
    }
    sleep(1);

    // acquire disk information
    $zvol_sectorsize = ( int )
    shell_exec("diskinfo \$zvoldev | awk '{print \$2}'");
    $zvol_mediasize = ( int )
    shell_exec("diskinfo \$zvoldev | awk '{print \$3}'");

    // write random/zero data to zvol
    // $source = '/dev/urandom';
    $source = '/dev/zero';
    $bs = 1024 * 1024;
    $count = ( int )( ( $zvol_sectorsize * $zvol_mediasize ) / $bs );
    $command = '/bin/dd if=' . $source . ' of=' . $zvoldev . ' bs=' . $bs . ' count=' . $count
    . ' 2>&1';
    exec($command);
    sleep(1);


    // remount pool
    zfsguru_benchmark_remountpool();
    echo( 'I' );

    // test variables
    $zvoldev = '/dev/zvol/' . $poolname . '/' . $zvolname;
    $raidtest = [
    'benchmarks/raidtest.read',
    'benchmarks/raidtest.write',
    'benchmarks/raidtest.mixed'
    ];

    // create the raidtest files
    foreach ( $raidtest as $rt_file ) {
        // remove existing file first (leftover from interrupted run)
        @exec('/bin/rm -f ' . $rt_file . ' 2>&1');
        // generate raidtest profile file
        if (strpos($rt_file, 'read') ) {
            $mode = '-r';
        } elseif (strpos($rt_file, 'write') ) {
            $mode = '-w';
        }
        $command = '/usr/local/bin/raidtest genfile ' . $mode . ' -s ' . $zvol_mediasize
        . ' -S ' . $zvol_sectorsize . ' -n ' . ( int )$data[ 'rio_requests' ] . ' ' . $rt_file;
        exec($command, $output, $rv);
        if ($rv != 0 ) {
            zfsguru_benchmark_error('raidtest genfile', $rv);
        }
    }

    // start test
    $rioscores = [];
    foreach ( $raidtest as $rtfile ) {
        // cooldown each test
        sleep(( int )$data[ 'cooldown' ]);
        // string name used in score array $rioscores
        $rioname = ( strpos($rtfile, '/') === false ) ? $rtfile :
        substr($rtfile, strrpos($rtfile, '/') + 1);
        $command = '/usr/local/bin/raidtest';
        $command .= ' test -d ' . $zvoldev . ' -n ' . ( int )$data[ 'rio_queuedepth' ] . ' ';
        $command .= $rtfile . ' 2>&1';
        unset($output);
        exec($command, $output, $rv);
        if ($rv != 0 ) {
            zfsguru_benchmark_error('raidtest test', $rv);
        }
        // sync
        zfsguru_benchmark_sync();
        // process scores
        preg_match($rt_preg, implode(chr(10), $output), $matches);
        $rioscores[ $rioname ] = ( int )@$matches[ 1 ];
    }

    // remove the raidtest files
    foreach ( $raidtest as $rt_file ) {
        exec('/bin/rm ' . $rt_file);
    }

    // destroy zvol
    exec('/sbin/zfs destroy ' . $poolname . '/' . $zvolname, $output, $rv);
    if ($rv != 0 ) {
        zfsguru_benchmark_error('zfs destroy zvol', $rv);
    }

    // return scores
    return $rioscores;
}

/**
 * @param $raid
 * @param $vdev
 * @param $testdisks
 * @param $datadisks
 *
 * @return array|false
 */
function zfsguru_benchmark_start($raid, $vdev, $testdisks, $datadisks)
{
    global $data, $benchmark, $poolname;

    // skip if we want to test more disks than possible
    if ($testdisks > $data[ 'diskcount' ] ) {
        return false;
    }

    // secure erase if applicable
    if ($data[ 'secure_erase' ] === true ) {
        zfsguru_benchmark_secure_erase();
    }

    // $raidtxt (used for chart colortag as well)
    $raidtxt = ( $raid == '' ) ? 'RAID0' : $raid;
    $raidtxt = ( $raid === 'mirror' ) ? 'RAID1' : $raidtxt;
    $raidtxt = ( $raid === 'raidz1' ) ? 'RAIDZ' : $raidtxt;
    $raidtxt = ( $raid === 'raidz2' ) ? 'RAIDZ2' : $raidtxt;

    // inject data in $data array for easy sharing across functions
    $data[ 'raidtxt' ] = $raidtxt;
    $data[ 'testdisks' ] = $testdisks;
    $data[ 'datadisks' ] = $datadisks;

    // announce test
    echo( 'Now testing ' . $raidtxt . ' configuration with ' . $testdisks . ' disks: ' );

    $score = [];
    for ( $round = 1; $round <= ( int )$data[ 'testrounds' ]; $round++ ) {
        // create pool
        zfsguru_benchmark_createpool($raid, $vdev);

        // sequential I/O (both read and write)
        $score[ 'sequential' ][ $round ] = zfsguru_benchmark_sequentialio();

        // random I/O tests
        $score[ 'random' ][ $round ] = zfsguru_benchmark_randomio();

        // destroy pool
        zfsguru_benchmark_destroypool();

        // signal test round done
        echo( '@' );
    }
    echo( chr(10) );

    // update $benchmark array which holds all (average) scores; used for chart
    zfsguru_benchmark_averagescores($score);

    // report scores to console output
    zfsguru_benchmark_report($score);

    // create a new benchmark chart. by doing this for every test, we allow 
    // the user to view results of the benchmark results gathered thus far.
    zfsguru_benchmark_createchart();

    return $score;
}

/**
 * @param $size_bytes
 *
 * @return string
 */
function humansize_binary( $size_bytes )
{
    if ($size_bytes < ( 1024 * 10 )) {
        return $size_bytes . ' B';
    }

    if ($size_bytes < ( 1024 * 1024 * 10 )) {
        return ( int )( $size_bytes / 1024 ) . ' KiB';
    }

    if ($size_bytes < ( 1024 * 1024 * 1024 * 10 )) {
        return ( int )( $size_bytes / ( 1024 * 1024 ) ) . ' MiB';
    }

    if ($size_bytes < ( 1024 * 1024 * 1024 * 1024 * 10 )) {
        return ( int )( $size_bytes / ( 1024 * 1024 * 1024 ) ) . ' GiB';
    }

    return ( int )( $size_bytes / ( 1024 * 1024 * 1024 * 1024 ) ) . ' TiB';
}

/**
 * @param $scores
 *
 * @return array
 */
function zfsguru_benchmark_averagescores( $scores )
{
    global $data, $benchmark;
    $avg = [];
    // calculate sequential I/O average scores
    if (@$data[ 'test_seq' ] ) {
        $totalread = 0;
        $totalwrite = 0;
        for ( $round = 1; $round <= ( int )$data[ 'testrounds' ]; $round++ ) {
            $totalread += $scores[ 'sequential' ][ $round ][ 'read' ];
            $totalwrite += $scores[ 'sequential' ][ $round ][ 'write' ];
        }
        $avg[ 'seqread' ] = ( int )( ( $totalread / ( int )$data[ 'testrounds' ] ) /
        ( 1024 * 1024 ) );
        $avg[ 'seqwrite' ] = ( int )( ( $totalwrite / ( int )$data[ 'testrounds' ] ) /
        ( 1024 * 1024 ) );
    }
    // calculate random I/O average scores
    if (@$data[ 'test_rio' ] ) {
        foreach ( $scores[ 'random' ][ 1 ] as $raidtest => $score ) {
            $totalscore = 0;
            for ( $round = 1; $round <= ( int )$data[ 'testrounds' ]; $round++ ) {
                $totalscore += $scores[ 'random' ][ $round ][ $raidtest ];
            }
            $avg[ $raidtest ] = ( int )( $totalscore / ( int )$data[ 'testrounds' ] );
        }
    }
    // update $benchmark array with $avg scores
    foreach ( $avg as $test => $score ) {
        $benchmark[ $test ][ $data[ 'raidtxt' ] ][ ( int )$data[ 'testdisks' ] ] = $score;
    }
    // return average scores (used for report and chart)
    return $avg;
}

/**
 * @param $scores
 */
function zfsguru_benchmark_report( $scores )
{
    global $data, $benchmark;
    $benchcopy = $benchmark;

    // sequential i/o
    if (@$data[ 'test_seq' ] ) {
        // sequential read
        echo( 'READ:	' );
        for ( $round = 1; $round <= ( int )$data[ 'testrounds' ]; $round++ ) {
            echo( humansize_binary($scores[ 'sequential' ][ $round ][ 'read' ]) . '/sec	' );
        }
        $avgread = @array_pop($benchcopy[ 'seqread' ][ $data[ 'raidtxt' ] ]);
        echo( '= ' . $avgread . ' MiB/sec avg' . chr(10) );
        // sequential write
        echo( 'WRITE:	' );
        for ( $round = 1; $round <= ( int )$data[ 'testrounds' ]; $round++ ) {
            echo( humansize_binary($scores[ 'sequential' ][ $round ][ 'write' ]) . '/sec	' );
        }
        $avgwrite = @array_pop($benchcopy[ 'seqwrite' ][ $data[ 'raidtxt' ] ]);
        echo( '= ' . $avgwrite . ' MiB/sec avg' . chr(10) );
    }

    // random i/o
    if (@$data[ 'test_rio' ] ) {
        foreach ( $scores[ 'random' ][ 1 ] as $raidtest => $iops ) {
            echo( $raidtest . ':	' );
            foreach ( $scores[ 'random' ] as $round => $randomscores ) {
                echo( ( int )$randomscores[ $raidtest ] . '	' );
            }
            $avgiops = array_pop($benchcopy[ $raidtest ][ $data[ 'raidtxt' ] ]);
            echo( '= ' . $avgiops . ' IOps ' );
            $throughput = $avgiops * ( ( 131072 + ( int )$data[ 'rio_alignment' ] ) / 2 );
            echo( '( ~' . humansize_binary(( int )$throughput) . '/sec )' . chr(10) );
        }
    }

    // TODO: report time taken
    echo( chr(10) );
}

/**
 * @param bool $benchmarkrunning
 */
function zfsguru_benchmark_createchart( $benchmarkrunning = true )
{
    global $data, $benchmark, $chartdefault, $testnames;

    // include chart.php library
    include_once '/includes/chart.php';
    if (!function_exists('zfsguru_createbenchmarkchart') ) {
        zfsguru_benchmark_error('function zfsguru_createbenchmarkchart');
    }

    foreach ( $benchmark as $test => $benchdata ) {
        // add benchmark array to data array
        $data[ 'benchmark' ] = $benchdata;
        // add watermark if benchmark is still running
        $data[ 'benchmarkrunning' ] = $benchmarkrunning;
        // call external function
        $image = zfsguru_createbenchmarkchart($testnames[ $test ], $data);
        // skip if we did not get a resource
        if (!is_resource($image) ) {
            echo( '* image creation failed (test: ' . $test . ')' . chr(10) );
            continue;
        }
        // set correct filename depending on whether benchmark is still running
        if ($benchmarkrunning ) {
            $filename = trim(shell_exec('realpath .')) . '/benchmarks/running_' . $test . '.png';
        } else {
            // final benchmark score
            $filename = trim(shell_exec('realpath .')) . '/benchmarks/bench_' . $test . '.png';
            // also remove temporary benchmarks; we don't need those anymore
            exec('/bin/rm benchmarks/running_* 2>&1');
        }
        // write png file to disk
        imagepng($image, $filename);
        @imagedestroy($image);
    }
}

/**
 * @param $diskcount
 */
function zfsguru_benchmark_processnested( $diskcount )
{
    global $benchmark, $testnames;
    // RAID1+0
    $chunk_m = 2;
    if ($diskcount >= ( $chunk_m * 2 ) ) {
        foreach ( $testnames as $tag => $testname ) {
            if (@isset($benchmark[ $tag ][ 'RAID1' ][ $chunk_m ]) ) {
                $benchmark[ $tag ][ 'RAID1+0' ][ $chunk_m ] =
                $benchmark[ $tag ][ 'RAID1' ][ $chunk_m ];
            }
        }
    }

    // RAIDZ+0
    $chunk_z = 4;
    if ($diskcount >= ( $chunk_z * 2 ) ) {
        foreach ( $testnames as $tag => $testname ) {
            if (@isset($benchmark[ $tag ][ 'RAIDZ' ][ $chunk_z ]) ) {
                $benchmark[ $tag ][ 'RAIDZ+0' ][ $chunk_z ] =
                $benchmark[ $tag ][ 'RAIDZ' ][ $chunk_z ];
            }
        }
    }

    // RAIDZ2+0
    $chunk_z2 = 6;
    if ($diskcount >= ( $chunk_z2 * 2 ) ) {
        foreach ( $testnames as $tag => $testname ) {
            if (@isset($benchmark[ $tag ][ 'RAIDZ2' ][ $chunk_z2 ]) ) {
                $benchmark[ $tag ][ 'RAIDZ2+0' ][ $chunk_z2 ] =
                $benchmark[ $tag ][ 'RAIDZ2' ][ $chunk_z2 ];
            }
        }
    }
}

function zfsguru_benchmark_main()
{
    global $data, $disks, $testnames, $benchmark;
    $dc = ( int )$data[ 'diskcount' ];
    // split tests in sections: upper and lower; the lower part is done last
    $sections = [16 => 0, 12 => 15, 8 => 11, 4 => 7, 1 => 3];

    // start benchmark spree!
    foreach ( $sections as $start_disks => $end_disks ) {
        // check if we have enough disks for this section, or skip this section
        if ($start_disks > $dc ) {
            continue;
        }

        // update nested scores
        zfsguru_benchmark_processnested($dc);

        // determine maximum disks we are going to test this round
        if ($end_disks < 1 ) {
            $maxdisks = $dc;
        } else {
            $maxdisks = min($dc, $end_disks);
        }

        // RAID0 / stripe
        for ( $i = $start_disks; $i <= $maxdisks; $i++ ) {
            zfsguru_benchmark_start(
                '',
                array_slice($disks, 0, $i),
                $i,
                $i
            );
        }

        // RAID5 / RAIDZ1
        if ($dc >= 2 ) {
            for ( $i = max(2, $start_disks); $i <= $maxdisks; $i++ ) {
                zfsguru_benchmark_start(
                    'raidz1',
                    array_slice($disks, 0, $i),
                    $i,
                    $i - 1
                );
                zfsguru_benchmark_processnested($dc);
            }
        }

        // RAID6 / RAIDZ2
        if ($dc >= 3 ) {
            for ( $i = max(3, $start_disks); $i <= $maxdisks; $i++ ) {
                zfsguru_benchmark_start(
                    'raidz2',
                    array_slice($disks, 0, $i),
                    $i,
                    $i - 2
                );
                zfsguru_benchmark_processnested($dc);
            }
        }

        // RAID1 / mirror
        if ($dc >= 2 ) {
            for ( $i = max(2, $start_disks); $i <= $maxdisks; $i++ ) {
                zfsguru_benchmark_start(
                    'mirror',
                    array_slice($disks, 0, $i),
                    $i,
                    $i - ($i / 2)
                );
                zfsguru_benchmark_processnested($dc);
            }
        }

        // RAID1+0 / mirror+stripe / nested mirror
        $chunk_m = 2;
        if ($dc >= ( $chunk_m * 2 ) ) {
            for ( $i = max(( $chunk_m * 2 ), $start_disks); $i <= $maxdisks; $i += $chunk_m ) {
                // inject existing testscores in new nested score
                zfsguru_benchmark_processnested($dc);
                $mirrordev = '';
                // todo: this needs to be updated for $chunk_m != 2
                for ( $y = 1; $y <= $i / 2; $y++ ) {
                    $mirrordev .= 'mirror ' . $disks[ $y * 2 - 2 ] . ' ' . $disks[ $y * 2 - 1 ] . ' ';
                }
                zfsguru_benchmark_start(
                    'RAID1+0',
                    $mirrordev,
                    $i,
                    $i - ($i / $chunk_m)
                );
            }
        }

        // RAID5+0 / raidz+striping / nested raidz
        $chunk_z = 4;
        if (( int )$data[ 'diskcount' ] >= ( $chunk_z * 2 ) ) {
            for ( $i = ( $chunk_z * 2 ); $i <= ( int )$data[ 'diskcount' ]; $i += $chunk_z ) {
                // inject existing testscores in new nested score
                zfsguru_benchmark_processnested($dc);
                $zeedev = '';
                // todo: needs updating for $chunk_z
                for ( $y = 1; $y <= $i / 4; $y++ ) {
                    $zeedev .= 'raidz ' . $disks[ $y * 4 - 4 ] . ' ' . $disks[ $y * 4 - 3 ] . ' '
                    . $disks[ $y * 4 - 2 ] . ' ' . $disks[ $y * 4 - 1 ] . ' ';
                }
                zfsguru_benchmark_start(
                    'RAIDZ+0',
                    $zeedev,
                    $i,
                    $i - ($i / $chunk_z)
                );
            }
        }

        // RAID6+0 / raidz2+striping / nested raidz2
        $chunk_z2 = 6;
        if (( int )$data[ 'diskcount' ] >= ( $chunk_z2 * 2 ) ) {
            for ( $i = ( $chunk_z2 * 2 ); $i <= ( int )$data[ 'diskcount' ]; $i += $chunk_z2 ) {
                // inject existing testscores in new nested score
                zfsguru_benchmark_processnested($dc);
                $zeedev = '';
                // needs updating for $chunk_z2
                for ( $y = 1; $y <= $i / 6; $y++ ) {
                    $zeedev .= 'raidz2 ' . $disks[ $y * 6 - 6 ] . ' ' . $disks[ $y * 6 - 5 ] . ' '
                    . $disks[ $y * 6 - 4 ] . ' ' . $disks[ $y * 6 - 3 ] . ' ' . $disks[ $y * 6 - 2 ] . ' '
                    . $disks[ $y * 6 - 1 ] . ' ';
                }
                zfsguru_benchmark_start(
                    'RAIDZ2+0',
                    $zeedev,
                    $i,
                    $i - ($i / $chunk_z2)
                );
            }
        }

    }
}

// START

// welcome output
zfsguru_benchmark_welcome();

// initialize variables and stuff
zfsguru_benchmark_init();

// main benchmark function (all tests are started from here)
zfsguru_benchmark_main();

// create final benchmark chart
zfsguru_benchmark_createchart(false);

// END
echo 'Done';
