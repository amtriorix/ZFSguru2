<?php

/**
 * @return array
 */
function content_layout_layout()
{
    global $guru, $tabs, $tags;

    // timer
    timerstart('layout', 'page');

    // bulletins
    $bulletins = ( is_int(@$guru[ 'preferences' ][ 'bulletin_unread' ]) ) ?
    $guru[ 'preferences' ][ 'bulletin_unread' ] : '?';

    // menu (hide when welcome manager is active)
    if (@isset($guru[ 'preferences' ]) ) {
        $menus = [
        'Status' => 'status.php',
        'Network' => 'network.php',
        'Disks' => 'disks.php',
        'Pools' => 'pools.php',
        'Files' => 'files.php',
        'Access' => 'access.php',
        'Services' => 'services.php',
        'System' => 'system.php'
        ];
    } else {
        $menus = [];
    }

    $menu = '';
    $uri = $_SERVER[ 'SCRIPT_NAME' ];
    $reluri = substr($uri, strrpos($uri, '/'));
    foreach ( $menus as $title => $url ) {
        if (substr($reluri, 1, strlen($url)) == $url ) {
            $menu .= '<a class="menu-active" href="' . $url . '">' . $title . '</a>';
        } else {
            $menu .= '<a href="' . $url . '">' . $title . '</a>';
        }
    }

    // navtabs
    $navtabs = '';
    if (@is_array($tabs) ) {
        foreach ( $tabs as $tabname => $taburl ) {
            $navtabs .= '<div></div>';
            if (( '/' . $taburl == $_SERVER[ 'REQUEST_URI' ] )OR( @$tags[ 'PAGE_ACTIVETAB' ] == $tabname ) ) {
                $navtabs .= '<a class="navtab navtab-active" href="' . $taburl . '">'
                . $tabname . '</a>';
            } else {
                $navtabs .= '<a class="navtab" href="' . $taburl . '">' . $tabname . '</a>';
            }
            $navtabs .= '<div></div>';
        }
    }

    // process feedback boxes
    $feedback = '';
    if (@is_array($_SESSION[ 'feedback' ]) ) {
        foreach ( $_SESSION[ 'feedback' ] as $style => $items ) {
            $title = ucfirst(substr($style, 2));
            foreach ( $items as $item ) {
                $feedback .= '<div class="feedback_' . $style . '" title="' . $title . '">'
                . $item . '</div>' . chr(10);
            }
        }
    }
    unset($_SESSION[ 'feedback' ]);

    // tabbar
    // displays clickable buttons and returns active page based on $_GET variable
    $tabbar = '';
    if (@is_array($tags[ 'PAGE_TABBAR' ]) ) {
        $tabbar = '<p class="tabbar">';
        reset($tags[ 'PAGE_TABBAR' ]);
        $activetabbar = key($tags[ 'PAGE_TABBAR' ]);
        $tabbar_url = @$tags[ 'PAGE_TABBAR_URL' ];
        foreach ( $tags[ 'PAGE_TABBAR' ] as $tag => $name ) {
            if (@isset($_GET[ $tag ]) ) {
                $activetabbar = $tag;
            } elseif (@isset($_GET[ str_replace('.', '_', $tag) ]) ) {
                $activetabbar = $tag;
            }
        }
        foreach ( $tags[ 'PAGE_TABBAR' ] as $tag => $name ) {
            if ($tag == $activetabbar ) {
                $tabbar .= '<a class="activebar" href="' . $tabbar_url . '&' . $tag . '">'
                . htmlentities($name) . '</a> ';
            } else {
                $tabbar .= '<a href="' . $tabbar_url . '&' . $tag . '">' . htmlentities($name) . '</a> ';
            }
        }
        $tabbar .= '</p>';
    }

    // timer statistics
    timerend('layout');
    timerend('page');
    $timekeeper = timekeeper();

    // return as tags
    return [
    'LAYOUT_BULLETINS' => $bulletins,
    'LAYOUT_MENU' => $menu,
    'LAYOUT_NAVTABS' => $navtabs,
    'LAYOUT_FEEDBACK' => $feedback,
    'LAYOUT_TABBAR' => $tabbar,
    'LAYOUT_TIMEKEEPER' => $timekeeper,
    ];
}
