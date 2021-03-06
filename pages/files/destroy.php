<?php

/**
 * @return array
 */
function content_files_destroy()
{
    // required library
    activate_library('zfs');

    // query filesystem
    $fs = @$_GET[ 'destroy' ];
    if ($fs == '') {
        redirect_url('files.php');
    }

    // call function
    $fs_all = zfs_filesystem_list($fs, '-r -t all');
    $fs_vol = zfs_filesystem_list($fs, '-r -t volume');
    $fs_snap = zfs_filesystem_list($fs, '-r -t snapshot');

    // table listing children datasets
    $table_children = [];
    foreach ( $fs_all as $data ) {
        // determine dataset type
        $type = 'filesystem';
        if (@isset($fs_vol[ $data[ 'name' ] ]) ) {
            $type = 'volume';
        } elseif (@isset($fs_snap[ $data[ 'name' ] ]) ) {
            $type = 'snapshot';
        }
        // add row to table
        $table_children[] = @[
        'CHILD_NAME' => $data[ 'name' ],
        'CHILD_USED' => $data[ 'used' ],
        'CHILD_AVAIL' => $data[ 'avail' ],
        'CHILD_REFER' => $data[ 'refer' ],
        'CHILD_MOUNTPOINT' => $data[ 'mountpoint' ],
        'CHILD_TYPE' => $type
        ];
    }

    // scan for SWAP volumes
    $class_swap = 'hidden';
    foreach ( $fs_vol as $fsvol => $fsdata ) {
        $prop = zfs_filesystem_properties($fsvol, 'org.freebsd:swap');
        if (@$prop[ $fsvol ][ 'org.freebsd:swap' ][ 'value' ] === 'on' ) {
            $class_swap = 'normal';
        }
    }

    // call functions
    return [
    'PAGE_TITLE' => 'Destroy filesystems',
    'TABLE_CHILDREN' => $table_children,
    'CLASS_SWAP' => $class_swap,
    'FSNAME' => htmlentities($fs)
    ];
}

function submit_recursive_destroy_fs() 
{
    // required libraries
    activate_library('samba');
    activate_library('super');
    activate_library('zfs');

    // redirect url
    $url = 'files.php';

    // filesystem to destroy
    $fs = @$_POST[ 'fs_destroy' ];

    // get list of filesystems
    $fslist = zfs_filesystem_list($fs, '-r -t filesystem');
    if ($fslist == false ) {
        error('cannot destroy a ZFS filesystem that does not exist!');
    }

    // remove any samba filesystem
    $sharesremoved = 0;
    foreach ( $fslist as $fsname => $fsdata ) {
        if (strlen(@$fsdata[ 'mountpoint' ]) > 1 ) {
            $sharesremoved += ( int )samba_removesharepath($fsdata[ 'mountpoint' ]);
        }
    }

    // display message if applicable
    if ($sharesremoved > 1 ) {
        page_feedback(
            'removed <b>' .$sharesremoved. ' Samba shares</b> that were'
            . ' attached to the filesystems you are about to destroy'
        );
    } elseif ($sharesremoved == 1 ) {
        page_feedback(
            'removed <b>one Samba share</b> that was attached to one of '
            . 'the filesystems you are about to destroy'
        );
    }

    // start command array
    $command = [];

    // scan for swap volumes and deactivate them prior to destroying them
    $vollist = zfs_filesystem_list($fs, '-r -t volume');
    exec('/sbin/swapctl -l', $swapctl_raw);
    $swapctl = @implode(chr(10), $swapctl_raw);
    if (@is_array($vollist) ) {
        foreach ( $vollist as $volname => $voldata ) {
            $prop = zfs_filesystem_properties($volname, 'org.freebsd:swap');
            // check if volume is in use as a SWAP device
            if ((@$prop[$volname]['org.freebsd:swap']['value'] === 'on') && @strpos(
                    $swapctl,
                    '/dev/zvol/'.$volname
                ) !== false) {
                    $command[] = '/sbin/swapoff /dev/zvol/' . $volname;
                }
        }
    }
    // display message if swap volumes detected
    if (count($command) == 1 ) {
        page_feedback(
            'if you continue, one SWAP volume will be deactivated'
        );
    }
    if (count($command) > 1 ) {
        page_feedback(
            'if you continue, ' . count($command) . ' SWAP volumes will be '
            . 'deactivated'
        );
    }

    // defer to dangerous command function
    $command[] = '/sbin/zfs destroy -R ' . $fs;
    dangerouscommand($command, $url);
}
