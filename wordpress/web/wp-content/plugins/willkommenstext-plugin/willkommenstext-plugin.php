<?php
/*
Plugin Name: Willkommenstext Plugin
Description: Ein Plugin, das im Wordpress Dashboard einen Willkommenstext ausspielt
Version: 1.0
Author: Levi
*/

function willkommenstext_dashboard_widget() {
    echo '<div class="dashboard-widget">Willkommen im Wordpress Dashboard!</div>';
}

add_action('wp_dashboard_setup', 'willkommenstext_dashboard_widget');
